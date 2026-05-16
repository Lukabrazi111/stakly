<?php

namespace App\Models;

use App\Enums\Game;
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
     * public marketplace board and anywhere "what's takeable right now"
     * is the question. Paused listings are explicitly excluded.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->where('status', ListingStatus::Open)
            ->where('expires_at', '>', now());
    }

    /**
     * Listings the owner is still actively managing — Open OR Paused, not
     * yet expired. Used in the owner's view of their own profile so they
     * can see (and resume) listings they've paused. Public marketplace and
     * other people's profile views stay on `scopeOpen`.
     */
    public function scopeOpenOrPaused(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [ListingStatus::Open, ListingStatus::Paused])
            ->where('expires_at', '>', now());
    }
}
