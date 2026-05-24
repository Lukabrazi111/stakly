<?php

namespace App\Jobs;

use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use App\Services\Provider\LichessGameClient;
use App\Services\Provider\LichessGameResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts an auto-fetched Lichess game card for a Pending match, then
 * immediately settles via `SettleFromCardAction` (M16 — API is the only
 * outcome source, no player Won/Lost/Drawn confirms).
 *
 * Pipeline:
 *   1. Search Lichess for games between the two snapshotted usernames
 *      since `match.created_at` via `LichessGameClient::searchGamesBetween()`.
 *   2. Filter to completed games — decisive (mate/resign/outoftime) OR draw
 *      (draw/stalemate). Aborted / half-played games are excluded.
 *   3. If exactly ONE candidate exists, post a system message with the
 *      verified card, then dispatch `SettleFromCardAction` to settle the
 *      match. Zero or multiple candidates → silent skip; wrong-game
 *      evidence is worse than no evidence.
 *
 * Triggered by M16 Phase 2 surfaces (page-visit on /matches/{id},
 * chat-send during Pending, cron at 5-min cadence) and M16 Phase 4
 * (Lichess admin OAuth stream consumer). All of those layer for redundancy
 * — the job is idempotent (`alreadyPosted()` short-circuits re-runs).
 *
 * Decisions encoded:
 *   - Snapshot-cross-checked, not live-looked-up. A mid-match unlink can't
 *     strip the anchor.
 *   - One post per match. Idempotency via an attachments_json scan keeps
 *     re-dispatch / queue-retry from double-posting + double-settling.
 *   - Card-then-settle order: the card lands in chat first so players
 *     read the game record above the settlement narration.
 */
class AutoFetchLichessGameJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public GameMatch $match,
    ) {}

    /**
     * One in-flight job per match — M16 Phase 2 trigger sites (page-visit,
     * chat-send, cron, stream) all funnel through `DispatchAutoFetchAction`
     * and any of them might fire while a previous job is still running.
     * The unique lock dedupes those races so we don't spam the provider API.
     * Lock releases on job success / failure / final-retry-exhausted.
     */
    public function uniqueId(): string
    {
        return (string) $this->match->id;
    }

    public function handle(
        LichessGameClient $client,
        PostSystemMessageAction $postSystem,
        SettleFromCardAction $settleFromCard,
    ): void {
        if ($this->alreadyPosted()) {
            return;
        }

        $this->match->loadMissing('providerSnapshots');

        $creatorUsername = $this->match->snapshotUsername(
            GameMatch::SIDE_CREATOR,
            LinkedAccountProvider::Lichess,
        );
        $takerUsername = $this->match->snapshotUsername(
            GameMatch::SIDE_TAKER,
            LinkedAccountProvider::Lichess,
        );

        if ($creatorUsername === null || $takerUsername === null) {
            // Caller gates on both snapshots being present, but defensive
            // belt-and-suspenders in case the job is re-dispatched out of
            // its normal context.
            return;
        }

        $games = $this->searchSafely($client, $creatorUsername, $takerUsername);

        if ($games === null) {
            return;
        }

        $completed = $this->filterCompleted($games);

        if (count($completed) !== 1) {
            // Zero or multiple — silent skip. Wrong-game evidence is worse
            // than no evidence; manual paste covers the ambiguous case.
            return;
        }

        $card = $this->postCard($postSystem, $completed[0]);

        // M16 — card IS the settlement trigger. SettleFromCardAction
        // row-locks the match, no-ops if not Pending (idempotent re-runs),
        // branches on winner_color to SettleMatchAction (winner) or
        // SettleDrawMatchAction (draw).
        $settleFromCard->handle($this->match, $card);
    }

    /**
     * @return list<LichessGameResult>|null null on provider failure (logged
     *                                      + swallowed) so the caller knows
     *                                      to abort cleanly.
     */
    private function searchSafely(
        LichessGameClient $client,
        string $creatorUsername,
        string $takerUsername,
    ): ?array {
        try {
            return $client->searchGamesBetween(
                $creatorUsername,
                $takerUsername,
                $this->match->created_at,
            );
        } catch (ProviderUnavailableException $e) {
            Log::info('Auto-fetch search failed (provider unavailable)', [
                'match_id' => $this->match->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('Auto-fetch search failed (unexpected)', [
                'match_id' => $this->match->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Games we'll auto-settle from: decisive (clear winner) OR draw
     * (agreed/stalemate/etc.). Aborted / half-played games skipped —
     * not a real result to settle against.
     *
     * @param  list<LichessGameResult>  $games
     * @return list<LichessGameResult>
     */
    private function filterCompleted(array $games): array
    {
        return array_values(array_filter(
            $games,
            fn (LichessGameResult $g) => $g->isDecisive() || $g->isDraw(),
        ));
    }

    /**
     * @return array<string, mixed> the card payload posted to chat — passed
     *                              to SettleFromCardAction so the settle
     *                              decision uses the same data the players see.
     */
    private function postCard(PostSystemMessageAction $postSystem, LichessGameResult $game): array
    {
        $card = $this->buildEntry($game);

        // Text is intentionally terse — the attached card carries the
        // detail (players, winner, time control, status). Plain-text
        // contexts (screen readers, future dispute log exports) still get
        // a meaningful one-liner.
        $postSystem->handle(
            $this->match,
            __('Verified Lichess game record.'),
            [$card],
        );

        return $card;
    }

    /**
     * Postgres `attachments_json @> '[{"source":"auto_fetch"}]'` matches
     * any system message in this match whose attachments array carries an
     * entry with `source = auto_fetch`. Per-match scan is bounded by the
     * chat's message count — small in practice.
     */
    private function alreadyPosted(): bool
    {
        return Message::query()
            ->where('match_id', $this->match->id)
            ->where('type', MessageType::System)
            ->whereJsonContains('attachments_json', [['source' => 'auto_fetch']])
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(LichessGameResult $game): array
    {
        return [
            'type' => 'game_card',
            'provider' => 'lichess',
            'source' => 'auto_fetch',
            'game_id' => $game->id,
            'url' => 'https://lichess.org/'.$game->id,
            // Auto-fetched games are always verified — the search itself is
            // username-anchored, so any returned game involves the two
            // snapshotted players by construction.
            'verified' => true,
            'white_username' => $game->whiteUsername,
            'black_username' => $game->blackUsername,
            'winner_color' => $game->winnerColor,
            'winner_username' => $game->winnerUsername(),
            'status' => $game->status,
            'speed' => $game->speed,
            'variant' => $game->variant,
            'rated' => $game->rated,
            'played_at' => $game->lastMoveAt->toIso8601String(),
        ];
    }
}
