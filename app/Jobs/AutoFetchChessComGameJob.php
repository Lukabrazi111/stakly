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
use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\ChessComGameResult;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\ProviderCircuitBreaker;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * Posts an auto-fetched chess.com game card for a Pending match, then settles via
 * `SettleFromCardAction`. Retries on empty candidates because chess.com's monthly
 * archive lags 5-15s after game-end (5s / 15s / 45s = ~65s total).
 *
 * Every attempt (including empty ones) writes a `match_auto_fetch_attempts` row so
 * the admin timeline shows the full retry chain rather than a single terminal row.
 *
 * Retry policy (M14 Slice 2b):
 *   - Two retry chains share the `$tries` budget:
 *     * no_match (archive lag): explicit `release()` per `RETRY_DELAYS` ([5, 15, 45]s).
 *     * HTTP error: re-throw + Laravel-driven `backoff()` ([5, 15, 30]s).
 *   - `PermanentProviderError` → audit row + `$this->fail($e)`, no retry.
 *   - `TransientProviderError` / `RateLimitedError` → audit row + re-throw, retried.
 *   - `retryUntil()` caps the chain at `match.created_at + M16 confirmation timeout`.
 */
class AutoFetchChessComGameJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Budget covers the longest plausible run: 3 transient-error retries +
     * the 4-attempt no_match chain. They share the counter; the actual mix
     * depends on what the provider returns.
     *
     * M35 P1 — extra headroom for `RateLimited` middleware releases. Each
     * throttle release consumes an attempt without running the handler, so
     * we widen the budget to absorb plausible burst-moment release counts
     * before the retry chain itself starts firing. `retryUntil()` remains
     * the real safety net.
     */
    public int $tries = 15;

    public int $timeout = 30;

    /**
     * Caps unique-lock lifetime past the worst-case retry chain (combined
     * no_match + transient-error delays). Prevents the lock from stranding
     * if the queue worker dies mid-retry.
     */
    public int $uniqueFor = 360;

    /**
     * Per-attempt delays for the archive-lag retry chain (no_match outcome).
     * HTTP transient errors use `backoff()` instead.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS = [5, 15, 45];

    public function __construct(
        public GameMatch $match,
    ) {}

    /**
     * One in-flight job per match — dedupe races between page-visit, chat-send, cron, stream
     * trigger sites so we don't spam the provider API.
     */
    public function uniqueId(): string
    {
        return (string) $this->match->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->match->created_at
            ->copy()
            ->addHours((int) config('stakly.match_confirmation_timeout_hours'));
    }

    /**
     * Self-throttle (M35 P1). `chess-com-api` limiter is defined in
     * `AppServiceProvider::registerProviderRateLimiters()` and reads
     * `config('services.chess_com.requests_per_minute')`. When the cap is
     * hit, this middleware releases the job back to the queue (consuming
     * one of the `$tries` budget) and retries after the limit window.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('chess-com-api')];
    }

    public function handle(
        ChessComGameClient $client,
        PostSystemMessageAction $postSystem,
        SettleFromCardAction $settleFromCard,
        RecordAutoFetchAttemptAction $recordAttempt,
        ProviderCircuitBreaker $breaker,
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
            LinkedAccountProvider::ChessCom,
        );
        $takerUsername = $this->match->snapshotUsername(
            GameMatch::SIDE_TAKER,
            LinkedAccountProvider::ChessCom,
        );

        if ($creatorUsername === null || $takerUsername === null) {
            $this->record($recordAttempt, AutoFetchOutcome::Skipped, [
                'outcome_reason' => 'snapshot_missing',
            ]);

            return;
        }

        $start = microtime(true);

        try {
            $games = $client->searchGamesBetween(
                $creatorUsername,
                $takerUsername,
                $this->match->created_at,
            );
        } catch (PermanentProviderError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => 'permanent',
            ]);
            $breaker->recordFailure(LinkedAccountProvider::ChessCom);
            $this->fail($e);

            return;
        } catch (RateLimitedError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => $this->errorRetriesExhausted() ? 'retry_exhausted' : null,
            ]);
            $breaker->recordFailure(LinkedAccountProvider::ChessCom);

            $retryAt = $e->retryAt();
            // `now()->getTimestamp()` (not PHP's `time()`) so Carbon's
            // `setTestNow` mocking carries through to tests.
            $nowTs = now()->getTimestamp();
            if ($retryAt !== null
                && $retryAt->getTimestamp() > $nowTs
                && ! $this->errorRetriesExhausted()
            ) {
                // Honor the provider-supplied delay over the job's default `backoff()`.
                $this->release(max(1, $retryAt->getTimestamp() - $nowTs));

                return;
            }

            throw $e;
        } catch (ProviderError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => $this->errorRetriesExhausted() ? 'retry_exhausted' : null,
            ]);
            $breaker->recordFailure(LinkedAccountProvider::ChessCom);
            throw $e;
        }

        // HTTP call succeeded (regardless of candidate count) — circuit health
        // tracks provider availability, not whether games were found.
        $breaker->recordSuccess(LinkedAccountProvider::ChessCom);

        $latencyMs = $this->elapsedMs($start);
        $completed = $this->filterCompleted($games);

        // M46 P2 — started-after-creation guard (SECURITY). Drop any game that
        // STARTED before this match was created; the staked game must post-date
        // the stake. Without it, a game played (or in progress) before the
        // listing was taken could be auto-settled — pre-play reuse. Applied
        // before the single-vs-multi branch so the single-candidate
        // direct-settle path can't be gamed either.
        $fresh = $this->rejectStale($completed);
        $count = count($fresh);

        if ($count === 0) {
            // Distinguish "found only pre-stake games" (stale_game_rejected)
            // from "found nothing" in the audit — a stale hit signals a
            // pre-play attempt or clock skew, not just archive lag. Both retry:
            // the real game may still land (chess.com archive lags 5-15s).
            $reason = $this->noMatchRetriesExhausted()
                ? 'retry_exhausted'
                : ($completed !== [] ? 'stale_game_rejected' : null);
            $this->record($recordAttempt, AutoFetchOutcome::NoMatch, [
                'candidates_count' => count($completed),
                'latency_ms' => $latencyMs,
                'outcome_reason' => $reason,
            ]);

            $this->retryNoMatchIfBudgetRemains();

            return;
        }

        // M14 Slice 3c — single candidates are settled directly (no TC filter;
        // Slice 3d's strict enforcement reverted 2026-06-06). Picker fires for
        // multi-candidate disambiguation. Both operate on started-after
        // candidates only (stale ones dropped above).
        $game = $count === 1
            ? $fresh[0]
            : $this->pickSettleableCandidate($fresh);

        if ($game === null) {
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => $count,
                'latency_ms' => $latencyMs,
                'outcome_reason' => 'time_control_mismatch',
            ]);

            return;
        }

        $card = $this->postCard($postSystem, $game);

        // `candidates_count` is the pre-disambiguation count — reader sees
        // "we found N, picked 1" rather than "we found 1".
        $this->record($recordAttempt, AutoFetchOutcome::Matched, [
            'winner_username' => $game->winnerUsername(),
            'candidates_count' => $count,
            'latency_ms' => $latencyMs,
        ]);

        // The card IS the settlement trigger. SettleFromCardAction row-locks the match,
        // no-ops if not Pending (idempotent), branches winner vs draw.
        $settleFromCard->handle($this->match, $card);
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * Pick the candidate this job should settle on. M14 Slice 3d — applies
     * to any candidate count: filter to those whose speed equals the
     * listing's single `time_control` value (M41 P3a); if multiple survive
     * (Slice 3c), pick the earliest game *started* after the stake — the first
     * game played for this match; a rematch is later (M46 P2). Tie-break on
     * lexicographic game id for determinism.
     *
     * Returns null when no candidate matches the listing's time-control —
     * caller records ambiguous, match stays Pending.
     *
     * @param  list<ChessComGameResult>  $candidates
     */
    private function pickSettleableCandidate(array $candidates): ?ChessComGameResult
    {
        $listingControl = $this->match->listing->time_control?->value;

        $tcMatches = array_values(array_filter(
            $candidates,
            fn (ChessComGameResult $g) => $g->speed === $listingControl,
        ));

        if ($tcMatches === []) {
            return null;
        }

        if (count($tcMatches) === 1) {
            return $tcMatches[0];
        }

        // M46 P2 — deterministic pick: the FIRST game started after the stake
        // (earliest `createdAt`) is the staked game; a rematch is later. Was
        // closest-to-`created_at` by end time — the started-after guard already
        // drops pre-stake games, and "first after" is the clearer rule.
        usort($tcMatches, function (ChessComGameResult $a, ChessComGameResult $b) {
            $byStart = $a->createdAt->getTimestamp() <=> $b->createdAt->getTimestamp();

            return $byStart !== 0 ? $byStart : strcmp($a->id, $b->id);
        });

        return $tcMatches[0];
    }

    /**
     * Settle-eligible candidates. Two-pass: decisive / draw games are the
     * primary candidates (a real played-out game beats an abandoned one
     * when both exist in the same window). Fall back to abandoned games
     * only when there's no primary candidate (M14 Slice 3b — cooperative-
     * exit refund, settled as draw).
     *
     * @param  list<ChessComGameResult>  $games
     * @return list<ChessComGameResult>
     */
    private function filterCompleted(array $games): array
    {
        $primary = array_values(array_filter(
            $games,
            fn (ChessComGameResult $g) => $g->isDecisive() || $g->isDraw(),
        ));

        if ($primary !== []) {
            return $primary;
        }

        return array_values(array_filter(
            $games,
            fn (ChessComGameResult $g) => $g->isAborted(),
        ));
    }

    /**
     * M46 P2 — SECURITY guard. Keep only games that STARTED at or after this
     * match was created; the staked game must post-date the stake. chess.com
     * `createdAt` is the game's `start_time` (end_time fallback for daily
     * games, which aren't Stakly time controls). Guards the single-candidate
     * direct-settle path against pre-play reuse.
     *
     * @param  list<ChessComGameResult>  $games
     * @return list<ChessComGameResult>
     */
    private function rejectStale(array $games): array
    {
        $matchCreated = $this->match->created_at;

        return array_values(array_filter(
            $games,
            fn (ChessComGameResult $g) => $g->createdAt->gte($matchCreated),
        ));
    }

    /**
     * True once attempts have reached the no_match retry chain length. Drives
     * the `retry_exhausted` audit-row reason for terminal no_match outcomes.
     */
    private function noMatchRetriesExhausted(): bool
    {
        return $this->attempts() >= count(self::RETRY_DELAYS) + 1;
    }

    /**
     * True on the final attempt of the overall `$tries` budget. Drives the
     * `retry_exhausted` audit-row reason for terminal transient-error outcomes.
     */
    private function errorRetriesExhausted(): bool
    {
        return $this->attempts() >= $this->tries;
    }

    private function retryNoMatchIfBudgetRemains(): void
    {
        if ($this->noMatchRetriesExhausted()) {
            return;
        }

        // `attempts()` is 1-indexed; RETRY_DELAYS is 0-indexed.
        $this->release(self::RETRY_DELAYS[$this->attempts() - 1]);
    }

    /**
     * @return array<string, mixed> card payload — passed to SettleFromCardAction so the
     *                              settle decision uses the same data the players see.
     */
    private function postCard(PostSystemMessageAction $postSystem, ChessComGameResult $game): array
    {
        $card = $this->buildEntry($game);

        $postSystem->handle(
            $this->match,
            __('Verified chess.com game record.'),
            [$card],
        );

        return $card;
    }

    private function alreadyPosted(): bool
    {
        return Message::query()
            ->where('match_id', $this->match->id)
            ->where('type', MessageType::System)
            ->whereJsonContains('attachments_json', [['source' => 'auto_fetch', 'provider' => 'chess_com']])
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(ChessComGameResult $game): array
    {
        return [
            'type' => 'game_card',
            'provider' => 'chess_com',
            'source' => 'auto_fetch',
            'game_id' => $game->id,
            'url' => $game->url,
            'verified' => true,
            'white_username' => $game->whiteUsername,
            'black_username' => $game->blackUsername,
            'winner_color' => $game->winnerColor,
            'winner_username' => $game->winnerUsername(),
            'status' => $game->status,
            'speed' => $game->speed,
            'variant' => $game->variant,
            'rated' => $game->rated,
            'played_at' => $game->endedAt->toIso8601String(),
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
            provider: LinkedAccountProvider::ChessCom,
            outcome: $outcome,
            extras: [
                'attempt_number' => $this->attempts(),
                ...$extras,
            ],
        );
    }
}
