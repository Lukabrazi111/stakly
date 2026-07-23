<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public reader for admin-managed CMS pages. Two paths through `show`:
 *
 *   - **Public** — must be published; published payloads are cached forever
 *     keyed by `Page::cacheKey()` (busts on model save/delete). Misses
 *     (draft / scheduled / absent) cache a short-TTL sentinel instead of
 *     `null` to avoid re-querying every request, so a scheduled page goes
 *     live within the TTL after `published_at` passes.
 *   - **Admin preview** — request carries a valid temporary signature
 *     (`URL::temporarySignedRoute`, 30 min). Bypasses cache + published-at
 *     gate so drafts and scheduled rows render.
 *
 * Missing translations fall back to `Page::defaultLocale()` via the model
 * resolver.
 */
class PageController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        // `{locale}` is on the route URI but `SetLocale` strips it from
        // the route's parameter bag before dispatch (to avoid Laravel's
        // positional dependency-resolver misalignment), so the active
        // locale is read from `App::getLocale()` here instead of taking
        // it as a typed argument.
        $locale = App::getLocale();

        $payload = $request->hasValidSignature()
            ? $this->payloadForPreview($slug, $locale)
            : $this->payloadForPublic($slug, $locale);

        abort_if($payload === null, 404);

        return Inertia::render('cms/page', $payload);
    }

    /**
     * @return array{title: string, html: string, description: string, updated_at: string}|null
     */
    private function payloadForPublic(string $slug, string $locale): ?array
    {
        $key = Page::cacheKey($slug, $locale);

        $cached = Cache::get($key);

        if ($cached !== null) {
            // Sentinel marks a known miss; translate it back to a 404.
            return ($cached['__missing'] ?? false) === true ? null : $cached;
        }

        $page = Page::forSlugWithFallback($slug, $locale);

        if ($page === null || ! $page->isPublished()) {
            Cache::put($key, ['__missing' => true], now()->addMinutes(5));

            return null;
        }

        $payload = $this->renderPayload($page);

        Cache::forever($key, $payload);

        return $payload;
    }

    /**
     * @return array{title: string, html: string, description: string, updated_at: string}|null
     */
    private function payloadForPreview(string $slug, string $locale): ?array
    {
        $page = Page::forSlugWithFallback($slug, $locale);

        return $page === null ? null : $this->renderPayload($page);
    }

    /**
     * @return array{title: string, html: string, description: string, updated_at: string}
     */
    private function renderPayload(Page $page): array
    {
        $html = $page->renderedHtml();

        return [
            'title' => $page->title,
            'html' => $html,
            // og:description excerpt — strip tags + collapse whitespace
            // before truncating so the snippet reads as one sentence
            // rather than visible markdown leftovers.
            'description' => (string) str(strip_tags($html))
                ->replaceMatches('/\s+/u', ' ')
                ->trim()
                ->limit(160),
            'updated_at' => $page->updated_at?->toIso8601String() ?? '',
        ];
    }
}
