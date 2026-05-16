<?php

use App\Http\Requests\Listings\StoreListingRequest;
use App\Models\Listing;
use App\Models\User;

// ─── Auth gates ───────────────────────────────────────────────────────────

test('guests are redirected to login when hitting /listings/mine', function () {
    $this->get('/listings/mine')->assertRedirect(route('login'));
});

test('unverified users are blocked from /listings/mine by the verified middleware', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/listings/mine')
        ->assertRedirect(route('verification.notice'));
});

test('platform user gets 403 on /listings/mine — management surface is player-only', function () {
    $platform = User::factory()->create(['is_platform' => true]);

    $this->actingAs($platform)
        ->get('/listings/mine')
        ->assertForbidden();
});

// ─── Page renders ─────────────────────────────────────────────────────────

test('a verified player sees their listings page with the expected props', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/listings/mine');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('listings/mine')
        ->has('listings.data')
        ->where('tab', 'listed')
        ->where('activeCount', 0)
        ->where('maxActive', StoreListingRequest::MAX_ACTIVE_LISTINGS)
    );
});

// ─── Ownership scoping ────────────────────────────────────────────────────

test('only the auth user listings appear on /listings/mine', function () {
    $me = User::factory()->create();
    $stranger = User::factory()->create();

    Listing::factory()->open()->for($me)->count(2)->create();
    Listing::factory()->open()->for($stranger)->count(3)->create();

    $response = $this->actingAs($me)->get('/listings/mine');

    $response->assertInertia(fn ($page) => $page
        ->has('listings.data', 2)
        ->where('listings.data.0.creator.id', $me->id)
        ->where('listings.data.1.creator.id', $me->id)
    );
});

// ─── Tab scoping ──────────────────────────────────────────────────────────

test('listed tab shows only Open listings', function () {
    $user = User::factory()->create();

    Listing::factory()->open()->for($user)->count(2)->create();
    Listing::factory()->taken()->for($user)->create();
    Listing::factory()->expired()->for($user)->create();
    Listing::factory()->cancelled()->for($user)->create();

    $this->actingAs($user)
        ->get('/listings/mine?tab=listed')
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'listed')
            ->has('listings.data', 2)
        );
});

test('all tab shows every status (Open + Taken + Expired + Cancelled)', function () {
    $user = User::factory()->create();

    Listing::factory()->open()->for($user)->count(2)->create();
    Listing::factory()->taken()->for($user)->create();
    Listing::factory()->expired()->for($user)->create();
    Listing::factory()->cancelled()->for($user)->create();

    $this->actingAs($user)
        ->get('/listings/mine?tab=all')
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'all')
            ->has('listings.data', 5)
        );
});

test('default tab (no query param) is listed', function () {
    $user = User::factory()->create();
    Listing::factory()->open()->for($user)->create();
    Listing::factory()->cancelled()->for($user)->create();

    $this->actingAs($user)
        ->get('/listings/mine')
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'listed')
            ->has('listings.data', 1)
        );
});

test('bad tab values silently fall back to listed', function (string $bad) {
    $user = User::factory()->create();
    Listing::factory()->open()->for($user)->create();
    Listing::factory()->cancelled()->for($user)->create();

    $this->actingAs($user)
        ->get('/listings/mine?tab='.$bad)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'listed')
            ->has('listings.data', 1)
        );
})->with(['bogus', 'open', 'cancelled', 'LISTED']);

// ─── Pagination ───────────────────────────────────────────────────────────

test('pagination is 12 per page on /listings/mine', function () {
    $user = User::factory()->create();
    Listing::factory()->open()->for($user)->count(20)->create();

    $this->actingAs($user)
        ->get('/listings/mine?tab=all')
        ->assertInertia(fn ($page) => $page
            ->has('listings.data', 12)
            ->where('listings.meta.total', 20)
        );

    $this->actingAs($user)
        ->get('/listings/mine?tab=all&page=2')
        ->assertInertia(fn ($page) => $page->has('listings.data', 8));
});

// ─── activeCount / maxActive props ───────────────────────────────────────

test('activeCount counts only Open listings (not Taken / Expired / Cancelled)', function () {
    $user = User::factory()->create();

    Listing::factory()->open()->for($user)->count(2)->create();
    Listing::factory()->taken()->for($user)->create();
    Listing::factory()->expired()->for($user)->create();
    Listing::factory()->cancelled()->for($user)->create();

    $this->actingAs($user)
        ->get('/listings/mine?tab=all')
        ->assertInertia(fn ($page) => $page
            ->where('activeCount', 2)
            ->where('maxActive', 2)
        );
});

// ─── Cross-cut: Inactive Mode does NOT hide the owner's own view ──────────

test('an inactive owner still sees their own listings on /listings/mine', function () {
    // Active Mode hides listings from public surfaces (marketplace, visitor
    // profile) but the owner should always see what's theirs — that's how
    // they manage / cancel / re-activate.
    $user = User::factory()->inactive()->create();
    Listing::factory()->open()->for($user)->count(3)->create();

    $this->actingAs($user)
        ->get('/listings/mine')
        ->assertInertia(fn ($page) => $page
            ->has('listings.data', 3)
            ->where('activeCount', 3)
        );
});
