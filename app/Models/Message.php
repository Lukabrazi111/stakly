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
 * `user_id` is null for system messages. Image attachments live on a Spatie
 * Media Library `match-attachments` collection on the private `local` disk,
 * served only through the authenticated streaming route. `attachments_json`
 * is reserved for non-binary metadata (OG link cards, evidence cards).
 */
class Message extends Model implements HasMedia
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, InteractsWithMedia;

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
     * Collection-level MIME guard is defense in depth — a path bypassing the
     * form request can't sneak a non-image onto this collection. Byte cap
     * (5 MB) is enforced by the Action layer.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS_COLLECTION)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useDisk('local');
    }

    /**
     * `nonQueued` so the thumbnail exists by the time `MessageSent` broadcasts —
     * queueing the conversion on top of the broadcast queue would race the
     * frontend. `keepOriginalImageFormat` preserves PNG transparency / WebP
     * efficiency. The re-encode through GD/Imagick also strips EXIF, keeping
     * phone-screenshot GPS data out of the bubble.
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
