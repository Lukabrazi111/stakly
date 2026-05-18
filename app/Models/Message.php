<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chat message tied to one match. Append-only — dispute review depends on
 * truthful logs, so messages never mutate after insert.
 *
 * `user_id` is null for system messages (Phase 5). Human-sent text messages
 * always carry the authenticated user's id.
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    // Append-only — no updated_at column at the DB layer either.
    public const UPDATED_AT = null;

    protected $fillable = [
        'match_id',
        'user_id',
        'type',
        'content',
        'attachments_json',
    ];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'attachments_json' => 'array',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
