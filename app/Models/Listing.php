<?php

namespace App\Models;

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\TimeControl;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'game',
        'platform',
        'stake_amount',
        'skill_min',
        'skill_max',
        'time_control',
        'region',
        'language',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'game' => Game::class,
            'platform' => LinkedAccountProvider::class,
            'stake_amount' => 'decimal:2',
            'skill_min' => 'integer',
            'skill_max' => 'integer',
            'time_control' => AsEnumCollection::of(TimeControl::class),
            'language' => 'array',
            'expires_at' => 'datetime',
            'status' => ListingStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 1:1 with the match created when this listing is taken. Null while
     * the listing is still Open / Cancelled / Expired. UNIQUE FK at the DB
     * level enforces the 1:1 relationship.
     */
    public function gameMatch(): HasOne
    {
        return $this->hasOne(GameMatch::class);
    }

    /**
     * Listings that are open AND not yet past their expiry. Used by the
     * owner's view of their own listings (profile, /listings/mine) and as
     * the foundation for `scopeOnPublicMarketplace`. Doesn't filter by the
     * owner's `is_active_mode` — owners always see their own listings.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->where('status', ListingStatus::Open)
            ->where('expires_at', '>', now());
    }

    /**
     * Listings that are publicly takeable RIGHT NOW. Extends `scopeOpen`
     * with a check that the owner's global Active Mode is on. When the
     * owner toggles Inactive on `/listings/mine`, none of their listings
     * appear here — they're hidden from both the public marketplace and
     * visitor views of their profile.
     *
     * Used by `ListingController::index`, `HomeController::index`, and
     * `UserController::show` (visitor branch). `/listings/mine` and the
     * profile owner-view bypass this scope since the owner should always
     * see their own listings regardless of their active mode.
     */
    public function scopeOnPublicMarketplace(Builder $query): Builder
    {
        return $query
            ->open()
            ->whereHas('user', fn (Builder $q) => $q->where('is_active_mode', true));
    }
}
