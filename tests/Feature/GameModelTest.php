<?php

use App\Models\Game;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

// M24 Phase 1 — `Game` model scopes + cache invalidation behavior.

test('forHomepage scope returns Active and ComingSoon, ordered by position', function () {
    $disabled = Game::factory()->disabled()->create(['position' => 5]);
    $comingSoon = Game::factory()->comingSoon()->create(['position' => 20]);
    $active = Game::factory()->active()->create(['position' => 10]);

    $games = Game::forHomepage()->get();

    expect($games)->toHaveCount(2);
    expect($games->pluck('id')->all())->toBe([$active->id, $comingSoon->id]);
    expect($games->pluck('id')->all())->not->toContain($disabled->id);
});

test('ordered scope sorts by position ascending, id tiebreaker', function () {
    $second = Game::factory()->create(['position' => 10]);
    $first = Game::factory()->create(['position' => 5]);
    $third = Game::factory()->create(['position' => 10]);

    expect(Game::ordered()->pluck('id')->all())
        ->toBe([$first->id, $second->id, $third->id]);
});

test('hasBackendIntegration true only for Active games whose slug matches an enum case', function () {
    // `App\Enums\Game` carries `chess`, `cs2`, `dota2` today — cs2 + dota2
    // are M15 placeholders. `valorant` is in the catalog but NOT in the
    // enum, which is what makes it the right "non-enum slug" example here.
    $chess = Game::factory()->active()->create(['slug' => 'chess']);
    $valorantActive = Game::factory()->active()->create(['slug' => 'valorant']);
    $valorantComingSoon = Game::factory()->comingSoon()->create(['slug' => 'lol']);

    expect($chess->hasBackendIntegration())->toBeTrue();

    // Even when admin (hypothetically) flips a non-enum slug to Active,
    // hasBackendIntegration stays false. The Filament resource validation
    // should prevent this state from happening in practice — this assertion
    // verifies the model-level safety net.
    expect($valorantActive->hasBackendIntegration())->toBeFalse();

    // ComingSoon never counts as backend-integrated regardless of slug.
    expect($valorantComingSoon->hasBackendIntegration())->toBeFalse();
});

test('homepage cache is invalidated when a Game is saved', function () {
    Game::factory()->active()->create(['slug' => 'chess']);

    // Prime the cache via the homepage controller.
    $this->get('/');
    expect(Cache::has(Game::HOMEPAGE_CACHE_KEY))->toBeTrue();

    // Any save (create here) should bust the cache.
    Game::factory()->comingSoon()->create(['slug' => 'cs2']);
    expect(Cache::has(Game::HOMEPAGE_CACHE_KEY))->toBeFalse();
});

test('homepage cache is invalidated when a Game is deleted', function () {
    $cs2 = Game::factory()->comingSoon()->create(['slug' => 'cs2']);

    $this->get('/');
    expect(Cache::has(Game::HOMEPAGE_CACHE_KEY))->toBeTrue();

    $cs2->delete();
    expect(Cache::has(Game::HOMEPAGE_CACHE_KEY))->toBeFalse();
});

test('slug uniqueness is enforced at the DB level', function () {
    Game::factory()->create(['slug' => 'chess']);

    expect(fn () => Game::factory()->create(['slug' => 'chess']))
        ->toThrow(UniqueConstraintViolationException::class);
});
