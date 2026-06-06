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
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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
     */
    public int $tries = 7;

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

    public function handle(
        ChessComGameClient $client,
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
            $this->fail($e);

            return;
        } catch (RateLimitedError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => $this->errorRetriesExhausted() ? 'retry_exhausted' : null,
            ]);

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
            throw $e;
        }

        $latencyMs = $this->elapsedMs($start);
        $completed = array_values(array_filter(
            $games,
            // Decisive or draw — aborted / half-played excluded (not a real result to settle).
            fn (ChessComGameResult $g) => $g->isDecisive() || $g->isDraw(),
        ));
        $count = count($completed);

        if ($count === 0) {
            // Record before releasing so each retry shows independently in the audit timeline.
            $reason = $this->noMatchRetriesExhausted() ? 'retry_exhausted' : null;
            $this->record($recordAttempt, AutoFetchOutcome::NoMatch, [
                'candidates_count' => 0,
                'latency_ms' => $latencyMs,
                'outcome_reason' => $reason,
            ]);

            $this->retryNoMatchIfBudgetRemains();

            return;
        }

        if ($count > 1) {
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => $count,
                'latency_ms' => $latencyMs,
            ]);

            return;
        }

        $game = $completed[0];
        $card = $this->postCard($postSystem, $game);

        $this->record($recordAttempt, AutoFetchOutcome::Matched, [
            'winner_username' => $game->winnerUsername(),
            'candidates_count' => 1,
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
