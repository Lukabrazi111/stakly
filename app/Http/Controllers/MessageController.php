<?php

namespace App\Http\Controllers;

use App\Actions\Message\SendMessageAction;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Models\GameMatch;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * HTTP entry point for the match chat. Thin adapter — authorize the user
 * as a match participant (via `view` policy, same gate as the match
 * detail page), delegate to `SendMessageAction`, return `back()` so
 * Inertia keeps the user on the match page.
 *
 * The actual UI update on success comes via the Reverb broadcast — the
 * Echo subscriber on the match page appends the new message to local
 * state. Optimistic UI in Slice 3 hides the round-trip latency from the
 * sender themselves.
 *
 * `ThrottleRequestsException` (rate-limit) and `ValidationException`
 * (status gate, content validation) both bubble up — Laravel renders 429
 * / 422 with appropriate payloads.
 */
class MessageController extends Controller
{
    public function store(
        StoreMessageRequest $request,
        GameMatch $match,
        SendMessageAction $action,
    ): RedirectResponse {
        // 404 (not 403) — same convention as the match show page; we don't
        // leak match existence to non-participants who happened to guess an id.
        abort_if($request->user()->cannot('view', $match), 404);

        $action->handle(
            user: $request->user(),
            match: $match,
            content: $request->validated('content'),
            file: $request->file('file'),
            correlationId: $request->validated('correlation_id'),
        );

        return back();
    }

    /**
     * Authenticated streaming route for chat image attachments (M8 Phase 3
     * Slice 1). Files live on the private `local` disk and are NEVER served
     * directly — every fetch passes the same `view` policy gate as the match
     * page itself. Non-participants 404 (not 403) to match the rest of the
     * match-scoped surface.
     *
     * The `media` route param is resolved here against the message's media
     * collection rather than via route-model-binding so we can keep the
     * scope check explicit: even if a participant guesses a media id from
     * a different message (theirs or anyone else's), they hit a 404 unless
     * that media truly belongs to this `{message}` under this `{match}`.
     *
     * `?conversion=thumb` returns the ~400px preview generated at upload
     * time. Anything else (or absent) returns the original.
     *
     * Cache-Control is `private` so intermediate caches can't store the
     * image, but the user's own browser caches aggressively — chat history
     * scrolls past dozens of images and re-fetching each on every scroll
     * would be wasteful. Files are immutable (messages are append-only,
     * media never replaced), so a one-year TTL is safe.
     */
    public function attachment(
        Request $request,
        GameMatch $match,
        Message $message,
        int $media,
    ): BinaryFileResponse {
        abort_if($request->user()->cannot('view', $match), 404);
        abort_if($message->match_id !== $match->id, 404);

        $mediaItem = $message->getMedia(Message::ATTACHMENTS_COLLECTION)
            ->firstWhere('id', $media);

        abort_if($mediaItem === null, 404);

        $conversion = $request->query('conversion');
        $path = $conversion === Message::THUMBNAIL_CONVERSION
            && $mediaItem->hasGeneratedConversion(Message::THUMBNAIL_CONVERSION)
                ? $mediaItem->getPath(Message::THUMBNAIL_CONVERSION)
                : $mediaItem->getPath();

        $response = response()->file($path, [
            'Content-Type' => $mediaItem->mime_type,
            'Content-Disposition' => 'inline; filename="'.$mediaItem->file_name.'"',
        ]);

        // Symfony's BinaryFileResponse stamps `Cache-Control: public, ...` by
        // default during prepare(); we override AFTER construction so the
        // private-cache hint sticks. `immutable` is safe because messages are
        // append-only — a media id never points to different bytes.
        $response->headers->set('Cache-Control', 'private, max-age=31536000, immutable');

        return $response;
    }
}
