<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a `withdrawals` row (M9 Phase 0b).
 *
 * There is no `Approved` state: the anti-abuse hold lives on the PAYOUT
 * (see `App\Services\PayoutClearance`), not on the withdrawal, so by the time
 * a player can request one the money has already cleared and there's nothing
 * left to gate. A withdrawal goes straight out. `Approved` returns if Phase
 * 2.3 mass-payout batching lands, which needs a queued-but-unsent state.
 *
 * `Rejected` and `Failed` both reverse the debit; they're distinct so admin
 * intervention is legible apart from a provider-side send failure.
 */
enum WithdrawalStatus: string implements HasColor, HasLabel
{
    /** Requested; balance already debited; payout job queued. */
    case Pending = 'pending';

    /** Handed to the gateway; awaiting on-chain confirmation. */
    case Sending = 'sending';

    /** Provider confirmed the payout. Terminal. */
    case Completed = 'completed';

    /** Admin refused it; balance credited back. Terminal. */
    case Rejected = 'rejected';

    /** Provider send failed permanently; balance credited back. Terminal. */
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sending => 'Sending',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Sending => 'info',
            self::Completed => 'success',
            self::Rejected, self::Failed => 'danger',
        };
    }

    /**
     * Terminal states never transition again — the money has either left or
     * been credited back.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Rejected, self::Failed => true,
            self::Pending, self::Sending => false,
        };
    }

    /**
     * States where the user's debit has been reversed.
     */
    public function isReversed(): bool
    {
        return $this === self::Rejected || $this === self::Failed;
    }
}
