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
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'team_size',
        'creator_side',
        'lobby_state',
        'lobby_ready_check_deadline',
        'is_public',
        'invite_token',
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
            'team_size' => 'integer',
            'is_public' => 'boolean',
            'lobby_ready_check_deadline' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 1:1 with the match created when this listing is taken; null while still
     * Open / Cancelled / Expired. UNIQUE FK at the DB level enforces 1:1.
     * For team-play listings (M34) the match row is created early in
     * `LobbyFilling` state at listing creation, so this is non-null from day 1.
     */
    public function gameMatch(): HasOne
    {
        return $this->hasOne(GameMatch::class);
    }

    /**
     * Soft-join lobby participation rows (M34). Empty for `team_size = 1`
     * (chess) listings — the existing TakeListingAction flow doesn't create
     * lobby rows. The `kicked_at IS NULL` scope lives in the LobbyParticipant
     * model as `live()` for callers that want only active participants.
     */
    public function lobbyParticipants(): HasMany
    {
        return $this->hasMany(LobbyParticipant::class);
    }

    /**
     * True when this listing uses the M34 lobby flow rather than the chess
     * `TakeListingAction` flow.
     */
    public function isTeamPlay(): bool
    {
        return $this->team_size > 1;
    }

    /**
     * Lobby owner = listing creator (M34 P0). The owner is just another
     * participant in `lobby_participants`; this accessor is purely a naming
     * helper for the kick + cancel-on-leave guards in P1.
     */
    public function lobbyOwner(): BelongsTo
    {
        return $this->user();
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
     * Active Mode check + the M30 ban gate — when the owner is suspended
     * OR has toggled Inactive, none of their listings appear in the public
     * marketplace or visitor profile views.
     */
    public function scopeOnPublicMarketplace(Builder $query): Builder
    {
        return $query
            ->open()
            ->whereHas('user', fn (Builder $q) => $q
                ->where('is_active_mode', true)
                ->whereNull('banned_at'),
            );
    }
}
