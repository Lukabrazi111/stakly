<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Exceptions\AccountFrozenException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\KycRequiredException;
use App\Jobs\ProcessWithdrawal;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payments\Dto\GatewayPayoutStatus;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cash-out lifecycle (M9 Phase 0b). The only writer of `withdrawals` rows.
 *
 * Every money movement goes through `App\Services\Wallet`, keyed on the
 * withdrawal id so each step is idempotent under retry:
 *
 *   wd:{id}           the debit taken at request time
 *   wd-reversal:{id}  the credit back on reject / permanent failure
 *   wd-margin:{id}    Stakly's cut, booked to the platform user
 *
 * Margin is booked at COMPLETION, never at request — a rejected withdrawal
 * must not record phantom revenue.
 *
 * There is no admin approval gate: the anti-abuse hold lives on the payout
 * (`App\Services\PayoutClearance`), so money reaching this service has already
 * cleared its insurance window.
 */
final class Withdrawals
{
    private const SCALE = 6;

    /**
     * Debit the user and queue the payout.
     *
     * @throws AccountFrozenException|InsufficientBalanceException|InvalidArgumentException|KycRequiredException
     */
    public static function request(User $user, string $amount, string $address): Withdrawal
    {
        $margin = (string) config('stakly.withdrawal_margin');

        if (bccomp($amount, $margin, self::SCALE) <= 0) {
            throw new InvalidArgumentException(
                "Withdrawal of {$amount} does not cover the platform margin of {$margin}."
            );
        }

        $withdrawal = DB::transaction(function () use ($user, $amount, $address, $margin) {
            // Locked before the availability check so two concurrent requests
            // can't each see the same headroom and both pass. Wallet::record
            // re-locks the same row inside this transaction, which is a no-op.
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->isFrozen()) {
                throw AccountFrozenException::for($locked->id);
            }

            // Off by default. Under the same lock as the balance check so the
            // volume tally can't be raced by concurrent requests each seeing
            // the other's withdrawal as not-yet-existing.
            if (KycGate::requiresVerification($locked, $amount)) {
                throw KycRequiredException::for($locked->id);
            }

            // Checked against AVAILABLE, not total: winnings still inside their
            // insurance window can be staked but must not be able to leave.
            $available = Wallet::availableBalance($locked);

            if (bccomp($amount, $available, self::SCALE) > 0) {
                throw new InsufficientBalanceException(
                    "User {$locked->id} has {$available} available (balance {$locked->usdt_balance}); requested {$amount}."
                );
            }

            $withdrawal = Withdrawal::create([
                'user_id' => $locked->id,
                'amount' => $amount,
                'platform_fee' => $margin,
                'destination_address' => $address,
                'status' => WithdrawalStatus::Pending,
            ]);

            $debit = Wallet::withdraw(
                user: $locked,
                amount: $amount,
                reference: "wd:{$withdrawal->id}",
                description: "Withdrawal #{$withdrawal->id}",
            );

            $withdrawal->update(['debit_transaction_id' => $debit->id]);

            return $withdrawal;
        });

        ProcessWithdrawal::dispatch($withdrawal);

