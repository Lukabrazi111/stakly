<?php

namespace App\Jobs;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use App\Services\Provider\LichessGameClient;
use App\Services\Provider\LichessGameResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts an auto-fetched Lichess game card on the first player confirm
 * (M8 Phase 4 auto-fetch path). Dispatched by `ConfirmOutcomeAction` on
 * the zero→one confirm transition when both snapshotted Lichess usernames
 * are present on the match row.
 *
 * Pipeline:
 *   1. Search Lichess for games between the two snapshotted usernames
 *      since `match.created_at` via `LichessGameClient::searchGamesBetween()`.
 *   2. Filter to decisive games only (mate / resign / outoftime / etc.).
 *      Drawn / aborted games are excluded — they shouldn't auto-narrate
 *      "X won" in chat.
 *   3. If exactly ONE candidate exists, post a system message with the
 *      verified card attached. Zero or multiple candidates → silent skip.
 *      Wrong-game evidence is worse than no evidence; the paste path is
 *      the player's escape hatch for ambiguous cases.
 *
 * Decisions encoded:
 *   - Evidence, not a vote. No yes/no buttons on the card — Confirm
 *     Won/Lost/Drawn remains the only binding signal.
 *   - Never auto-settles. Even if the API winner disagrees with player
 *     confirms, settlement logic still uses the consensus path (M14 will
 *     gate auto-settlement on adapter maturity + dispute volume).
 *   - Snapshot-cross-checked, not live-looked-up. A mid-match unlink can't
 *     strip the anchor.
 *   - One post per match. Idempotency via an attachments_json scan keeps
 *     re-dispatch / queue-retry from double-posting.
 */
class AutoFetchLichessGameJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public GameMatch $match,
    ) {}

    public function handle(LichessGameClient $client, PostSystemMessageAction $postSystem): void
    {
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

        $decisive = $this->filterDecisive($games);

        if (count($decisive) !== 1) {
            // Zero or multiple — silent skip. Wrong-game evidence is worse
            // than no evidence; manual paste covers the ambiguous case.
            return;
        }

        $this->postCard($postSystem, $decisive[0]);
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
     * @param  list<LichessGameResult>  $games
     * @return list<LichessGameResult>
     */
    private function filterDecisive(array $games): array
    {
        return array_values(array_filter(
            $games,
            fn (LichessGameResult $g) => $g->isDecisive(),
        ));
    }

    private function postCard(PostSystemMessageAction $postSystem, LichessGameResult $game): void
    {
        // Text is intentionally terse — the attached card carries the
        // detail (players, winner, time control, status). Plain-text
        // contexts (screen readers, future dispute log exports) still get
        // a meaningful one-liner.
        $postSystem->handle(
            $this->match,
            __('Verified Lichess game record.'),
            [$this->buildEntry($game)],
        );
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
