<?php

namespace App\Models;

use App\Enums\LinkedAccountProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's verified external game-account link (M18 Phase 3 prep — replaces
 * the inline `chess_com_*` / `lichess_*` columns that used to live on `users`).
 *
 * Inserted only after the bio-code flow completes — in-flight state lives
 * in `PendingVerification`, never here. Two UNIQUE constraints on the
 * table (see migration): (user_id, provider) and (provider, username).
 */
class LinkedAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'username',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => LinkedAccountProvider::class,
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
