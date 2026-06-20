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
 * Background OG/Twitter/oEmbed metadata fetch for URLs in a chat message.
 *
 * URLs go through `App\Support\SafeHttpClient` (per-hop SSRF check + bounded redirects + GET-only)
 * before the embed library sees them. OG images are proxied through Stakly (re-encoded to drop EXIF,
 * persisted under `link-images/{sha256-of-url}.{ext}`) so the third-party host never sees a participant's IP.
 *
 * Per-URL results cached for an hour. Failures are logged + swallowed — a thrown job lands in
 * failed-jobs forever and clutters the queue.
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
     * Single try — link previews are nice-to-have, retrying a dead URL on every redeploy is pointless.
     */
    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  list<string>  $urls  De-duplicated, validated, capped list of http(s) URLs.
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
     * try/catch isolates per-URL failure so one bad URL doesn't block the rest of the batch.
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
        // Fail fast before spawning curl handles — library will also per-hop SSRF-check via SafeHttpClient.
        if (! SsrfGuard::isUrlSafe($url)) {
            return null;
        }

        $info = $this->buildEmbed()->get($url);

        $title = $info->title;
        $description = $info->description;
        $canonicalUrl = $info->url ? (string) $info->url : $url;
        $siteName = $info->providerName ?: parse_url($url, PHP_URL_HOST) ?: null;

        // A title-less card is identical to the plain blue-text fallback — not worth showing.
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
     * Fresh `Embed` per call — the Crawler's cookie-jar is tied to its CurlClient instance,
     * so we want a clean session per URL to avoid cross-domain cookie leaks.
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

        return new Embed(new Crawler($safe));
    }

    /**
     * Re-encoded through Spatie\Image to strip EXIF — never persist third-party image bytes
     * verbatim (GPS / device metadata / payload-in-EXIF tricks).
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

        // Spatie\Image needs a file path on disk, not a stream — temp file → re-encode in
        // place → move to media disk. Re-encode strips EXIF via the GD/Imagick round-trip.
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

        // Two messages linking the same URL share the same cached file via the sha256 hash.
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
     * Manual redirect chain — Guzzle's allow_redirects would skip our SSRF check on intermediate hops.
     * Can't reuse SafeHttpClient because its CurlClient rejects binary bodies.
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
     * Row lock prevents read-modify-write race on the JSON column with concurrent appenders.
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
            // No correlation_id — frontend's match-by-id replace path swaps the cards in place.
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
