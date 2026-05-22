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
            ...self::gameCardEntries($message),
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
     * Slice 2 link cards. The raw `attachments_json` stores `image_path`
     * (relative path on the private `local` disk); the frontend wants
     * `image_url` (the authenticated route the browser can fetch). The
     * translation happens here so the frontend has a uniform shape and
     * the storage side stays disk-relative.
     *
     * Same `absolute: false` reasoning as `imageEntries` — the queue
     * worker that runs `MessageSent::broadcastWith` and the web request
     * that runs `MessageResource::toArray` both produce these payloads;
     * relative URLs sidestep any `APP_URL` ↔ request-host drift.
     *
     * @return list<array<string, mixed>>
     */
    private static function linkEntries(Message $message): array
    {
        $raw = $message->attachments_json;

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $entries = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) !== 'link') {
                continue;
            }

            $imageUrl = null;

            if (! empty($item['image_path'])) {
                $filename = basename((string) $item['image_path']);
                $imageUrl = route(
                    'link-images.show',
                    ['filename' => $filename],
                    absolute: false,
                );
            }

            $entries[] = [
                'type' => 'link',
                'url' => $item['url'] ?? null,
                'canonical_url' => $item['canonical_url'] ?? null,
                'title' => $item['title'] ?? null,
                'description' => $item['description'] ?? null,
                'site_name' => $item['site_name'] ?? null,
                'image_url' => $imageUrl,
            ];
        }

        return $entries;
    }

    /**
     * Phase 4 verified-game cards. The raw `attachments_json` entry written
     * by `FetchLichessGameMetadataJob` (paste path) or `AutoFetchLichessGameJob`
     * (auto-fetch path) is already in API shape — no URL rewriting needed —
     * so this filter is a near-passthrough. Any new fields surface to the
     * frontend by adding them here; missing fields default to `null` so an
     * older persisted entry without a newer field doesn't 500 the page.
     *
     * @return list<array<string, mixed>>
     */
    private static function gameCardEntries(Message $message): array
    {
        $raw = $message->attachments_json;

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $entries = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) !== 'game_card') {
                continue;
            }

            $entries[] = [
                'type' => 'game_card',
                'provider' => $item['provider'] ?? null,
                'source' => $item['source'] ?? null,
                'game_id' => $item['game_id'] ?? null,
                'url' => $item['url'] ?? null,
                'verified' => (bool) ($item['verified'] ?? false),
                'white_username' => $item['white_username'] ?? null,
                'black_username' => $item['black_username'] ?? null,
                'winner_color' => $item['winner_color'] ?? null,
                'winner_username' => $item['winner_username'] ?? null,
                'status' => $item['status'] ?? null,
                'speed' => $item['speed'] ?? null,
                'variant' => $item['variant'] ?? null,
                'rated' => (bool) ($item['rated'] ?? false),
                'played_at' => $item['played_at'] ?? null,
            ];
        }

        return $entries;
    }
}
