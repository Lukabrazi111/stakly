<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces every public web GET to live under `/{locale}/…`. A request to
 * `/listings` 301-redirects to `/{preferredLocale}/listings`, where
 * `preferredLocale` is the `stakly_locale` cookie if present and valid,
 * otherwise `config('stakly.default_locale')`.
 *
 * Skipped for: any path already prefixed with a supported locale
 * (normal traffic), Fortify auth POST surfaces, Filament admin, the
 * Reverb broadcasting-auth endpoint, the health route, and static
 * file paths the framework serves directly. Non-GET / non-HEAD verbs
 * also pass through — POST → 301 silently downgrades to GET in some
 * browsers, so we let mismatched verbs fall through to a 404 instead
 * of issuing a redirect that would corrupt a form submission.
 *
 * Bypassing for an Inertia partial reload would mask a real bug
 * (the client should always emit locale-prefixed URLs via Wayfinder),
 * but a 301 here would also be safe — Inertia just follows the
 * Location header. We chose "pass through 301s on Inertia too" to
 * keep the rule trivial: GET + unprefixed → redirect.
 */
class RedirectUnprefixedLocale
{
    /**
     * Exact-match path segments that the framework or upstream
     * packages serve directly at app root.
     */
    private const EXEMPT_FIRST_SEGMENTS = [
        'admin',
        'login',
        'logout',
        'register',
        'forgot-password',
        'reset-password',
        'email',
        'user',
        'two-factor-challenge',
        'broadcasting',
        'up',
        'build',
        'storage',
        'vendor',
        'api',
        'sanctum',
        'livewire',
        'filament',
        'filament-impersonate',
        // M15 Phase 2 — `/auth/{provider}/callback` OAuth callbacks must
        // be locale-agnostic because external IdPs only support a single
        // redirect URI per app. Future Riot/Discord/etc. callbacks land
        // under `/auth/*` too, so the exemption is for the whole subtree.
        'auth',
    ];

    /**
     * Exact filenames at the document root that don't get locale
     * prefixes (browser auto-requests, SEO files).
     */
    private const EXEMPT_FILES = [
        'favicon.ico',
        'favicon.svg',
        'apple-touch-icon.png',
        'robots.txt',
        'sitemap.xml',
        'og-image.png',
        'manifest.json',
        'site.webmanifest',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if ($this->shouldSkip($path)) {
            return $next($request);
        }

        $locales = (array) config('stakly.locales', ['en']);
        $firstSegment = explode('/', $path)[0] ?? '';

        if (in_array($firstSegment, $locales, true)) {
            return $next($request);
        }

        // 2–3 lowercase letters look like an ISO 639 language code. If a
        // request hits `/xx/...` with an unsupported code, the user clearly
        // tried to specify a locale — bouncing to `/en/xx/...` would just
        // create a 404 one redirect later. Let the router 404 directly.
        if (preg_match('/^[a-z]{2,3}$/', $firstSegment) === 1) {
            return $next($request);
        }

        $preferred = $this->preferredLocale($request, $locales);

        $target = '/'.$preferred.($path === '' ? '' : '/'.$path);

        if ($request->getQueryString() !== null) {
            $target .= '?'.$request->getQueryString();
        }

        return redirect($target, 301);
    }

    private function shouldSkip(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (in_array($path, self::EXEMPT_FILES, true)) {
            return true;
        }

        $firstSegment = explode('/', $path)[0];

        return in_array($firstSegment, self::EXEMPT_FIRST_SEGMENTS, true);
    }

    /**
     * @param  list<string>  $locales
     */
    private function preferredLocale(Request $request, array $locales): string
    {
        $cookie = $request->cookie(SetLocale::COOKIE_NAME);

        if (is_string($cookie) && in_array($cookie, $locales, true)) {
            return $cookie;
        }

        return (string) config('stakly.default_locale', 'en');
    }
}
