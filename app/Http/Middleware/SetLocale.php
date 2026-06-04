<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the `{locale}` URL prefix to the request lifecycle:
 * activates Laravel's locale (so `__('key')` resolves the right bag),
 * sets `URL::defaults(['locale' => …])` (so server-generated `route()`
 * URLs auto-prefix without per-call boilerplate), and refreshes the
 * `stakly_locale` cookie so the next unprefixed visit redirects to the
 * same locale via `RedirectUnprefixedLocale`.
 *
 * Runs inside `Route::prefix('{locale}')->whereIn('locale', …)`, so by
 * the time we get here the `{locale}` param is guaranteed to be one of
 * the supported codes. The defence-in-depth `in_array` check below is
 * for the corner case where the middleware is invoked outside that
 * group (eg. test harness, future refactor).
 */
class SetLocale
{
    public const COOKIE_NAME = 'stakly_locale';

    private const COOKIE_LIFETIME_MINUTES = 60 * 24 * 365;

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');

        if (! is_string($locale) || ! in_array($locale, config('stakly.locales', []), true)) {
            abort(404);
        }

        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);

        Cookie::queue(
            self::COOKIE_NAME,
            $locale,
            self::COOKIE_LIFETIME_MINUTES,
            path: '/',
        );

        // Strip `locale` off the route's bag so it doesn't leak into the
        // controller dispatcher. Laravel's `ResolvesRouteDependencies`
        // positional-aligns `array_values($parameters)` against the
        // controller signature; an unconsumed route param (we never type
        // `string $locale` on any controller) skews the alignment and
        // results in a `TypeError` when the next model-bound param
        // receives the locale string instead of its model.
        $request->route()?->forgetParameter('locale');

        return $next($request);
    }
}
