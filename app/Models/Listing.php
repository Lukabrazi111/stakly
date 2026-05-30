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
     * 1:1 with the match created when this listing is taken; null while still
     * Open / Cancelled / Expired. UNIQUE FK at the DB level enforces 1:1.
     */
    public function gameMatch(): HasOne
    {
        return $this->hasOne(GameMatch::class);
    }

    /**
     * Open AND not yet past expiry. Doesn't filter by the owner's
     * `is_active_mode` — owners always see their own listings.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->where('status', ListingStatus::Open)
            ->where('expires_at', '>', now());
    }

    /**
     * Publicly takeable RIGHT NOW. Extends `scopeOpen` with the owner's
     * Active Mode check — when the owner toggles Inactive, none of their
     * listings appear in the public marketplace or visitor profile views.
     */
    public function scopeOnPublicMarketplace(Builder $query): Builder
    {
        return $query
            ->open()
            ->whereHas('user', fn (Builder $q) => $q->where('is_active_mode', true));
    }
}
