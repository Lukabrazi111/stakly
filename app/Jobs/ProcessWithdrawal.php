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
use Illuminate\Support\Facades\Log;
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
     * Every retry is spent.
     *
     * Auto-reversing is only safe while we KNOW the provider never took the
     * payout. Once `provider_payout_id` is set the funds may already be moving
     * on-chain, and crediting the balance back would hand the player the money
     * twice — the job can fail *after* a successful `createPayout` (e.g. the
     * database blips while `markCompleted` books the margin).
     *
     * So: reverse only the untouched case, and leave anything the provider has
     * seen for a human. It stays visible in the admin withdrawal queue, where
     * Reject is still available once its real state is known.
     */
    public function failed(?Throwable $exception): void
    {
        $withdrawal = $this->withdrawal->fresh();

        if ($withdrawal === null || $withdrawal->status->isTerminal()) {
            return;
        }

        $reason = $exception?->getMessage() ?? 'Payout failed after exhausting retries.';

        if ($withdrawal->provider_payout_id !== null) {
            Log::critical('Withdrawal payout failed after reaching the provider — NOT auto-reversed.', [
                'withdrawal_id' => $withdrawal->id,
                'provider_payout_id' => $withdrawal->provider_payout_id,
                'status' => $withdrawal->status->value,
                'reason' => $reason,
            ]);

            return;
        }

        Withdrawals::markFailed($withdrawal, $reason);
    }
}
