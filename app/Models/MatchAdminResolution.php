<?php

namespace App\Models;

use App\Enums\MatchAdminResolutionAction;
use Database\Factories\MatchAdminResolutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only admin resolution audit row. Written from
 * `AdminSettleToWinnerAction` and `AdminSettleDrawAction` inside the same DB
 * transaction as the underlying Wallet payout / refund so the audit trail
 * can never desync from the money movement.
 */
class MatchAdminResolution extends Model
{
    /** @use HasFactory<MatchAdminResolutionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'match_id',
        'admin_user_id',
        'action',
        'winner_user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'action' => MatchAdminResolutionAction::class,
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }
}
