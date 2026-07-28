<?php

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\Withdrawals;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Hands a pending withdrawal to the active `PaymentGateway` (M9 Phase 0b).
 *
 * `ShouldQueueAfterCommit` matters here: the withdrawal row and its ledger
 * debit are written in one transaction, so dispatching before commit could
 * hand a worker an id that doesn't exist yet — or, worse, pay out against a
 * debit that then rolls back.
 *
 * Retries are safe because `Withdrawals::send()` passes the withdrawal id as
 * the provider's idempotency key, and every terminal transition is guarded on
 * the current status. On permanent failure the debit is reversed so the user's
 * money never sits in limbo.
 */
class ProcessWithdrawal implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Modest budget: a payout either goes through or it doesn't. Long retry
     * chains would keep the user's money debited-but-unsent for no benefit —
     * failing over to a reversal sooner is the kinder outcome.
     */
    public int $tries = 4;

    public function __construct(
        public Withdrawal $withdrawal,
    ) {}

    /**
     * One in-flight send per withdrawal.
     */
    public function uniqueId(): string
    {
        return (string) $this->withdrawal->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        Withdrawals::send($this->withdrawal->fresh());
    }

    /**
     * Every retry is spent. Return the money rather than leaving it debited
     * with nothing on its way to the user.
     */
    public function failed(?Throwable $exception): void
    {
        Withdrawals::markFailed(
            $this->withdrawal->fresh(),
            $exception?->getMessage() ?? 'Payout failed after exhausting retries.',
        );
    }
}
