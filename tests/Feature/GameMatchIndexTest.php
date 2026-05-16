<?php

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| /matches index page (M6 Phase 6.1)
|--------------------------------------------------------------------------
|
| Verifies:
|   - guests + unverified users are redirected by auth + verified middleware
|   - a player sees matches where they're the creator (via listing) OR taker
|   - a player NEVER sees other players' matches
|   - filter[status] chip works, invalid status redirects cleanly
|   - pagination caps at 12 per page, newest first
|
*/

test('guests are redirected to login', function () {
    $this->get('/matches')->assertRedirect(route('login'));
});

test('unverified users are redirected to the verification notice', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/matches')
        ->assertRedirect(route('verification.notice'));
});

test('authenticated user sees the /matches page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/matches')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('match/index')
            ->has('matches.data')
            ->has('matches.meta')
            ->has('filters')
        );
});

test('matches where user is the creator are included', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $listing = Listing::factory()->taken()->for($alice)->create();
    $match = GameMatch::factory()
        ->for($listing)
        ->for($bob, 'taker')
        ->create();

    $this->actingAs($alice)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->has('matches.data', 1)
            ->where('matches.data.0.id', $match->id)
        );
});

test('matches where user is the taker are included', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $listing = Listing::factory()->taken()->for($alice)->create();
    $match = GameMatch::factory()
        ->for($listing)
        ->for($bob, 'taker')
        ->create();

    $this->actingAs($bob)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->has('matches.data', 1)
            ->where('matches.data.0.id', $match->id)
        );
});

test('matches where user is neither creator nor taker are NOT visible', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $carol = User::factory()->create();

    // Match between Alice (creator) and Bob (taker)
    $listing = Listing::factory()->taken()->for($alice)->create();
    GameMatch::factory()
        ->for($listing)
        ->for($bob, 'taker')
        ->create();

    // Carol shouldn't see it
    $this->actingAs($carol)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page->has('matches.data', 0));
});

test('filter[status]=pending scopes to Pending matches only', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    // Pending
    $pendingListing = Listing::factory()->taken()->for($alice)->create();
    $pending = GameMatch::factory()
        ->for($pendingListing)
        ->for($bob, 'taker')
        ->create();

    // Settled
    $settledListing = Listing::factory()->taken()->for($alice)->create();
    GameMatch::factory()
        ->for($settledListing)
        ->for($bob, 'taker')
        ->settled($alice)
        ->create();

    $this->actingAs($alice)
        ->get('/matches?filter[status]=pending')
        ->assertInertia(fn ($page) => $page
            ->has('matches.data', 1)
            ->where('matches.data.0.id', $pending->id)
            ->where('matches.data.0.status', MatchStatus::Pending->value)
            ->where('filters.status', 'pending')
        );
});

test('filter[status]=settled scopes to Settled matches only', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $pendingListing = Listing::factory()->taken()->for($alice)->create();
    GameMatch::factory()
        ->for($pendingListing)
        ->for($bob, 'taker')
        ->create();

    $settledListing = Listing::factory()->taken()->for($alice)->create();
    $settled = GameMatch::factory()
        ->for($settledListing)
        ->for($bob, 'taker')
        ->settled($alice)
        ->create();

    $this->actingAs($alice)
        ->get('/matches?filter[status]=settled')
        ->assertInertia(fn ($page) => $page
            ->has('matches.data', 1)
            ->where('matches.data.0.id', $settled->id)
            ->where('matches.data.0.status', MatchStatus::Settled->value)
        );
});

test('an unknown filter[status] redirects to a clean /matches', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/matches?filter[status]=alien_invasion')
        ->assertRedirect('/matches');
});

test('pagination caps at 12 per page', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    foreach (range(1, 15) as $i) {
        $listing = Listing::factory()->taken()->for($alice)->create();
        GameMatch::factory()
            ->for($listing)
            ->for($bob, 'taker')
            ->create();
    }

    $this->actingAs($alice)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->has('matches.data', 12)
            ->where('matches.meta.total', 15)
            ->where('matches.meta.per_page', 12)
        );
});

test('rows are returned newest first', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $oldListing = Listing::factory()->taken()->for($alice)->create();
    $older = GameMatch::factory()
        ->for($oldListing)
        ->for($bob, 'taker')
        ->create(['created_at' => now()->subHours(2)]);

    $newListing = Listing::factory()->taken()->for($alice)->create();
    $newer = GameMatch::factory()
        ->for($newListing)
        ->for($bob, 'taker')
        ->create(['created_at' => now()]);

    $this->actingAs($alice)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->where('matches.data.0.id', $newer->id)
            ->where('matches.data.1.id', $older->id)
        );
});

test('match resource on index includes the opponent (creator + taker) and listing', function () {
    $alice = User::factory()->create(['username' => 'alice', 'name' => 'Alice']);
    $bob = User::factory()->create(['username' => 'bob', 'name' => 'Bob']);

    $listing = Listing::factory()
        ->taken()
        ->for($alice)
        ->state(['stake_amount' => '50'])
        ->create();

    GameMatch::factory()
        ->for($listing)
        ->for($bob, 'taker')
        ->create();

    $this->actingAs($alice)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->where('matches.data.0.creator.username', 'alice')
            ->where('matches.data.0.taker.username', 'bob')
            ->where('matches.data.0.listing.id', $listing->id)
            // JSON int/float drift: `(float) 50` serializes as `50` (int) in
            // the response payload (no decimal portion). Assert as int to
            // match Inertia's decoded shape. See milestones.md M7 notes.
            ->where('matches.data.0.listing.stake_amount', 50)
        );
});