        return $withdrawal->fresh();
    }

    /**
     * Hand the payout to the active gateway and record whatever it says.
     *
     * All four `GatewayPayoutStatus` cases are mapped even though `MockGateway`
     * only ever returns `Completed` — the async paths are what a real provider
     * uses, and leaving them unhandled would make the swap a silent breakage.
     */
    public static function send(Withdrawal $withdrawal): Withdrawal
    {
        if ($withdrawal->status->isTerminal()) {
            return $withdrawal;
        }

        $gateway = app(PaymentGateway::class);

        $result = $gateway->createPayout(
            amount: $withdrawal->netAmount(),
            address: $withdrawal->destination_address,
            // The withdrawal id is the provider-side idempotency key, so a
            // retried job can never double-send.
            reference: "wd:{$withdrawal->id}",
        );

        $withdrawal->update([
            'provider' => config('services.payments.driver'),
            'provider_payout_id' => $result->providerPayoutId,
        ]);

        return match ($result->status) {
            GatewayPayoutStatus::Completed => self::markCompleted(
                $withdrawal,
                $result->txHash,
                $result->networkFee,
            ),
            // Accepted but not yet on-chain. The payout webhook (Phase 1)
            // finishes the story; until then the row sits in Sending.
            GatewayPayoutStatus::Queued,
            GatewayPayoutStatus::Sending => tap($withdrawal)->update([
                'status' => WithdrawalStatus::Sending,
            ]),
            GatewayPayoutStatus::Failed => self::markFailed(
                $withdrawal,
                'Provider rejected the payout.',
            ),
        };
    }

    /**
     * Provider confirmed the send. Books Stakly's margin now that the money
     * has actually left custody.
     */
    public static function markCompleted(
        Withdrawal $withdrawal,
        ?string $txHash = null,
        ?string $networkFee = null,
    ): Withdrawal {
        if ($withdrawal->status === WithdrawalStatus::Completed) {
            return $withdrawal;
        }

        if ($withdrawal->status->isReversed()) {
            throw new InvalidArgumentException(
                "Withdrawal #{$withdrawal->id} was already reversed and cannot complete."
            );
        }

        return DB::transaction(function () use ($withdrawal, $txHash, $networkFee) {
            $margin = (string) $withdrawal->platform_fee;

            if (bccomp($margin, '0', self::SCALE) > 0) {
                Wallet::fee(
                    amount: $margin,
                    listing: null,
                    reference: "wd-margin:{$withdrawal->id}",
                    description: "Platform margin on withdrawal #{$withdrawal->id}",
                );
            }

            $withdrawal->update([
                'status' => WithdrawalStatus::Completed,
                'tx_hash' => $txHash,
                'network_fee' => $networkFee,
            ]);

            return $withdrawal;
        });
    }

    /**
     * Admin refusal. Credits the full gross back — the margin was never booked,
     * so there's nothing to unwind on the platform side.
     */
    public static function reject(Withdrawal $withdrawal, string $reason, User $admin): Withdrawal
    {
        if ($withdrawal->status->isTerminal()) {
            return $withdrawal;
        }

        return DB::transaction(function () use ($withdrawal, $reason, $admin) {
            self::reverseDebit($withdrawal, "rejected: {$reason}");

            $withdrawal->update([
                'status' => WithdrawalStatus::Rejected,
                'rejected_reason' => $reason,
                'reviewed_by' => $admin->id,
            ]);

            return $withdrawal;
        });
    }

    /**
     * Permanent provider-side failure. Same reversal as a rejection, kept as a
     * distinct status so operator action reads differently from a send error.
     */
    public static function markFailed(Withdrawal $withdrawal, string $reason): Withdrawal
    {
        if ($withdrawal->status->isTerminal()) {
            return $withdrawal;
        }

        return DB::transaction(function () use ($withdrawal, $reason) {
            self::reverseDebit($withdrawal, "failed: {$reason}");

            $withdrawal->update([
                'status' => WithdrawalStatus::Failed,
                'rejected_reason' => $reason,
            ]);

            return $withdrawal;
        });
    }

    /**
     * Idempotent on the `wd-reversal:` key, so a retried reject / fail returns
     * the existing credit instead of paying the user twice.
     *
     * Credited even if the account is frozen — a freeze blocks debits only, and
     * refusing to return money we already took would be the wrong failure mode.
     */
    private static function reverseDebit(Withdrawal $withdrawal, string $context): void
    {
        Wallet::reverseWithdrawal(
            user: $withdrawal->user,
            amount: (string) $withdrawal->amount,
            reference: "wd-reversal:{$withdrawal->id}",
            description: "Withdrawal #{$withdrawal->id} {$context}",
        );
    }
}
