<?php

namespace App\Models;

use App\Enums\LinkedAccountProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transient pending bio-code state for linked-account verification. At most
 * one row per user — `RequestLinkVerificationAction` upserts on (user_id) so
 * starting a fresh verification overwrites any in-flight one. `code` is
 * plaintext (we display it back to the user).
 */
class PendingVerification extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'username',
        'code',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => LinkedAccountProvider::class,
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
