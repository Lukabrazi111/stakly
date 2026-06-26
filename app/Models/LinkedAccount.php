<?php

namespace App\Models;

use App\Enums\LinkedAccountProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's verified external game-account link. Inserted only after the
 * verify flow completes (bio-code for chess; OAuth for FACEIT in M15+) —
 * in-flight state lives in `PendingVerification`, never here. UNIQUE
 * constraints: (user_id, provider), (provider, username), and (provider,
 * provider_user_id) — see migration for the full rationale.
 */
class LinkedAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'username',
        'provider_user_id',
        'skill_rating',
        'skill_rating_synced_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => LinkedAccountProvider::class,
            'skill_rating_synced_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
