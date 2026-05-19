<?php

namespace App\Actions\Message;

use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\GameMatch;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Image\Image;

/**
 * Posts a human chat message to a match's thread.
 *
 * The HTTP path always produces `Text` messages from the authenticated user.
 * System messages (Phase 5) go through a separate Action so the type is
 * never caller-supplied — no path through this Action can produce one.
 *
 * Four guards, ordered cheapest-first:
 *
 *   1. Text rate limit (10 messages / 10s per user). Every send hits this
 *      bucket regardless of whether it carries a file.
 *
 *   2. Attachment rate limit (5 uploads / 30s per user). Only attachment-
 *      bearing sends hit this stricter bucket — uploads are heavier
 *      (disk I/O, image conversion, bandwidth) than text inserts and deserve
 *      their own protection. Spam-by-alternating doesn't help: text still
 *      capped at 10/10s, uploads still capped at 5/30s.
 *
 *   3. Status gate — `Settled` and `ManualReview` matches reject sends with
 *      a `ValidationException` (becomes 422 with a friendly message). The
 *      check reads `$match->fresh()` so a stale Eloquent instance can't
 *      bypass a recent settlement. Race window between fresh + insert is
 *      sub-millisecond and worst case is one stray message — acceptable.
 *
 *   4. Participant — caller's responsibility via the controller's
 *      `view` policy gate on `GameMatch`. This Action trusts that.
 *
 * Broadcast: dispatches `MessageSent` (ShouldDispatchAfterCommit) on the
 * `match.{id}` private channel. Echo subscribers receive the payload shape
 * declared in `MessageSent::broadcastWith()`.
 */
class SendMessageAction
{
    private const MAX_MESSAGES_PER_WINDOW = 10;

    private const WINDOW_SECONDS = 10;

    private const MAX_UPLOADS_PER_WINDOW = 5;

    private const UPLOAD_WINDOW_SECONDS = 30;

    public function handle(
        User $user,
        GameMatch $match,
        ?string $content,
        ?UploadedFile $file = null,
        ?string $correlationId = null,
    ): Message {
        $this->assertNotRateLimited($user);

        if ($file !== null) {
            $this->assertUploadNotRateLimited($user);
        }

        $this->assertChatIsOpen($match->fresh());

        return DB::transaction(function () use ($user, $match, $content, $file, $correlationId) {
            $message = Message::create([
                'match_id' => $match->id,
                'user_id' => $user->id,
                'type' => MessageType::Text,
                'content' => $content,
            ]);

            if ($file !== null) {
                $this->attachImage($message, $file);
                // Ensure the in-memory model carries its media for the
                // post-commit broadcast worker — re-hydrated from DB inside
                // the job, but loading here also covers any same-request
                // resource serialization.
                $message->load('media');
            }

            // correlation_id is client-generated (UUID) and only used by the
            // sender's frontend to replace its optimistic pending message
            // with the broadcast-confirmed one. Not persisted to DB; the
            // broadcast event echoes it back in the payload.
            MessageSent::dispatch($message, $correlationId);

            return $message;
        });
    }

    /**
     * Sliding-window rate limit via Laravel's RateLimiter. Each `hit` decays
     * after WINDOW_SECONDS — 10 hits within 10s blocks the 11th.
     */
    private function assertNotRateLimited(User $user): void
    {
        $key = self::rateLimitKey($user);

        if (RateLimiter::tooManyAttempts($key, self::MAX_MESSAGES_PER_WINDOW)) {
            throw new ThrottleRequestsException(
                __('You\'re sending messages too quickly. Wait a moment and try again.')
            );
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
    }

    /**
     * Stricter sliding-window limit for attachment-bearing sends. Hit before
     * the DB transaction so a flood doesn't eat disk I/O before being rejected.
     */
    private function assertUploadNotRateLimited(User $user): void
    {
        $key = self::uploadRateLimitKey($user);

        if (RateLimiter::tooManyAttempts($key, self::MAX_UPLOADS_PER_WINDOW)) {
            throw new ThrottleRequestsException(
                __('You\'re uploading files too quickly. Wait a moment and try again.')
            );
        }

        RateLimiter::hit($key, self::UPLOAD_WINDOW_SECONDS);
    }

    /**
     * Status guard. Chat is read-only after a match resolves — Settled
     * (winner or draw refund) AND ManualReview both lock new sends. Disputed
     * stays open because the chat is the evidence record. Pending is the
     * default-open state.
     */
    private function assertChatIsOpen(GameMatch $match): void
    {
        if ($match->status === MatchStatus::Settled || $match->status === MatchStatus::ManualReview) {
            throw ValidationException::withMessages([
                'content' => __('This match is settled — chat is read-only.'),
            ]);
        }
    }

    /**
     * Pre-process the upload through Spatie\Image to re-encode the bitmap.
     * Re-encoding via GD/Imagick drops EXIF metadata as a side effect, so
     * the persisted original carries no camera GPS / device fingerprint /
     * capture-time data — the kind of detail a casual phone screenshot
     * carries and that doesn't belong in a money-chat audit trail.
     *
     * The thumbnail conversion does its own re-encode on top, so both the
     * inline preview and the original-on-lightbox land EXIF-free.
     *
     * Spatie's `addMedia` moves the temp file into the media disk on
     * `toMediaCollection`, so the temp path cleans itself up.
     */
    private function attachImage(Message $message, UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $tempPath = tempnam(sys_get_temp_dir(), 'stakly-chat-img-');
        $tempPathWithExt = $tempPath.'.'.$extension;
        rename($tempPath, $tempPathWithExt);

        // Capture dimensions before save so the frontend can set aspect-ratio
        // and avoid layout shift when chat history scrolls past unloaded
        // images. Dimensions are read from the original before the re-encode
        // — the saved file has the same dimensions (no resize on this step).
        $image = Image::load($file->getRealPath());
        $width = $image->getWidth();
        $height = $image->getHeight();
        $image->save($tempPathWithExt);

        $message
            ->addMedia($tempPathWithExt)
            ->usingFileName(Str::uuid()->toString().'.'.$extension)
            ->withCustomProperties([
                'width' => $width,
                'height' => $height,
            ])
            ->toMediaCollection(Message::ATTACHMENTS_COLLECTION);
    }

    /**
     * Exposed so tests can clear the limiter between cases without
     * duplicating the key construction.
     */
    public static function rateLimitKey(User $user): string
    {
        return 'chat:'.$user->id;
    }

    public static function uploadRateLimitKey(User $user): string
    {
        return 'chat-upload:'.$user->id;
    }
}
