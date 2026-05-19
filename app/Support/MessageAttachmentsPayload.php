<?php

namespace App\Support;

use App\Models\Message;

/**
 * Single source of truth for the `attachments` array shape that
 * `MessageResource` ships on initial Inertia load AND `MessageSent::broadcastWith`
 * ships on live broadcast. Keeping the two paths in lockstep matters because
 * the frontend uses one `ChatMessage` type to render both — a divergence
 * would surface as silent rendering bugs only on the broadcast path.
 *
 * Phase 3 Slice 1 emits `type=image` entries from the message's Spatie
 * Media Library collection. Slice 2 will append `type=link` entries from
 * `attachments_json` (queued OG metadata fetch). The unified array means
 * the frontend renders attachments in insertion order without caring
 * about source.
 *
 * URL generation lives here, not on the model, so `App\Models\Message`
 * stays free of routing concerns.
 */
class MessageAttachmentsPayload
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forMessage(Message $message): array
    {
        return [
            ...self::imageEntries($message),
            ...self::linkEntries($message),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function imageEntries(Message $message): array
    {
        $entries = [];

        foreach ($message->getMedia(Message::ATTACHMENTS_COLLECTION) as $media) {
            $base = [
                'match' => $message->match_id,
                'message' => $message->id,
                'media' => $media->id,
            ];

            // Relative URLs (absolute: false) — the resource path is generated
            // in two contexts: (a) the initial Inertia load inside a web
            // request, where `route()` uses the request host; (b) the queue
            // worker that runs `MessageSent::broadcastWith`, where `route()`
            // uses `config('app.url')`. If APP_URL drifts from the browser's
            // origin (mismatched `APP_PORT`, prod behind a proxy, etc.), the
            // worker-built absolute URL points somewhere the browser can't
            // reach and images break post-broadcast. Relative URLs are
            // resolved by the browser against its current origin in both
            // cases — no APP_URL coupling, no broken-image-after-send race.
            $url = route('matches.messages.attachment', $base, absolute: false);

            $entries[] = [
                'type' => 'image',
                'media_id' => $media->id,
                'name' => $media->file_name,
                'mime' => $media->mime_type,
                'size' => $media->size,
                'width' => $media->getCustomProperty('width'),
                'height' => $media->getCustomProperty('height'),
                'url' => $url,
                'thumb_url' => $url.'?conversion='.Message::THUMBNAIL_CONVERSION,
            ];
        }

        return $entries;
    }

    /**
     * Phase 4 link cards. `attachments_json` is reserved as `null` until the
     * OG fetcher / verified-game-evidence pipeline lands in Slice 2 / Phase 4.
     * For Slice 1 this always returns an empty list.
     *
     * @return list<array<string, mixed>>
     */
    private static function linkEntries(Message $message): array
    {
        $raw = $message->attachments_json;

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        return array_values($raw);
    }
}
