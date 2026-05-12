<?php

namespace App\Enums;

/**
 * Types of ledger entries on `wallet_transactions`. Each type implies a sign
 * convention applied by `App\Services\Wallet` when computing the row's
 * `amount` column:
 *   Deposit / EscrowRelease / Payout / Fee → positive (credit)
 *   Withdrawal / EscrowHold                → negative (debit)
 */
enum WalletTransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case EscrowHold = 'escrow_hold';
    case EscrowRelease = 'escrow_release';
    case Payout = 'payout';
    case Fee = 'fee';
}
