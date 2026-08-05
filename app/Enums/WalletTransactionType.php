<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Types of ledger entries on `wallet_transactions`. Each type implies a sign
 * convention applied by `App\Services\Wallet` when computing the row's
 * `amount` column:
 *   Deposit / EscrowRelease / Payout / Fee / WithdrawalReversal → positive (credit)
 *   Withdrawal / EscrowHold                                     → negative (debit)
 *
 * `HasColor` + `HasLabel` are read by Filament's TextColumn::badge() and
 * infolist TextEntry to auto-style each case — admin wallet ledger (M31) +
 * future surfaces benefit without per-callsite color maps.
 */
enum WalletTransactionType: string implements HasColor, HasLabel
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case EscrowHold = 'escrow_hold';
    case EscrowRelease = 'escrow_release';
    case Payout = 'payout';
    case Fee = 'fee';

    /**
     * Credit-back when a withdrawal is rejected by an admin or fails at the
     * provider. Kept distinct from `Deposit` so platform revenue reporting and
     * the deposit-volume figures don't count reversals as new money in.
     */
    case WithdrawalReversal = 'withdrawal_reversal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::EscrowHold => 'Escrow hold',
            self::EscrowRelease => 'Escrow release',
            self::Payout => 'Payout',
            self::Fee => 'Fee',
            self::WithdrawalReversal => 'Withdrawal reversal',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Deposit, self::Payout => 'success',
            self::Withdrawal => 'danger',
            self::EscrowHold => 'warning',
            self::EscrowRelease, self::WithdrawalReversal => 'info',
            self::Fee => 'gray',
        };
    }
}
