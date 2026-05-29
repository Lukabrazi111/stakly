<?php

namespace App\Jobs;

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\AutoFetchOutcome;
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
 *
 * M14 Phase 1 — every return path writes a `match_auto_fetch_attempts`
 * row via `RecordAutoFetchAttemptAction`. Each row captures the outcome,
 * candidate count, provider latency, and (on error) the exception
 * message. Skip rows additionally carry an `outcome_reason` discriminator.
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
        RecordAutoFetchAttemptAction $recordAttempt,
    ): void {
        if ($this->alreadyPosted()) {
            $this->record($recordAttempt, AutoFetchOutcome::Skipped, [
                'outcome_reason' => 'already_posted',
            ]);

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
            $this->record($recordAttempt, AutoFetchOutcome::Skipped, [
                'outcome_reason' => 'snapshot_missing',
            ]);

            return;
        }

        [$games, $errorMessage, $latencyMs] = $this->searchWithMetrics(
            $client,
            $creatorUsername,
            $takerUsername,
        );

        if ($games === null) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $errorMessage,
                'latency_ms' => $latencyMs,
            ]);

            return;
        }

        $completed = $this->filterCompleted($games);
        $count = count($completed);

        if ($count === 0) {
            $this->record($recordAttempt, AutoFetchOutcome::NoMatch, [
                'candidates_count' => 0,
                'latency_ms' => $latencyMs,
            ]);

            return;
        }

        if ($count > 1) {
            // Wrong-game evidence is worse than no evidence; manual paste
            // covers the ambiguous case.
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => $count,
                'latency_ms' => $latencyMs,
            ]);

            return;
        }

        $game = $completed[0];
        $card = $this->postCard($postSystem, $game);

        // Audit row lands before settlement — settlement re-locks the match
        // and could throw, but the audit row reflects what the pipeline
        // decided regardless of downstream outcome.
        $this->record($recordAttempt, AutoFetchOutcome::Matched, [
            'winner_username' => $game->winnerUsername(),
            'candidates_count' => 1,
            'latency_ms' => $latencyMs,
        ]);

        // M16 — card IS the settlement trigger. SettleFromCardAction
        // row-locks the match, no-ops if not Pending (idempotent re-runs),
        // branches on winner_color to SettleMatchAction (winner) or
        // SettleDrawMatchAction (draw).
        $settleFromCard->handle($this->match, $card);
    }

    /**
     * Wraps the provider search with latency tracking and structured error
     * capture. Returns `[games, errorMessage, latencyMs]` — exactly one of
     * `games` / `errorMessage` is non-null.
     *
     * @return array{0: list<LichessGameResult>|null, 1: string|null, 2: int}
     */
    private function searchWithMetrics(
        LichessGameClient $client,
        string $creatorUsername,
        string $takerUsername,
    ): array {
        $start = microtime(true);

        try {
            $games = $client->searchGamesBetween(
                $creatorUsername,
                $takerUsername,
                $this->match->created_at,
            );

            return [$games, null, $this->elapsedMs($start)];
        } catch (ProviderUnavailableException $e) {
            return [null, $e->getMessage(), $this->elapsedMs($start)];
        } catch (Throwable $e) {
            return [null, $e->getMessage(), $this->elapsedMs($start)];
        }
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
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

    /**
     * @param  array<string, mixed>  $extras
     */
    private function record(
        RecordAutoFetchAttemptAction $action,
        AutoFetchOutcome $outcome,
        array $extras = [],
    ): void {
        $action->handle(
            matchId: $this->match->id,
            provider: LinkedAccountProvider::Lichess,
            outcome: $outcome,
            extras: $extras,
        );
    }
}
