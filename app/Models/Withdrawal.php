<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Database\Factories\WithdrawalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's cash-out request. Written only by `App\Services\Withdrawals` —
 * every state change there is paired with the matching `App\Services\Wallet`
 * ledger entry, so this row and the ledger can't drift.
 */
class Withdrawal extends Model
{
    /** @use HasFactory<WithdrawalFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'platform_fee',
        'network_fee',
        'destination_address',
        'status',
        'debit_transaction_id',
        'provider',
        'provider_payout_id',
        'tx_hash',
        'rejected_reason',
        'reviewed_by',
        'hold_until',
    ];

    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'amount' => 'decimal:6',
            'platform_fee' => 'decimal:6',
            'network_fee' => 'decimal:6',
            'hold_until' => 'immutable_datetime',
        ];
    }

    /**
     * Still inside its new-address cooldown (M9 Phase 0e) — debited, but the
     * payout hasn't been handed to the provider yet.
     */
    public function isHeld(): bool
    {
        return $this->hold_until !== null
            && $this->hold_until->isFuture()
            && ! $this->status->isTerminal();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function debitTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'debit_transaction_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * What actually lands at the destination address: gross minus Stakly's
     * margin. Network gas is deducted by the provider from this figure, so the
     * final on-chain credit is this minus `network_fee`.
     */
    public function netAmount(): string
    {
        return bcsub((string) $this->amount, (string) $this->platform_fee, 6);
    }
}
