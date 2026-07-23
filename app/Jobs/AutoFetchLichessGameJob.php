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
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\LichessGameClient;
use App\Services\Provider\LichessGameResult;
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
 * Posts an auto-fetched Lichess game card for a Pending match, then settles via
 * `SettleFromCardAction`. Searches Lichess for games between the snapshotted usernames
 * since `match.created_at`; exactly one completed candidate triggers post + settle.
 * Zero or multiple candidates skip silently — wrong-game evidence is worse than none.
 *
 * Snapshot-cross-checked (not live-looked-up) so a mid-match unlink can't strip the anchor.
 * Idempotent via attachments_json scan (`alreadyPosted()`) — re-dispatch / queue-retry won't
 * double-post or double-settle.
 *
 * Retry policy (M14 Slice 2b):
 *   - no_match (M46 P3): explicit `release()` per `RETRY_DELAYS` ([3, 8, 20]s, ~31s).
 *     Bridges the few-second lag between a Lichess game-end (which the stream
 *     sidecar fires this job on) and the game appearing in the `/api/games/user`
 *     export the search reads — so the fast stream signal lands on the first
 *     shot instead of falling through to the 10-min cron backstop.
 *   - `TransientProviderError` / `RateLimitedError` → audit row + re-throw; Laravel
 *     retries per `backoff()` up to `$tries`.
 *   - `PermanentProviderError` → audit row (`outcome_reason='permanent'`) + `$this->fail($e)`;
 *     no retry, lands in `failed_jobs`.
 *   - `retryUntil()` caps the whole chain at `match.created_at + M16 confirmation timeout`
 *     so we never retry past the point where `ResolveMatchTimeoutAction` flips the match
 *     to ManualReview.
 */
class AutoFetchLichessGameJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * M35 P2 — widened from 4 to 12 to absorb `RateLimited` middleware
     * releases. Each throttle release consumes an attempt without running
     * the handler; `retryUntil()` is the real safety net.
     */
    public int $tries = 12;

    public int $timeout = 30;

    /**
     * Caps unique-lock lifetime past the worst-case retry chain so the lock
     * doesn't strand if the queue worker dies mid-retry. Comfortably covers
     * the ~31s no_match chain (M46 P3) plus rate-limit release headroom.
     */
    public int $uniqueFor = 240;

    /**
     * Per-attempt delays for the no_match retry chain (M46 P3). HTTP transient
     * errors use `backoff()` instead. Short by design — it only bridges the
     * Lichess game-export indexing lag after a stream-triggered dispatch; the
     * stream + cron re-fire on any longer gap.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS = [3, 8, 20];

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

    /**
     * Bound the retry chain so it can't outlive the match's M16 confirmation
     * window. Once the timeout fires, `ResolveMatchTimeoutAction` flips the
     * match to ManualReview and further auto-fetch is pointless.
     */
    public function retryUntil(): DateTimeInterface
    {
        return $this->match->created_at
            ->copy()
            ->addHours((int) config('stakly.match_confirmation_timeout_hours'));
    }

    /**
     * Self-throttle (M35 P2). `lichess-api` limiter is defined in
     * `AppServiceProvider::registerProviderRateLimiters()` and reads
     * `config('services.lichess.requests_per_minute')`. When the cap is
     * hit, this middleware releases the job back to the queue (consuming
     * one of the `$tries` budget) and retries after the limit window.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('lichess-api')];
    }

    public function handle(
        LichessGameClient $client,
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
            LinkedAccountProvider::Lichess,
        );
        $takerUsername = $this->match->snapshotUsername(
            GameMatch::SIDE_TAKER,
            LinkedAccountProvider::Lichess,
        );

        if ($creatorUsername === null || $takerUsername === null) {
            // Defensive — caller gates on this, but re-dispatch could land here out of context.
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
            $breaker->recordFailure(LinkedAccountProvider::Lichess);
            $this->fail($e);

            return;
        } catch (RateLimitedError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => $this->errorRetriesExhausted() ? 'retry_exhausted' : null,
            ]);
            $breaker->recordFailure(LinkedAccountProvider::Lichess);

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
            $breaker->recordFailure(LinkedAccountProvider::Lichess);
            throw $e;
        }

        // HTTP call succeeded (regardless of candidate count) — circuit health
        // tracks provider availability, not whether games were found.
        $breaker->recordSuccess(LinkedAccountProvider::Lichess);

        $latencyMs = $this->elapsedMs($start);
        $completed = $this->filterCompleted($games);

        // M46 P2 — started-after-creation guard (SECURITY). Drop any game that
        // STARTED before this match was created; the staked game must post-date
        // the stake. Guards the single-candidate direct-settle path against
        // pre-play reuse.
        $fresh = $this->rejectStale($completed);
        $count = count($fresh);

        if ($count === 0) {
            // stale_game_rejected distinguishes "found only pre-stake games"
            // (pre-play attempt or clock skew) from "found nothing" in the audit.
            // Both retry (M46 P3): the real game may not be in the export index
            // yet even though the stream already fired us on its game-end.
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

        // M14 Slice 3c — single candidates are settled directly (no TC
        // filter). The picker only disambiguates when the search window
        // surfaces multiple games. Slice 3d's strict single-candidate
        // enforcement was reverted on 2026-06-06 — too aggressive in
        // practice given Lichess's `correspondence` / `ultraBullet` speeds
        // sit outside Stakly's TimeControl enum (bullet joined the enum in
        // M41 P3a, so a single bullet game still settles via this path).
        // Both paths operate on started-after candidates only (stale dropped above).
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

        // Audit row lands before settlement so the pipeline decision is recorded even if settle throws.
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

    private function errorRetriesExhausted(): bool
    {
        return $this->attempts() >= $this->tries;
    }

    /**
     * True once attempts have reached the no_match retry chain length (M46 P3).
     * Drives the `retry_exhausted` audit reason for terminal no_match outcomes.
     */
    private function noMatchRetriesExhausted(): bool
    {
        return $this->attempts() >= count(self::RETRY_DELAYS) + 1;
    }

    private function retryNoMatchIfBudgetRemains(): void
    {
        if ($this->noMatchRetriesExhausted()) {
            return;
        }

        // `attempts()` is 1-indexed; RETRY_DELAYS is 0-indexed.
        $this->release(self::RETRY_DELAYS[$this->attempts() - 1]);
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
     * caller records `outcome=ambiguous` with `outcome_reason=time_control_mismatch`
     * and the match stays Pending until either a matching game lands or
     * the M16 timeout flips it to ManualReview.
     *
     * @param  list<LichessGameResult>  $candidates
     */
    private function pickSettleableCandidate(array $candidates): ?LichessGameResult
    {
        $listingControl = $this->match->listing->time_control?->value;

        $tcMatches = array_values(array_filter(
            $candidates,
            fn (LichessGameResult $g) => $g->speed === $listingControl,
        ));

        if ($tcMatches === []) {
            return null;
        }

        if (count($tcMatches) === 1) {
            return $tcMatches[0];
        }

        // M46 P2 — deterministic pick: the FIRST game started after the stake
        // (earliest `createdAt`) is the staked game; a rematch is later. Was
        // closest-to-`created_at` by `lastMoveAt` — the started-after guard
        // already drops pre-stake games, and "first after" is the clearer rule.
        usort($tcMatches, function (LichessGameResult $a, LichessGameResult $b) {
            $byStart = $a->createdAt->getTimestamp() <=> $b->createdAt->getTimestamp();

            return $byStart !== 0 ? $byStart : strcmp($a->id, $b->id);
        });

        return $tcMatches[0];
    }

    /**
     * Settle-eligible candidates. Two-pass: decisive / draw games are the
     * primary candidates (a real played-out game beats an aborted one when
     * both exist in the same window). Fall back to aborted games only
     * when there's no primary candidate (M14 Slice 3b — aborted settles
     * as cooperative-exit refund). `unknown` falls through to silent skip.
     *
     * @param  list<LichessGameResult>  $games
     * @return list<LichessGameResult>
     */
    private function filterCompleted(array $games): array
    {
        $primary = array_values(array_filter(
            $games,
            fn (LichessGameResult $g) => $g->isDecisive() || $g->isDraw(),
        ));

        if ($primary !== []) {
            return $primary;
        }

        return array_values(array_filter(
            $games,
            fn (LichessGameResult $g) => $g->isAborted(),
        ));
    }

    /**
     * M46 P2 — SECURITY guard. Keep only games that STARTED at or after this
     * match was created; the staked game must post-date the stake. Lichess
     * `createdAt` is the game's creation (start) timestamp. Guards the
     * single-candidate direct-settle path against pre-play reuse.
     *
     * @param  list<LichessGameResult>  $games
     * @return list<LichessGameResult>
     */
    private function rejectStale(array $games): array
    {
        $matchCreated = $this->match->created_at;

        return array_values(array_filter(
            $games,
            fn (LichessGameResult $g) => $g->createdAt->gte($matchCreated),
        ));
    }

    /**
     * @return array<string, mixed> card payload — passed to SettleFromCardAction so the
     *                              settle decision uses the same data the players see.
     */
    private function postCard(PostSystemMessageAction $postSystem, LichessGameResult $game): array
    {
        $card = $this->buildEntry($game);

        // Text is intentionally terse — the attached card carries the detail.
        $postSystem->handle(
            $this->match,
            __('Verified Lichess game record.'),
            [$card],
        );

        return $card;
    }

    /**
     * Postgres `@>` containment match on any system message in this match with
     * `source = auto_fetch` in attachments. Per-match scan is bounded by chat message count.
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
            // Always verified — the search is username-anchored on both snapshots.
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
