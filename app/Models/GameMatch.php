<?php

namespace App\Models;

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use Database\Factories\GameMatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A 1v1 match between the listing's creator and a taker. Match metadata only —
 * money flows through `App\Services\Wallet` against the related listing.
 *
 * Named `GameMatch` because `match` is a PHP reserved keyword post-8.0; all
 * references follow the `GameMatch*` / `gameMatch()` convention.
 */
class GameMatch extends Model
{
    /** @use HasFactory<GameMatchFactory> */
    use HasFactory;

    /**
     * `side` values on `match_provider_snapshots`. String constants (not an
     * enum) because no business logic hangs off the value. Promote to enum if
     * a future team-match shape introduces additional roles.
     */
    public const SIDE_CREATOR = 'creator';

    public const SIDE_TAKER = 'taker';

    protected $fillable = [
        'listing_id',
        'taker_user_id',
        'status',
        'winner_user_id',
        'dispute_opened_at',
        'dispute_opened_by',
        'settled_at',
        'api_response',
        'api_resolved_at',
        'cancelled_at',
        'cancellation_requested_by',
        'cancellation_requested_at',
        'cancellation_rejected_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'dispute_opened_at' => 'datetime',
            'settled_at' => 'datetime',
            'api_response' => 'array',
            'api_resolved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'cancellation_rejected_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'match_id');
    }

    /**
     * Snapshot of each player's verified external accounts at match creation.
     * Populated by `TakeListingAction`; read by the smart-link enrichment jobs,
     * auto-fetch jobs, and `SettleFromCardAction` for winner-to-user mapping.
     */
    public function providerSnapshots(): HasMany
    {
        return $this->hasMany(MatchProviderSnapshot::class, 'match_id');
    }

    /**
     * Append-only admin resolution audit. Ordered oldest → newest so the
     * chronology renders top-down.
     */
    public function adminResolutions(): HasMany
    {
        return $this->hasMany(MatchAdminResolution::class, 'match_id')->oldest();
    }

    /**
     * Append-only auto-fetch attempt audit. Ordered oldest → newest so the
     * Filament timeline reads chronologically.
     */
    public function autoFetchAttempts(): HasMany
    {
        return $this->hasMany(MatchAutoFetchAttempt::class, 'match_id')->oldest();
    }

    /**
     * "What username did the {side} player verify for {provider} at match
     * creation?" Returns null when no snapshot exists (this side isn't linked
     * for this provider). Job handlers `loadMissing('providerSnapshots')` at
     * entry; falls back to lazy load otherwise.
     */
    public function snapshotUsername(string $side, LinkedAccountProvider $provider): ?string
    {
        return $this->providerSnapshots
            ->first(
                fn (MatchProviderSnapshot $snapshot) => $snapshot->side === $side
                    && $snapshot->provider === $provider,
            )
            ?->username;
    }

    /**
     * Matches where $userId is creator (via listing.user_id) OR taker.
     */
    public function scopeForParticipant(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->where('taker_user_id', $userId)
                ->orWhereHas('listing', fn (Builder $inner) => $inner->where('user_id', $userId));
        });
    }
}
