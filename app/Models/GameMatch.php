<?php

namespace App\Models;

use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use Database\Factories\GameMatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A 1v1 match between the listing's creator and a taker. Match metadata only —
 * money flows through `App\Services\Wallet` against the related listing.
 *
 * Class is named `GameMatch` (not `Match`) because `match` is a PHP reserved
 * keyword post-8.0. All references to the model — controllers, policies,
 * relations — follow the `GameMatch*` / `gameMatch()` naming convention.
 */
class GameMatch extends Model
{
    /** @use HasFactory<GameMatchFactory> */
    use HasFactory;

    protected $fillable = [
        'listing_id',
        'taker_user_id',
        'status',
        'creator_confirmed_outcome',
        'taker_confirmed_outcome',
        'winner_user_id',
        'dispute_opened_at',
        'dispute_opened_by',
        'settled_at',
        'api_response',
        'api_resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'creator_confirmed_outcome' => MatchOutcome::class,
            'taker_confirmed_outcome' => MatchOutcome::class,
            'dispute_opened_at' => 'datetime',
            'settled_at' => 'datetime',
            'api_response' => 'array',
            'api_resolved_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taker_user_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    public function disputeOpener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispute_opened_by');
    }
}
