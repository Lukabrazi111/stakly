<?php

namespace App\Jobs;

use App\Events\MessageSent;
use App\Models\Message;
use App\Support\SafeHttpClient;
use App\Support\SsrfGuard;
use Embed\Embed;
use Embed\Http\Crawler;
use Embed\Http\CurlClient;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Image;
use Throwable;

/**
 * Background OG/Twitter/oEmbed metadata fetch for URLs that landed in a
 * chat message. Runs once per message — `SendMessageAction` collects all
 * URLs in the content into a single dispatch, so concurrent jobs writing
 * to the same row never race.
 *
 * Each URL goes through `App\Support\SafeHttpClient` (per-hop SSRF check
 * + bounded redirect chain + GET-only) before oscarotero/embed sees it.
 * If the page exposes an `og:image`, we proxy it through Stakly: fetch
 * the bytes (separately SSRF-guarded + MIME-checked + size-capped),
 * re-encode via Spatie\Image to drop EXIF, persist on the private
 * `local` disk under `link-images/{sha256-of-url}.{ext}`. The browser
 * later resolves that file via the authenticated streaming route in
 * `App\Http\Controllers\LinkImageController`, so a third-party host
 * never sees a participant's IP and a broken-image link never leaks
 * a stale Stakly URL.
 *
 * Results are cached per-URL for an hour so a popular link doesn't
 * hammer the upstream on every paste. Failures (SSRF refusal, timeout,
 * malformed HTML, bot-detection wall) are logged + swallowed — a missing
 * card is acceptable; a job that throws would put the row at the head of
 * the failed-jobs queue forever.
 *
 * On any successfully extracted entry, we re-broadcast `MessageSent`
 * with `correlation_id = null`. The frontend's match-by-id replace path
 * swaps the plain-link bubble for one with link cards in place — no
 * scroll, no reorder.
 */
class FetchLinkMetadataJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CACHE_TTL_SECONDS = 3600;

    private const MAX_IMAGE_BYTES = 2_000_000;

    private const MAX_IMAGE_REDIRECTS = 3;

    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const USER_AGENT = 'StaklyLinkPreview/1.0 (+chat unfurl)';

    /**
     * Single try — link previews are nice-to-have, not load-bearing.
     * Retrying a metadata fetch buys us nothing and risks hammering the
     * same dead URL on every redeploy of the queue worker.
     */
    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  list<string>  $urls  De-duplicated, validated, capped list of
     *                              http(s) URLs extracted from the message's content.
     */
    public function __construct(
        public Message $message,
        public array $urls,
    ) {}

    public function handle(): void
    {
        $entries = [];

        foreach ($this->urls as $url) {
            $entry = $this->extractEntry($url);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if (count($entries) === 0) {
            return;
        }

        $this->appendEntriesAndRebroadcast($entries);
    }

    /**
     * Cache + extract + image-proxy pipeline for a single URL. Wrapped in
     * a try/catch so any one URL's failure (DNS issue, SSRF refusal,
     * malformed HTML, etc.) doesn't block the rest of the batch.
     *
     * @return array<string, mixed>|null
     */
    private function extractEntry(string $url): ?array
    {
        $cacheKey = $this->cacheKey($url);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            return $this->fetchAndCache($url, $cacheKey);
        } catch (Throwable $e) {
            Log::info('Link preview fetch failed', [
                'url' => $url,
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAndCache(string $url, string $cacheKey): ?array
    {
        // Pre-check before handing to the library — the library will also
        // SSRF-check on every hop via our SafeHttpClient, but failing fast
        // here avoids spawning curl handles for obviously-bad input.
        if (! SsrfGuard::isUrlSafe($url)) {
            return null;
        }

        $info = $this->buildEmbed()->get($url);

        $title = $info->title;
        $description = $info->description;
        $canonicalUrl = $info->url ? (string) $info->url : $url;
        $siteName = $info->providerName ?: parse_url($url, PHP_URL_HOST) ?: null;

        // A card with nothing but the URL itself isn't worth showing — it's
        // identical to the plain blue-text fallback. Demand at least a title
        // so the unfurl carries information.
        if ($title === null || trim($title) === '') {
            return null;
        }

        $proxiedImage = null;
        $imageMime = null;

        if ($info->image !== null) {
            $proxiedImage = $this->proxyImage((string) $info->image);

            if ($proxiedImage !== null) {
                [$proxiedImage, $imageMime] = $proxiedImage;
            }
        }

        $entry = [
            'type' => 'link',
            'url' => $url,
            'canonical_url' => $canonicalUrl,
            'title' => Str::limit($title, 200, ''),
            'description' => $description ? Str::limit($description, 300, '') : null,
            'site_name' => $siteName ? Str::limit($siteName, 80, '') : null,
            'image_path' => $proxiedImage,
            'image_mime' => $imageMime,
        ];

        Cache::put($cacheKey, $entry, self::CACHE_TTL_SECONDS);

        return $entry;
    }

    /**
     * Build a fresh `Embed` per call — the library's `Crawler` holds a
     * cookie-jar path tied to its `CurlClient` instance, and we want a
     * clean session per URL to avoid cross-domain cookie leaks.
     */
    private function buildEmbed(): Embed
    {
        $curl = new CurlClient;
        $curl->setSettings([
            'follow_location' => false,
            'ssl_verify_peer' => true,
            'ssl_verify_host' => 2,
            'max_redirs' => 0,
            'timeout' => 5,
            'connect_timeout' => 3,
            'user_agent' => self::USER_AGENT,
        ]);

        $safe = new SafeHttpClient($curl);

        // The Crawler's constructor accepts any PSR-18 ClientInterface; our
        // SafeHttpClient is one. The library will use it for the initial
        // fetch and for any canonical-equiv re-fetch it triggers internally.
        return new Embed(new Crawler($safe));
    }

    /**
     * Fetch + persist an OG image. Returns `[disk_path, mime]` on success,
     * `null` on any failure (SSRF refusal, redirect loop, oversized
     * payload, non-image MIME, unsupported format, re-encode failure).
     *
     * Re-encoded through Spatie\Image — same EXIF-strip pattern as Slice 1
     * chat image uploads. We never persist third-party image bytes
     * verbatim because the image could embed GPS / device metadata or
     * cute payload-in-EXIF tricks that browsers occasionally choke on.
     *
     * @return array{0: string, 1: string}|null
     */
    private function proxyImage(string $imageUrl): ?array
    {
        try {
            $bytes = $this->downloadImageBytes($imageUrl, 0);
        } catch (Throwable $e) {
            Log::info('Link preview image fetch failed', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($bytes === null) {
            return null;
        }

        [$rawBytes, $mime] = $bytes;

        // Spatie\Image needs a file path on disk, not a stream — write the
        // download to a temp file, re-encode in place (which strips EXIF
        // via the GD/Imagick round-trip), and only then move it to the
        // public media disk path. The temp file unlinks via PHP's
        // tmpfile semantics at the end of the request / handle().
        $extension = $this->extensionFor($mime);
        $tempPath = tempnam(sys_get_temp_dir(), 'stakly-link-img-');
        $tempPathWithExt = $tempPath.'.'.$extension;
        rename($tempPath, $tempPathWithExt);

        try {
            file_put_contents($tempPathWithExt, $rawBytes);
            Image::load($tempPathWithExt)->save($tempPathWithExt);
        } catch (Throwable $e) {
            @unlink($tempPathWithExt);
            Log::info('Link preview image re-encode failed', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $hash = hash('sha256', $imageUrl);
        $relativePath = 'link-images/'.$hash.'.'.$extension;

        // putFileAs is atomic on local-disk drivers and overwrites if the
        // hash already exists (two messages linking the same URL share
        // the same cached file — no waste).
        $finalPath = Storage::disk('local')->putFileAs(
            'link-images',
            $tempPathWithExt,
            $hash.'.'.$extension,
        );

        @unlink($tempPathWithExt);

        if ($finalPath === false) {
            return null;
        }

        return [$relativePath, $mime];
    }

    /**
     * Manual redirect chain for image fetches — Guzzle's allow_redirects
     * would skip our SSRF check on intermediate hops. Same per-hop guard
     * pattern as `SafeHttpClient`, but the body is read into memory rather
     * than parsed as HTML so we can't reuse SafeHttpClient (its underlying
     * CurlClient rejects binary bodies).
     *
     * @return array{0: string, 1: string}|null [bytes, mime]
     */
    private function downloadImageBytes(string $url, int $hop): ?array
    {
        if ($hop > self::MAX_IMAGE_REDIRECTS) {
            return null;
        }

        if (! SsrfGuard::isUrlSafe($url)) {
            return null;
        }

        $response = Http::withOptions([
            'allow_redirects' => false,
            'stream' => false,
        ])
            ->timeout(5)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->get($url);

        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            $location = $response->header('Location');

            if (! is_string($location) || $location === '') {
                return null;
            }

            $resolved = (string) UriResolver::resolve(
                Utils::uriFor($url),
                Utils::uriFor($location),
            );

            return $this->downloadImageBytes($resolved, $hop + 1);
        }

        if (! $response->successful()) {
            return null;
        }

        $mime = strtolower(strtok($response->header('Content-Type') ?? '', ';') ?: '');

        if (! in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            return null;
        }

        $body = $response->body();

        if (strlen($body) === 0 || strlen($body) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return [$body, $mime];
    }

    /**
     * Lock the message row, append the new entries to whatever else might
     * already be there (a second slice-2 job pile-up won't lose entries),
     * then re-broadcast.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function appendEntriesAndRebroadcast(array $entries): void
    {
        $rebroadcastTarget = null;

        DB::transaction(function () use ($entries, &$rebroadcastTarget) {
            $fresh = Message::query()->lockForUpdate()->find($this->message->id);

            if ($fresh === null) {
                return;
            }

            $existing = is_array($fresh->attachments_json) ? $fresh->attachments_json : [];
            $fresh->update(['attachments_json' => array_merge($existing, $entries)]);

            $rebroadcastTarget = $fresh;
        });

        if ($rebroadcastTarget !== null) {
            // No correlation_id — this is not tied to a specific client send.
            // The frontend's match-by-id replace path picks the broadcast up
            // and swaps the cards into place on the existing bubble.
            MessageSent::dispatch($rebroadcastTarget);
        }
    }

    private function cacheKey(string $url): string
    {
        return 'link-preview:'.hash('sha256', $url);
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }
}
