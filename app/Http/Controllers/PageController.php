<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public reader for admin-managed CMS pages (M26 Phase 1). Two paths through
 * the same action:
 *
 *   - **Public** — must be published; goes through `Cache::rememberForever`
 *     keyed by `Page::cacheKey()`. Cache busts automatically from the model's
 *     save/delete events.
 *   - **Admin preview** — request carries a valid temporary signature minted
 *     by `PagesTable` / `EditPage`. Bypasses the cache and the published-at
 *     gate so drafts and scheduled rows render. Signature expires after 30
 *     minutes (`URL::temporarySignedRoute`).
 *
 * Locale fallback to `Page::DEFAULT_LOCALE` lives in the model resolver, so
 * a missing translation transparently renders the English copy until the
 * translated row lands.
 */
class PageController extends Controller
{
    public function show(Request $request, string $locale, string $slug): Response
    {
        $payload = $request->hasValidSignature()
            ? $this->payloadForPreview($slug, $locale)
            : $this->payloadForPublic($slug, $locale);

        abort_if($payload === null, 404);

        return Inertia::render('cms/page', $payload);
    }

    /**
     * Bare-slug entry point — `/about` → 301 to `/en/about`. Validates the
     * slug exists + is published first (re-uses the public cache key) so
     * unknown slugs 404 instead of redirecting to a route that will also
     * 404 — saves a hop and keeps logs cleaner.
     */
    public function redirectToDefault(string $slug): RedirectResponse
    {
        $payload = $this->payloadForPublic($slug, Page::DEFAULT_LOCALE);

        abort_if($payload === null, 404);

        return redirect()->route('pages.show', [
            'locale' => Page::DEFAULT_LOCALE,
            'slug' => $slug,
        ], 301);
    }

    /**
     * @return array{title: string, html: string, updated_at: string}|null
     */
    private function payloadForPublic(string $slug, string $locale): ?array
    {
        return Cache::rememberForever(
            Page::cacheKey($slug, $locale),
            function () use ($slug, $locale): ?array {
                $page = Page::forSlugWithFallback($slug, $locale);

                if ($page === null || ! $page->isPublished()) {
                    return null;
                }

                return $this->renderPayload($page);
            },
        );
    }

    /**
     * @return array{title: string, html: string, updated_at: string}|null
     */
    private function payloadForPreview(string $slug, string $locale): ?array
    {
        $page = Page::forSlugWithFallback($slug, $locale);

        return $page === null ? null : $this->renderPayload($page);
    }

    /**
     * @return array{title: string, html: string, updated_at: string}
     */
    private function renderPayload(Page $page): array
    {
        return [
            'title' => $page->title,
            'html' => $page->renderedHtml(),
            'updated_at' => $page->updated_at?->toIso8601String() ?? '',
        ];
    }
}
