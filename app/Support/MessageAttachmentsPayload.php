<?php

namespace App\Support;

use App\Models\Message;

/**
 * Single source of truth for the `attachments` array shape that
 * `MessageResource` ships on initial Inertia load AND
 * `MessageSent::broadcastWith` ships on live broadcast. The frontend uses one
 * `ChatMessage` type to render both, so divergence here surfaces as silent
 * broadcast-path-only rendering bugs.
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
            ...self::disputePromptEntries($message),
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

            // Relative URLs sidestep APP_URL ↔ request-host drift: the web
            // request uses the request host, but the queue worker that runs
            // `MessageSent::broadcastWith` uses `config('app.url')`. If they
            // disagree, absolute URLs built in the worker point somewhere the
            // browser can't reach.
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
     * Link cards. Storage holds `image_path` (private disk); the frontend
     * gets `image_url` (the authenticated streaming route). Translation
     * lives here so storage stays disk-relative. Same `absolute: false`
     * reasoning as `imageEntries`.
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
     * Verified-game cards. Near-passthrough — raw entries from the metadata
     * jobs are already in API shape. Missing fields default to `null` so an
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

    /**
     * Dispute-prompt markers. Written by `ResolveDisputeAction::flipToManualReview`
     * alongside a "submit evidence" system message. Carries no fields beyond
     * the type discriminator — signals React `SystemBubble` to render the
     * warning variant.
     *
     * @return list<array<string, mixed>>
     */
    private static function disputePromptEntries(Message $message): array
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

            if (($item['type'] ?? null) !== 'dispute_prompt') {
                continue;
            }

            $entries[] = ['type' => 'dispute_prompt'];
        }

        return $entries;
    }
}
