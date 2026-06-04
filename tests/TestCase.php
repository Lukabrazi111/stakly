<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * When true (the default), the trait-level `call()` / `json()` overrides
     * auto-prepend `/en/` to test URIs that aren't already locale-prefixed
     * or in the exempt path list. Lets existing feature tests keep using
     * unprefixed literals (`'/listings'`, `'/wallet/deposit'`) without
     * touching ~300 call sites after the M26 P4 locale-prefix routing
     * landed.
     *
     * Tests that specifically need to drive an unprefixed request (e.g. the
     * `LocaleRoutingTest` exercising the redirect middleware) opt out via
     * `$this->withoutLocalePrefix()` in beforeEach.
     */
    protected bool $autoLocalePrefix = true;

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    protected function withoutLocalePrefix(): static
    {
        $this->autoLocalePrefix = false;

        return $this;
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        return parent::call($method, $this->localizedUri($uri), $parameters, $cookies, $files, $server, $content);
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        return parent::json($method, $this->localizedUri($uri), $data, $headers, $options);
    }

    /**
     * Prepend `/en/` to test URIs that target the locale-prefixed web routes.
     * Skipped for: already-prefixed URIs (`/en/…`, `/ka/…`, `/ru/…`), paths
     * that look like an attempted locale prefix (2–3 lowercase letters,
     * matches the middleware's defensive 404 gate), and any first segment
     * served outside the locale group (admin, Fortify auth surfaces, health,
     * broadcasting, static assets).
     */
    private function localizedUri(mixed $uri): mixed
    {
        if (! $this->autoLocalePrefix || ! is_string($uri) || $uri === '') {
            return $uri;
        }

        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return '/en'.$query.$fragment;
        }

        $firstSegment = explode('/', $trimmed)[0];
        $locales = (array) config('stakly.locales', ['en', 'ka', 'ru']);

        if (in_array($firstSegment, $locales, true)) {
            return $uri;
        }

        if (in_array($firstSegment, self::EXEMPT_FIRST_SEGMENTS, true)) {
            return $uri;
        }

        if (in_array($trimmed, self::EXEMPT_FILES, true)) {
            return $uri;
        }

        if (preg_match('/^[a-z]{2,3}$/', $firstSegment) === 1) {
            return $uri;
        }

        return '/en/'.$trimmed.$query.$fragment;
    }

    /**
     * Mirrors `RedirectUnprefixedLocale::EXEMPT_FIRST_SEGMENTS` — kept in
     * sync manually because tests and middleware are in different layers.
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
    ];

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
}
