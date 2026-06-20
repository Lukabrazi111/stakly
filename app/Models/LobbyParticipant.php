<?php

namespace App\Models;

use Database\Factories\LobbyParticipantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One soft-join row per (listing, user, lifetime). Live participation has
 * `kicked_at IS NULL`; kicked rows stay as audit + the 5-min same-listing
 * rejoin cooldown anchor. See migration for the full rationale.
 *
 * `is_ready = true` means the user clicked Ready AND `Wallet::hold` has
 * fired — `stake_held_at` is the audit-trail timestamp of when escrow
 * posted. Invariant: `is_ready` and `stake_held_at` flip together.
 */
class LobbyParticipant extends Model
{
    /** @use HasFactory<LobbyParticipantFactory> */
    use HasFactory;

    public const SIDE_A = 'a';

    public const SIDE_B = 'b';

    protected $fillable = [
        'listing_id',
        'user_id',
        'side',
        'slot_index',
        'is_ready',
        'stake_held_at',
        'kicked_at',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'is_ready' => 'boolean',
            'stake_held_at' => 'datetime',
            'kicked_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Live = not kicked. Soft-joined OR Ready'd — anything still in play.
     * Mirrors the partial unique indexes' `WHERE kicked_at IS NULL` predicate.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('kicked_at');
    }

    /**
     * Kicked rows still inside their 5-min same-listing rejoin cooldown.
     * Read by `JoinLobbyAction` to gate rejoin attempts.
     */
    public function scopeWithinKickCooldown(Builder $query): Builder
    {
        return $query
            ->whereNotNull('kicked_at')
            ->where('kicked_at', '>', now()->subMinutes(5));
    }
}
