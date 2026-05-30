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
 * HTTP entry point for the match chat. UI updates come via the Reverb
 * broadcast, not this response.
 */
class MessageController extends Controller
{
    public function store(
        StoreMessageRequest $request,
        GameMatch $match,
        SendMessageAction $action,
    ): RedirectResponse {
        // 404 (not 403) — don't leak match existence to non-participants
        // who happened to guess an id.
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
     * Authenticated streaming route for chat image attachments. Files live
     * on the private `local` disk and are NEVER served directly — every
     * fetch passes the `view` policy gate. Non-participants 404 (not 403)
     * to match the rest of the match-scoped surface.
     *
     * `media` is resolved manually (not route-model-bound) so we can scope
     * it to this message under this match — guessing a media id from a
     * different message 404s.
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

        // Symfony's BinaryFileResponse stamps `Cache-Control: public, ...`
        // during prepare(); override AFTER construction so `private` sticks.
        // `immutable` is safe — messages are append-only, a media id never
        // points to different bytes.
        $response->headers->set('Cache-Control', 'private, max-age=31536000, immutable');

        return $response;
    }
}
