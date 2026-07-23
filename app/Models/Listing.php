<?php

namespace App\Models;

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Enums\TimeControl;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
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
            'time_control' => TimeControl::class,
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
     *
     * M34: also hides private listings (`is_public = false`). Those reach
     * joiners only via `/lobbies/{invite_token}`. Direct `/listings/{id}`
     * URLs still resolve for users who have the link.
     */
    public function scopeOnPublicMarketplace(Builder $query): Builder
    {
        return $query
            ->open()
            ->where('is_public', true)
            ->whereHas('user', fn (Builder $q) => $q
                ->where('is_active_mode', true)
                ->whereNull('banned_at'),
            )
            ->whereCreatorNotBusyForGame();
    }

    /**
     * M37 — hide a listing while its creator is mid-match for THIS game, so the
     * board / homepage / visitor profile never offer a take that the
     * concurrency guard (`TakeListingAction`) would reject. Per game: a chess
     * listing hides while its owner is in a chess match, but their CS2 offers
     * stay up (and vice-versa). The owner still sees their own hidden listings
     * on their own profile, which uses `scopeOpen`, not this.
     *
     * Correlated anti-join: drop the listing if its creator participates (as
     * creator OR taker) in an in-progress match whose listing is the same game.
     * 1v1 matches only have a creator + taker, so no lobby-roster branch is
     * needed — team listings can't dangle (the create/join guards already block
     * a second concurrent team engagement, and a locked team listing is `Taken`,
     * not `Open`).
     */
    public function scopeWhereCreatorNotBusyForGame(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('game_matches')
                ->join('listings as busy_listing', 'busy_listing.id', '=', 'game_matches.listing_id')
                ->whereColumn('busy_listing.game', 'listings.game')
                ->whereIn('game_matches.status', MatchStatus::inProgressValues())
                ->where(fn ($p) => $p
                    ->whereColumn('game_matches.taker_user_id', 'listings.user_id')
                    ->orWhereColumn('busy_listing.user_id', 'listings.user_id'));
        });
    }
}
