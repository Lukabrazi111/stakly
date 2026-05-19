<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Chat message tied to one match. Append-only — dispute review depends on
 * truthful logs, so messages never mutate after insert.
 *
 * `user_id` is null for system messages (Phase 5). Human-sent text messages
 * always carry the authenticated user's id.
 *
 * Image attachments (Phase 3 Slice 1) live on a Spatie Media Library
 * `match-attachments` collection. Files are written to the private `local`
 * disk and served only through the authenticated streaming route — see
 * `MessageController::attachment`. The `attachments_json` jsonb column is
 * reserved for non-binary metadata (Phase 3 Slice 2 OG link cards, Phase 4
 * verified-game-evidence cards).
 */
class Message extends Model implements HasMedia
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, InteractsWithMedia;

    // Append-only — no updated_at column at the DB layer either.
    public const UPDATED_AT = null;

    public const ATTACHMENTS_COLLECTION = 'match-attachments';

    public const THUMBNAIL_CONVERSION = 'thumb';

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

    /**
     * Image attachments are constrained to web-safe formats. The Action layer
     * enforces the 5 MB byte cap; the collection-level MIME guard is defense
     * in depth so a path that bypasses the form request can't sneak a non-image
     * onto this collection.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS_COLLECTION)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useDisk('local');
    }

    /**
     * Single ~400px contain-fit thumbnail rendered inline in the chat list;
     * the original is reserved for the lightbox click-through. `nonQueued`
     * so the thumbnail exists by the time the broadcast fires — the queue
     * worker already handles the `MessageSent` event itself, queueing the
     * conversion on top would race the broadcast to the frontend.
     *
     * `keepOriginalImageFormat` keeps PNG transparency / WebP efficiency
     * intact rather than collapsing every thumbnail to JPEG. Spatie's
     * conversion pipeline re-encodes through GD/Imagick, which drops EXIF
     * metadata as a side effect — phone-screenshot GPS data stays out of
     * what other participants see in the bubble.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion(self::THUMBNAIL_CONVERSION)
            ->fit(Fit::Contain, 400, 400)
            ->keepOriginalImageFormat()
            ->nonQueued()
            ->performOnCollections(self::ATTACHMENTS_COLLECTION);
    }
}
