<?php

namespace App\Actions\Message;

use App\Actions\GameMatch\DispatchAutoFetchAction;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Jobs\FetchChessComGameMetadataJob;
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
 * Posts a human chat message to a match's thread. The HTTP path always produces `Text`
 * messages — system messages go through a separate Action so the type is never caller-supplied.
 *
 * Guards: text rate limit, attachment rate limit (separate stricter bucket), status gate
 * (`Settled` / `ManualReview` reject), participant check (controller policy). Broadcasts
 * `MessageSent` after commit on the `match.{id}` channel.
 */
class SendMessageAction
{
    private const MAX_MESSAGES_PER_WINDOW = 10;

    private const WINDOW_SECONDS = 10;

    private const MAX_UPLOADS_PER_WINDOW = 5;

    private const UPLOAD_WINDOW_SECONDS = 30;

    /**
     * Each URL = outbound fetch + possible image proxy + persisted card. Five bounds abuse.
     */
    private const MAX_URLS_PER_MESSAGE = 5;

    public function __construct(
        private readonly DispatchAutoFetchAction $dispatchAutoFetch,
    ) {}

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

        $message = DB::transaction(function () use ($user, $match, $content, $file, $correlationId) {
            $message = Message::create([
                'match_id' => $match->id,
                'user_id' => $user->id,
                'type' => MessageType::Text,
                'content' => $content,
            ]);

            if ($file !== null) {
                self::attachFileTo($message, $file);
                // Ensure in-memory model carries media for the post-commit broadcast worker.
                $message->load('media');
            }

            // correlation_id is client-generated; broadcast event echoes it back so the
            // sender's frontend can replace its optimistic pending message.
            MessageSent::dispatch($message, $correlationId);

            if ($content !== null) {
                $this->dispatchUrlEnrichmentJobs($message, $content);
            }

            return $message;
        });

        // Chat-send trigger for auto-fetch. ShouldBeUnique on the job dedupes back-to-back sends;
        // action gates on `status === Pending` so closed matches don't re-trigger the API.
        $this->dispatchAutoFetch->handle($match);

        return $message;
    }

    /**
     * Partitions URLs into Lichess-game / chess.com-game / generic buckets and fans out
     * to the matching metadata job per bucket.
     */
    private function dispatchUrlEnrichmentJobs(Message $message, string $content): void
    {
        $urls = self::extractLinkUrls($content);

        if (count($urls) === 0) {
            return;
        }

        $lichessGameIds = [];
        $chessComGameUrls = [];
        $otherUrls = [];

        foreach ($urls as $url) {
            $lichessId = self::extractLichessGameId($url);
            if ($lichessId !== null) {
                $lichessGameIds[] = $lichessId;

                continue;
            }

            $chessComUrl = self::extractChessComGameUrl($url);
            if ($chessComUrl !== null) {
                $chessComGameUrls[] = $chessComUrl;

                continue;
            }

            $otherUrls[] = $url;
        }

        if (count($otherUrls) > 0) {
            FetchLinkMetadataJob::dispatch($message, $otherUrls);
        }

        // Outer URL list is capped at MAX_URLS_PER_MESSAGE so the fan-out is bounded.
        foreach (array_unique($lichessGameIds) as $gameId) {
            FetchLichessGameMetadataJob::dispatch($message, $gameId);
        }

        foreach (array_unique($chessComGameUrls) as $url) {
            FetchChessComGameMetadataJob::dispatch($message, $url);
        }
    }

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
     * Hit before the DB transaction so a flood doesn't eat disk I/O before being rejected.
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
     * Settled / ManualReview / Cancelled lock new sends. Disputed stays open
     * because the chat is the evidence record.
     */
    private function assertChatIsOpen(GameMatch $match): void
    {
        $closedStatuses = [
            MatchStatus::Settled,
            MatchStatus::ManualReview,
            MatchStatus::Cancelled,
        ];

        if (in_array($match->status, $closedStatuses, strict: true)) {
            throw ValidationException::withMessages([
                'content' => __('This match has ended — chat is read-only.'),
            ]);
        }
    }

    /**
     * Branch attach by mime: images go through the EXIF-strip + dimension
     * pipeline, everything else (PDFs) gets a direct store. Shared between
     * the chat path and `OpenDisputeAction::postOpenerClaim`.
     */
    public static function attachFileTo(Message $message, UploadedFile $file): void
    {
        $isImage = str_starts_with((string) $file->getMimeType(), 'image/');

        if ($isImage) {
            self::attachImageTo($message, $file);

            return;
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'pdf');

        $message
            ->addMedia($file->getRealPath())
            ->usingName($file->getClientOriginalName())
            ->usingFileName(Str::uuid()->toString().'.'.$extension)
            ->toMediaCollection(Message::ATTACHMENTS_COLLECTION);
    }

    /**
     * Re-encode via GD/Imagick to strip EXIF (camera GPS / device fingerprint / capture time)
     * before persisting — doesn't belong in a money-chat audit trail. Public + static so
     * OpenDisputeAction can attach evidence to the user's dispute-opening message with the
     * same EXIF-stripping + dimension-capture pipeline as a normal chat upload.
     */
    public static function attachImageTo(Message $message, UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $tempPath = tempnam(sys_get_temp_dir(), 'stakly-chat-img-');
        $tempPathWithExt = $tempPath.'.'.$extension;
        rename($tempPath, $tempPathWithExt);

        // Capture dimensions so the frontend can set aspect-ratio and avoid layout shift.
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
     * Exposed so tests can clear the limiter between cases without duplicating the key construction.
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
     * Extracts http(s) URLs from message content for the OG metadata fetcher. Trailing
     * punctuation is stripped because chat sentences put URLs next to punctuation
     * (`look at https://foo.com.` should detect `https://foo.com`).
     *
     * @return list<string>
     */
    public static function extractLinkUrls(string $content): array
    {
        preg_match_all('#https?://[^\s<>"\']+#i', $content, $matches);

        $urls = [];

        foreach ($matches[0] as $raw) {
            $url = rtrim($raw, ".,;:!?'\"()[]{}");

            // Cheap pre-flight only — full DNS-based SSRF check runs inside the queued
            // worker. Doing DNS here would stall the request behind one resolver call per URL.
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
     * Return the 8-12 char Lichess game ID from a Lichess game URL, else null.
     * Reserved single-segment paths (`training`, `analysis`, etc.) are denylisted
     * because they fall in the same length window and would misroute to the game-card pipeline.
     */
    public static function extractLichessGameId(string $url): ?string
    {
        // Delimiter `~` (not `#`) — pattern contains a literal `#` inside the trailing
        // character class, and `#`-delimited would close the regex early and silently misparse.
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

    /**
     * Return the URL unchanged if it's a chess.com game URL, else null. The fetcher takes
     * a URL (not just ID) because chess.com's Published Data API has no get-by-id endpoint —
     * it queries per-user archives and matches by URL.
     */
    public static function extractChessComGameUrl(string $url): ?string
    {
        $matches = preg_match(
            '~^https?://(?:www\.)?chess\.com/(?:analysis/)?(?:game/(?:live|daily)|live/game)/\d+~i',
            $url,
        );

        return $matches === 1 ? $url : null;
    }
}
