<?php

use App\Models\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * M26 Phase 1 — public reader for CMS pages. Covers:
 *   - Published pages render via Inertia with title + html
 *   - Drafts and scheduled rows 404 publicly
 *   - Admin signed URL bypasses both the published-at gate and the cache
 *   - `/{slug}` redirects to `/en/{slug}` only when the page exists
 *   - Cache: second hit doesn't re-query the database
 */
beforeEach(function () {
    Cache::flush();

    // Several tests below drive bare-slug URLs (`/about`, `/unknown-slug`)
    // to assert the RedirectUnprefixedLocale middleware's 301. Without
    // opting out of the TestCase's auto-prefix, those URIs would be
    // silently rewritten to `/en/about` etc. before the middleware ever
    // sees them.
    $this->withoutLocalePrefix();
});

test('GET /en/{slug} renders published page via Inertia', function () {
    Page::factory()->create([
        'slug' => 'about',
        'locale' => 'en',
        'title' => 'About Stakly',
        'body' => "## Hello\n\nWelcome to Stakly.",
        'published_at' => now()->subDay(),
    ]);

    $response = $this->get('/en/about');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('cms/page')
            ->where('title', 'About Stakly')
            ->where('html', fn (string $html) => str_contains($html, '<h2>Hello</h2>'))
            ->has('updated_at'));
});

test('GET /en/{slug} returns 404 for missing pages', function () {
    $this->get('/en/nonexistent')->assertNotFound();
});

test('GET /en/{slug} returns 404 for draft pages', function () {
    Page::factory()->draft()->create(['slug' => 'about', 'locale' => 'en']);

    $this->get('/en/about')->assertNotFound();
});

test('GET /en/{slug} returns 404 for scheduled pages', function () {
    Page::factory()->scheduled(now()->addDay())->create([
        'slug' => 'about',
        'locale' => 'en',
    ]);

    $this->get('/en/about')->assertNotFound();
});

test('admin preview signature renders draft pages', function () {
    $page = Page::factory()->draft()->create([
        'slug' => 'about',
        'locale' => 'en',
        'title' => 'Draft About',
        'body' => '## Coming soon',
    ]);

    $signed = URL::temporarySignedRoute(
        'pages.show',
        now()->addMinutes(30),
        ['locale' => $page->locale, 'slug' => $page->slug],
    );

    $this->get($signed)
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('cms/page')
            ->where('title', 'Draft About'));
});

test('admin preview signature renders scheduled pages', function () {
    $page = Page::factory()->scheduled(now()->addDay())->create([
        'slug' => 'about',
        'locale' => 'en',
        'title' => 'Scheduled About',
        'body' => '## Coming soon',
    ]);

    $signed = URL::temporarySignedRoute(
        'pages.show',
        now()->addMinutes(30),
        ['locale' => $page->locale, 'slug' => $page->slug],
    );

    $this->get($signed)->assertOk();
});

test('invalid signature falls through to public path', function () {
    Page::factory()->draft()->create(['slug' => 'about', 'locale' => 'en']);

    // A bogus signature is treated the same as no signature — `hasValidSignature()`
    // rejects it and the controller hits the public branch, which 404s a draft.
    $this->get('/en/about?expires=1&signature=invalid')->assertNotFound();
});

test('bare slug redirects to default locale URL with 301', function () {
    Page::factory()->create([
        'slug' => 'about',
        'locale' => 'en',
        'published_at' => now()->subDay(),
    ]);

    $this->get('/about')
        ->assertStatus(301)
        ->assertRedirect('/en/about');
});

test('bare slug redirects even when no page exists', function () {
    // The RedirectUnprefixedLocale middleware (M26 P4) redirects every unprefixed
    // public GET to /{defaultLocale}/<path>; existence is the destination route's
    // problem. The follow-up `GET /en/{slug} returns 404 for missing pages` test
    // covers what happens after the 301.
    $this->get('/unknown-slug')
        ->assertStatus(301)
        ->assertRedirect('/en/unknown-slug');
});

test('bare slug redirects even when only a draft exists', function () {
    Page::factory()->draft()->create(['slug' => 'about', 'locale' => 'en']);

    // Same as above — the middleware doesn't peek at the destination's
    // published status. `GET /en/{slug} returns 404 for draft pages`
    // covers the 404 the prefixed route then emits.
    $this->get('/about')
        ->assertStatus(301)
        ->assertRedirect('/en/about');
});

test('second public request hits cache (no extra DB query)', function () {
    Page::factory()->create([
        'slug' => 'about',
        'locale' => 'en',
        'published_at' => now()->subDay(),
    ]);

    $this->get('/en/about')->assertOk();

    // Count queries on the second request — the cache hit should bypass the
    // `Page::forSlugWithFallback` query entirely.
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->get('/en/about')->assertOk();

    expect($queries)->toBe(0);
});

test('saving a page after a cached request makes the next read see the update', function () {
    $page = Page::factory()->create([
        'slug' => 'about',
        'locale' => 'en',
        'title' => 'Original',
        'published_at' => now()->subDay(),
    ]);

    $this->get('/en/about')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('title', 'Original'));

    $page->update(['title' => 'Updated']);

    $this->get('/en/about')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('title', 'Updated'));
});

test('unknown locale segment does not match the CMS route', function () {
    Page::factory()->create([
        'slug' => 'about',
        'locale' => 'en',
        'published_at' => now()->subDay(),
    ]);

    // The locale-prefix group constrains `{locale}` to `config('stakly.locales')`
    // ('en', 'ka', 'ru'). `/fr/about` doesn't satisfy that, so the router 404s
    // before the CMS route ever sees it — no controller check needed.
    $this->get('/fr/about')->assertNotFound();
});
