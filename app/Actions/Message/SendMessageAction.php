<?php

namespace App\Actions\Message;

use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Jobs\FetchLichessGameMetadataJob;
use App\Jobs\FetchLinkMetadataJob;
use App\Models\GameMatch;
use App\Models\Message;
use App\Models\User;
use App\Support\SsrfGuard;
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

    /**
     * Hard cap on how many URLs we'll unfurl per message. The fetch job
     * is queued so the response stays fast, but every extra URL is
     * another outbound HTTP fetch + possible image proxy + persisted
     * card. Five is generous for normal chat and still bounds abuse.
     */
    private const MAX_URLS_PER_MESSAGE = 5;

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

            // Phase 3 Slice 2 + Phase 4 paste path — queue URL enrichment.
            // Lichess game URLs branch to FetchLichessGameMetadataJob
            // (verified evidence card with snapshot cross-check). Everything
            // else stays on the generic OG fetcher. A single message can
            // dispatch BOTH job types if it carries a mix.
            //
            // Each job runs ShouldQueueAfterCommit so it sees the inserted
            // row; on success each re-dispatches MessageSent so the frontend
            // can swap the plain-link bubble for the enriched one in place.
            if ($content !== null) {
                $this->dispatchUrlEnrichmentJobs($message, $content);
            }

            return $message;
        });
    }

    /**
     * Partition the URLs in $content into (Lichess game URLs → verified
     * card pipeline) vs (everything else → generic OG fetcher), then
     * dispatch each pipeline if it has work.
     */
    private function dispatchUrlEnrichmentJobs(Message $message, string $content): void
    {
        $urls = self::extractLinkUrls($content);

        if (count($urls) === 0) {
            return;
        }

        $lichessGameIds = [];
        $otherUrls = [];

        foreach ($urls as $url) {
            $gameId = self::extractLichessGameId($url);

            if ($gameId !== null) {
                $lichessGameIds[] = $gameId;
            } else {
                $otherUrls[] = $url;
            }
        }

        if (count($otherUrls) > 0) {
            FetchLinkMetadataJob::dispatch($message, $otherUrls);
        }

        // One job per game ID — paste path is one-card-per-game, and the
        // outer URL list is already capped at MAX_URLS_PER_MESSAGE so the
        // fan-out is bounded.
        foreach (array_unique($lichessGameIds) as $gameId) {
            FetchLichessGameMetadataJob::dispatch($message, $gameId);
        }
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

    /**
     * Pull http(s) URLs out of message content for the OG metadata
     * fetcher. Returns a de-duplicated, capped list of URLs that passed
     * the cheap pre-flight SSRF check (raw `http://10.0.0.1/`-style
     * literals never make it to the job queue).
     *
     * Trailing punctuation (.,!?;:'")] etc.) is stripped because chat
     * sentences put URLs next to punctuation — `look at https://foo.com.`
     * should detect `https://foo.com`, not `https://foo.com.` which would
     * 404 at the provider.
     *
     * Exposed as a static helper (rather than buried in the dispatch
     * block) so the URL-detection rules are testable in isolation —
     * Slice 2 tests cover the regex without spinning a queue worker.
     *
     * @return list<string>
     */
    public static function extractLinkUrls(string $content): array
    {
        preg_match_all('#https?://[^\s<>"\']+#i', $content, $matches);

        $urls = [];

        foreach ($matches[0] as $raw) {
            $url = rtrim($raw, ".,;:!?'\"()[]{}");

            // Cheap pre-flight only — full DNS-based SSRF check runs inside
            // the queued worker before each outbound fetch. Doing the DNS
            // lookup here would stall the request behind one resolver call
            // per URL.
            if (! SsrfGuard::isPlausiblySafe($url)) {
                continue;
            }

            if (in_array($url, $urls, true)) {
                continue;
            }

            $urls[] = $url;

            if (count($urls) >= self::MAX_URLS_PER_MESSAGE) {
                break;
            }
        }

        return $urls;
    }

    /**
     * If $url is a Lichess game URL, return the 8–12 char game ID; otherwise
     * null. Lichess uses single-segment paths for game IDs
     * (`lichess.org/{id}`), an optional `/embed/{id}` wrapper for embeds,
     * and post-id suffixes for color (`/white`) and move anchors (`#5`).
     *
     * The same length window catches a handful of reserved Lichess paths
     * (`training`, `analysis`, `streamer`, `practice`, `tournament`). An
     * explicit denylist excludes them — without it, `lichess.org/training`
     * would be misrouted to the game-card pipeline.
     */
    public static function extractLichessGameId(string $url): ?string
    {
        // Delimiter is `~` (not `#`) because the pattern contains a literal
        // `#` inside the trailing character class — `#`-delimited would
        // close the regex early and silently misparse.
        if (! preg_match(
            '~^https?://(?:www\.)?lichess\.org/(?:embed/)?([a-zA-Z0-9]{8,12})(?:[/?#]|$)~i',
            $url,
            $matches,
        )) {
            return null;
        }

        $candidate = $matches[1];

        static $reservedPaths = [
            'training',
            'analysis',
            'streamer',
            'practice',
            'tournament',
        ];

        if (in_array(strtolower($candidate), $reservedPaths, true)) {
            return null;
        }

        return $candidate;
    }
}
