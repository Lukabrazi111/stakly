<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public reader for admin-managed CMS pages. Two paths through `show`:
 *
 *   - **Public** — must be published; cached via `Cache::rememberForever`
 *     keyed by `Page::cacheKey()`. Busts on model save/delete.
 *   - **Admin preview** — request carries a valid temporary signature
 *     (`URL::temporarySignedRoute`, 30 min). Bypasses cache + published-at
 *     gate so drafts and scheduled rows render.
 *
 * Missing translations fall back to `Page::DEFAULT_LOCALE` via the model
 * resolver.
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
     * Bare-slug entry — `/about` → 301 to `/en/about`. Validates the slug
     * exists + is published first so unknown slugs 404 here instead of
     * redirecting to a route that will also 404.
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
     * @return array{title: string, html: string, description: string, updated_at: string}|null
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
