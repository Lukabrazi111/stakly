<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use Database\Factories\WalletTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    /** @use HasFactory<WalletTransactionFactory> */
    use HasFactory;

    // Append-only ledger — rows never change after insert. Disables Eloquent's
    // updated_at handling; `created_at` is still managed normally.
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'related_listing_id',
        'reference_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount' => 'decimal:6',
            'balance_after' => 'decimal:6',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'related_listing_id');
    }
}
