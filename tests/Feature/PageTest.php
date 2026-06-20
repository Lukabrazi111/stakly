<?php

use App\Models\Page;
use Illuminate\Support\Facades\Cache;

/**
 * M26 Phase 1 — `Page` model unit-level behavior. Covers the locale-fallback
 * resolver, the published-at status check, markdown rendering, and the
 * save/delete cache invalidation that lets admin edits appear immediately on
 * the public route.
 */
test('forSlugWithFallback returns exact-locale row when one exists', function () {
    Page::factory()->create(['slug' => 'about', 'locale' => 'en', 'title' => 'About EN']);

    $page = Page::forSlugWithFallback('about', 'en');

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('About EN');
});

test('forSlugWithFallback falls back to default locale when requested locale is missing', function () {
    Page::factory()->create(['slug' => 'about', 'locale' => 'en', 'title' => 'About EN']);

    $page = Page::forSlugWithFallback('about', 'es');

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('About EN')
        ->and($page->locale)->toBe('en');
});

test('forSlugWithFallback returns null when neither requested nor default locale exists', function () {
    $page = Page::forSlugWithFallback('nonexistent', 'en');

    expect($page)->toBeNull();
});

test('forSlugWithFallback prefers exact locale over default fallback', function () {
    Page::factory()->create(['slug' => 'about', 'locale' => 'en', 'title' => 'About EN']);
    Page::factory()->create(['slug' => 'about', 'locale' => 'fr', 'title' => 'À propos']);

    $page = Page::forSlugWithFallback('about', 'fr');

    expect($page->title)->toBe('À propos');
});

test('isPublished is true when published_at is in the past', function () {
    $page = Page::factory()->create(['published_at' => now()->subDay()]);

    expect($page->isPublished())->toBeTrue();
});

test('isPublished is false for drafts (published_at null)', function () {
    $page = Page::factory()->draft()->create();

    expect($page->isPublished())->toBeFalse();
});

test('isPublished is false for scheduled rows (published_at in future)', function () {
    $page = Page::factory()->scheduled(now()->addDay())->create();

    expect($page->isPublished())->toBeFalse();
});

test('renderedHtml converts markdown body to HTML', function () {
    $page = Page::factory()->create([
        'body' => "## Hello\n\nThis is **bold**.",
    ]);

    $html = $page->renderedHtml();

    expect($html)->toContain('<h2>Hello</h2>')
        ->and($html)->toContain('<strong>bold</strong>');
});

test('renderedHtml escapes raw HTML by default (XSS guard)', function () {
    $page = Page::factory()->create([
        'body' => "Hello\n\n<script>alert('xss')</script>",
    ]);

    $html = $page->renderedHtml();

    // CommonMark default config strips raw inline HTML — we never want admin
    // input becoming a vector for script injection on the public page.
    expect($html)->not->toContain('<script>');
});

test('saving a page busts its cache key for every supported locale', function () {
    $page = Page::factory()->create(['slug' => 'about', 'locale' => 'en']);

    foreach (Page::supportedLocales() as $locale) {
        Cache::put(Page::cacheKey('about', $locale), 'sentinel', 60);
    }

    $page->update(['title' => 'New title']);

    foreach (Page::supportedLocales() as $locale) {
        expect(Cache::get(Page::cacheKey('about', $locale)))->toBeNull();
    }
});

test('deleting a page busts its cache key for every supported locale', function () {
    $page = Page::factory()->create(['slug' => 'about', 'locale' => 'en']);

    foreach (Page::supportedLocales() as $locale) {
        Cache::put(Page::cacheKey('about', $locale), 'sentinel', 60);
    }

    $page->delete();

    foreach (Page::supportedLocales() as $locale) {
        expect(Cache::get(Page::cacheKey('about', $locale)))->toBeNull();
    }
});

test('cacheKey is locale-scoped', function () {
    expect(Page::cacheKey('about', 'en'))->toBe('cms.page.en.about')
        ->and(Page::cacheKey('about', 'fr'))->toBe('cms.page.fr.about');
});
