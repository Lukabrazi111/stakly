<?php

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Listing;
use App\Models\User;

test('homepage is publicly accessible and renders welcome', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('welcome'));
});

test('featured strip ships up to 4 open listings sorted by ending-soon', function () {
    // 5 open listings, expires_at spread out — only the 4 soonest should ship.
    $first = Listing::factory()->open()->create(['expires_at' => now()->addHour()]);
    $second = Listing::factory()->open()->create(['expires_at' => now()->addHours(2)]);
    $third = Listing::factory()->open()->create(['expires_at' => now()->addHours(3)]);
    $fourth = Listing::factory()->open()->create(['expires_at' => now()->addHours(4)]);
    $fifth = Listing::factory()->open()->create(['expires_at' => now()->addDays(2)]);

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->has('featured.data', 4)
        ->where('featured.data.0.id', $first->id)
        ->where('featured.data.1.id', $second->id)
        ->where('featured.data.2.id', $third->id)
        ->where('featured.data.3.id', $fourth->id)
    );

    // The 5th (later-expiring) listing is intentionally omitted.
    expect($fifth)->not->toBeNull();
});

test('featured strip omits non-open listings', function () {
    Listing::factory()->open()->create(['expires_at' => now()->addHour()]);
    Listing::factory()->taken()->create();
    Listing::factory()->expired()->create();
    Listing::factory()->cancelled()->create();

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page->has('featured.data', 1));
});

test('featured strip is empty when no open listings exist', function () {
    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page->has('featured.data', 0));
});

test('featured listings whitelist creator (no email leak)', function () {
    $user = User::factory()->active()->create(['email' => 'private@example.com']);
    Listing::factory()->open()->for($user)->create();

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->has('featured.data.0.creator', fn ($creator) => $creator
            ->has('id')
            ->has('name')
            ->etc()
        )
    );
    $response->assertDontSee('private@example.com');
});

// M24 Phase 1 — GameSelector reads its catalog from the `games` table via
// the `games` Inertia prop. Tiles are ordered by `position`, Disabled rows
// are hidden, Active + ComingSoon both surface.

test('games prop ships tiles ordered by position', function () {
    Game::factory()->create(['slug' => 'zeta', 'position' => 30]);
    Game::factory()->create(['slug' => 'alpha', 'position' => 10]);
    Game::factory()->create(['slug' => 'beta', 'position' => 20]);

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->has('games.data', 3)
        ->where('games.data.0.slug', 'alpha')
        ->where('games.data.1.slug', 'beta')
        ->where('games.data.2.slug', 'zeta')
    );
});

test('games prop excludes Disabled status tiles', function () {
    Game::factory()->active()->create(['slug' => 'chess', 'position' => 10]);
    Game::factory()->comingSoon()->create(['slug' => 'cs2', 'position' => 20]);
    Game::factory()->disabled()->create(['slug' => 'parked', 'position' => 30]);

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->has('games.data', 2)
        ->where('games.data.0.slug', 'chess')
        ->where('games.data.1.slug', 'cs2')
    );
});

test('games prop emits whitelisted fields only', function () {
    Game::factory()->active()->create([
        'slug' => 'chess',
        'display_name' => 'Chess',
        'poster_path' => '/images/games/chess.png',
        'position' => 10,
    ]);

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->has('games.data.0', fn ($tile) => $tile
            ->where('slug', 'chess')
            ->where('display_name', 'Chess')
            ->where('poster_path', '/images/games/chess.png')
            ->where('status', GameStatus::Active->value)
        )
    );
});

test('admin-uploaded poster path resolves to /storage URL', function () {
    // Filament FileUpload stores disk-relative paths (e.g. `games/abc.webp`).
    // GameResource normalizes these through Storage::url so the frontend
    // doesn't have to know which storage backend produced the file.
    Game::factory()->comingSoon()->create([
        'slug' => 'admin-upload',
        'poster_path' => 'games/abc123.webp',
        'position' => 5,
    ]);

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->where('games.data.0.poster_path', '/storage/games/abc123.webp')
    );
});
