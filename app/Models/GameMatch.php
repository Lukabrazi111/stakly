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
 * Class is named `GameMatch` (not `Match`) because `match` is a PHP reserved
 * keyword post-8.0. All references to the model — controllers, policies,
 * relations — follow the `GameMatch*` / `gameMatch()` naming convention.
 */
class GameMatch extends Model
{
    /** @use HasFactory<GameMatchFactory> */
    use HasFactory;

    /**
     * Two valid `side` values on `match_provider_snapshots`. Kept as string
     * constants (not a PHP enum) because there's no business logic on the
     * value beyond "creator vs taker" and we don't want to import an enum
     * just to read a single snapshot row. Promote to an enum if a future
     * team-match shape introduces additional roles.
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

    /**
     * Chat history (M8 Phase 2). Append-only — ordered by id (= insert order
     * thanks to bigserial). Composite `(match_id, id)` index on `messages`
     * keeps the per-match lookup cheap.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'match_id');
    }

    /**
     * Snapshot of each player's verified external accounts at match
     * creation time (M8 Phase 4). Populated by `TakeListingAction`. Read by
     * the smart-link enrichment jobs + auto-fetch jobs + `SettleFromCardAction`
     * (M16) for winner-to-user mapping. See `App\Models\MatchProviderSnapshot`.
     */
    public function providerSnapshots(): HasMany
    {
        return $this->hasMany(MatchProviderSnapshot::class, 'match_id');
    }

    /**
     * Admin resolution audit rows (M12 Phase 2). Append-only; one row per
     * admin click on Settle to Creator / Settle to Taker / Settle as Draw.
     * Ordered oldest → newest so the chronology renders top-down.
     */
    public function adminResolutions(): HasMany
    {
        return $this->hasMany(MatchAdminResolution::class, 'match_id')->oldest();
    }

    /**
     * Auto-fetch attempt audit rows (M14 Phase 1). Append-only; one row
     * per call into the pipeline (skip rows from `DispatchAutoFetchAction`
     * + outcome rows from the per-platform `AutoFetch*GameJob`s). Ordered
     * oldest → newest so the Filament timeline reads chronologically.
     */
    public function autoFetchAttempts(): HasMany
    {
        return $this->hasMany(MatchAutoFetchAttempt::class, 'match_id')->oldest();
    }

    /**
     * Lookup helper for the smart-link jobs: "what username did the
     * {side} player verify for {provider} at match creation?" Returns
     * null when no snapshot exists for that slot — caller treats that as
     * "this side isn't linked for this provider."
     *
     * Reads from the loaded `providerSnapshots` collection if it's
     * already eager-loaded; otherwise triggers a lazy load (one query).
     * Job handlers call `loadMissing('providerSnapshots')` at entry to
     * keep call-site code clean.
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
     * Matches where $userId is either the creator (via listing.user_id) or
     * the taker. Used by the /matches index page so a player sees both sides
     * of their participation in one list.
     */
    public function scopeForParticipant(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->where('taker_user_id', $userId)
                ->orWhereHas('listing', fn (Builder $inner) => $inner->where('user_id', $userId));
        });
    }
}
