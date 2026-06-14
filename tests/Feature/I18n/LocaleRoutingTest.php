<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\App;

/**
 * M26 Phase 4 Slice A — locale-prefix routing foundation. Exercises both
 * middleware (SetLocale + RedirectUnprefixedLocale), the route group
 * constraint, and the resulting Inertia shared-prop payload.
 *
 * Opts out of the TestCase's auto-locale-prefix so the tests can drive raw
 * unprefixed URIs and observe the redirect middleware directly.
 */
beforeEach(function () {
    $this->withoutLocalePrefix();
});

// ─── Prefixed routes serve normally ────────────────────────────────────────

test('GET /en/ serves the home page', function () {
    $this->get('/en')->assertOk();
});

test('GET /en/listings serves the listings index', function () {
    $this->get('/en/listings')->assertOk();
});

test('GET /ka/listings serves the listings index under the ka prefix', function () {
    $this->get('/ka/listings')->assertOk();
});

test('GET /xx/listings 404s — unsupported locale', function () {
    $this->get('/xx/listings')->assertNotFound();
});

// ─── Unprefixed requests redirect 301 to the preferred locale ──────────────

test('GET /listings without prefix 301-redirects to /en/listings', function () {
    $this->get('/listings')
        ->assertStatus(301)
        ->assertRedirect('/en/listings');
});

test('cookie-remembered locale wins over the default on unprefixed requests', function () {
    $this->withUnencryptedCookie(SetLocale::COOKIE_NAME, 'ka')
        ->get('/listings')
        ->assertStatus(301)
        ->assertRedirect('/ka/listings');
});

test('invalid cookie value falls back to default locale', function () {
    $this->withUnencryptedCookie(SetLocale::COOKIE_NAME, 'xx')
        ->get('/listings')
        ->assertStatus(301)
        ->assertRedirect('/en/listings');
});

test('root / redirects to /en (preserves trailing-slash behaviour)', function () {
    $this->get('/')
        ->assertStatus(301)
        ->assertRedirect('/en');
});

test('query strings survive the redirect', function () {
    $this->get('/listings?game=chess&min=10')
        ->assertStatus(301)
        ->assertRedirect('/en/listings?game=chess&min=10');
});

// ─── Exempt paths pass through without redirect ────────────────────────────

test('GET /up (health) does not redirect', function () {
    $this->get('/up')->assertOk();
});

test('GET /login (Fortify) does not redirect into the locale prefix', function () {
    // Fortify's `loginView` callback redirects /login → /?auth=login at the
    // app root. We just need to assert the middleware didn't bounce it to
    // /en/login on the way through.
    $location = $this->get('/login')->headers->get('Location') ?? '';

    expect($location)->toContain('auth=login')
        ->and($location)->not->toContain('/en/');
});

test('GET /admin (Filament) does not redirect into the locale prefix', function () {
    // Filament redirects unauthenticated requests to /admin/login. The point
    // here is that the destination is still under `/admin/`, not `/en/admin/`.
    $response = $this->get('/admin');

    expect($response->headers->get('Location') ?? '')->not->toStartWith('/en/admin');
});

test('GET /favicon.ico does not redirect into the locale prefix', function () {
    $response = $this->get('/favicon.ico');

    // The framework will 404 or 200 (depending on whether the file exists in
    // public/); what matters is no 301 to /en/favicon.ico.
    expect($response->status())->not->toBe(301);
});

// ─── Non-GET methods are not redirected ────────────────────────────────────

test('POST to an unprefixed path falls through to a normal 405/404', function () {
    // Fortify's /logout is POST — without our middleware getting in the way,
    // it resolves directly. With it, the middleware lets non-safe methods
    // pass; the framework then 405s or processes normally.
    $response = $this->post('/listings');

    expect($response->status())->not->toBe(301);
});

// ─── SetLocale effects: App::getLocale + Inertia share ─────────────────────

test('SetLocale flips App::getLocale to the URL locale', function () {
    $this->get('/ka/listings');

    expect(App::getLocale())->toBe('ka');
});

