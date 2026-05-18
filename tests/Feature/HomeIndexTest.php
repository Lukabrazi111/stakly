<?php

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