test('Inertia shared props carry the active locale code', function () {
    $this->get('/ka/listings')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('locale', 'ka'));
});

test('Inertia shared props expose availableLocales with native labels', function () {
    $this->get('/en/listings')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('availableLocales', 3)
            ->where('availableLocales.0.code', 'en')
            ->where('availableLocales.0.native_label', 'English')
            ->where('availableLocales.1.code', 'ka')
            ->where('availableLocales.1.native_label', 'ქართული')
            ->where('availableLocales.2.code', 'ru')
            ->where('availableLocales.2.native_label', 'Русский'),
        );
});

test('Inertia shared props ship the en JSON bag when locale is en', function () {
    $this->get('/en/listings')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('translations.Listings', 'Listings'));
});

test('SetLocale queues stakly_locale cookie on response', function () {
    $response = $this->get('/ka/listings');

    $cookies = collect($response->headers->getCookies());
    $localeCookie = $cookies->firstWhere(fn ($c) => $c->getName() === SetLocale::COOKIE_NAME);

    expect($localeCookie)->not->toBeNull()
        ->and($localeCookie->getValue())->toBe('ka');
});

// ─── URL::defaults fallback (admin / queue / console contexts) ─────────────
//
// AppServiceProvider::boot sets `URL::defaults(['locale' => default])` as a
// safety net for anywhere SetLocale middleware never runs — Filament admin,
// queued notifications, console commands. Without it, every `route(...)`
// call outside the locale-prefix group 500s with `Missing parameter: {locale}`.

test('route() generates locale-prefixed URLs without SetLocale middleware', function () {
    expect(route('listings.show', ['listing' => 42], absolute: false))->toBe('/en/listings/42');
    expect(route('matches.show', ['match' => 7], absolute: false))->toBe('/en/matches/7');
    expect(route('listings.index', absolute: false))->toBe('/en/listings');
});

test('route() positional scalar arg still works because locale fills from defaults', function () {
    // Filament `WalletTransactionInfolist` calls
    // `route('listings.show', $record->related_listing_id)` — a single scalar.
    // Defaults must fill {locale} BEFORE positional binding pulls into {listing}.
    expect(route('listings.show', 42, absolute: false))->toBe('/en/listings/42');
});

// ─── Livewire 3 hashed-prefix assets bypass the locale redirect ────────────
//
// Regression guard for the Filament admin login breakage (2026-06-14): the
// exempt list had `'livewire'` (exact first-segment match), but Livewire 3
// serves its asset bundle from `/livewire-{hash}/livewire.js` where the hash
// changes per Livewire version. Without the prefix match, the asset request
// got 301'd into `/en/livewire-{hash}/...` and 404'd, leaving the Filament
// admin login form non-interactive (password show/hide broken, form submit
// no-ops). Future Livewire bumps must NOT silently re-break this.

test('Livewire 3 hashed asset prefix is not locale-redirected', function () {
    // Middleware should match any `livewire-{anything}` first segment and
    // skip the locale redirect. We assert the negative (no 301) rather than
    // "returns 200" because Livewire's hashed asset only resolves once the
    // app has been booted with Livewire registered AND served by the same
    // app handler — on CI without the actual asset the route 404s, which
    // is still correct middleware behaviour. The bug we're guarding is the
    // 301 → `/en/livewire-{hash}/...` regression.
    //
    // Locally the asset resolves to a `BinaryFileResponse` (file streaming)
    // which is missing the Laravel-specific `status()` method on the base
    // response. `getStatusCode()` lives on Symfony's Response and is safe
    // across every response type the framework might return here.
    $response = $this->get('/livewire-3b469bd1/livewire.js');
    expect($response->getStatusCode())->not->toBe(301);
});

test('Livewire hashed update endpoint is not locale-redirected', function () {
    // Even on a non-existent hash, the path must NOT be 301'd to /en/...
    // (404 is the right answer if the route doesn't exist; 301 to /en/ is
    // the regression we're guarding against).
    $response = $this->get('/livewire-doesnotexist/livewire.js');
    expect($response->getStatusCode())->not->toBe(301);
});
