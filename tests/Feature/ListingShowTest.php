<?php

use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;

test('public listing detail page renders for any visitor', function () {
    $listing = Listing::factory()->open()->create();

    $response = $this->get("/listings/{$listing->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('listings/show')
        ->where('listing.id', $listing->id)
        ->where('listing.status', 'open')
        ->has('listing.creator', fn ($creator) => $creator
            ->where('id', $listing->user_id)
            ->where('name', $listing->user->name)
            ->etc()
        )
    );
});

test('show returns 404 for a non-existent listing id', function () {
    $this->get('/listings/999999')->assertNotFound();
});

test('non-open listings (taken / expired / cancelled) still render with their status', function () {
    $taken = Listing::factory()->taken()->create();
    $expired = Listing::factory()->expired()->create();
    $cancelled = Listing::factory()->cancelled()->create();

    $this->get("/listings/{$taken->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('listing.status', 'taken'));

    $this->get("/listings/{$expired->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('listing.status', 'expired'));

    $this->get("/listings/{$cancelled->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('listing.status', 'cancelled'));
});

test('show resource never leaks the creator email or other sensitive fields', function () {
    $owner = User::factory()->create(['email' => 'leak-check@example.com']);
    $listing = Listing::factory()->open()->for($owner)->create();

    $response = $this->get("/listings/{$listing->id}");

    $response->assertDontSee('leak-check@example.com');
});

// ─── "View match →" link (M6 Phase 6.3) ──────────────────────────────────

test('match prop is null on Open listings (no match exists yet)', function () {
    $listing = Listing::factory()->open()->create();

    $this->get("/listings/{$listing->id}")
        ->assertInertia(fn ($page) => $page->where('match', null));
});

test('match prop is null for unauthenticated guests on Taken listings', function () {
    $listing = Listing::factory()->taken()->create();
    GameMatch::factory()->for($listing)->create();

    $this->get("/listings/{$listing->id}")
        ->assertInertia(fn ($page) => $page->where('match', null));
});

test('match prop is null for non-participants on Taken listings', function () {
    $creator = User::factory()->create();
    $taker = User::factory()->create();
    $randomViewer = User::factory()->create();

    $listing = Listing::factory()->taken()->for($creator)->create();
    GameMatch::factory()->for($listing)->for($taker, 'taker')->create();

    $this->actingAs($randomViewer)
        ->get("/listings/{$listing->id}")
        ->assertInertia(fn ($page) => $page->where('match', null));
});

test('match prop is populated for the creator on a Taken listing', function () {
    $creator = User::factory()->create();
    $taker = User::factory()->create();

    $listing = Listing::factory()->taken()->for($creator)->create();
    $match = GameMatch::factory()->for($listing)->for($taker, 'taker')->create();

    $this->actingAs($creator)
        ->get("/listings/{$listing->id}")
        ->assertInertia(fn ($page) => $page
            ->where('match.id', $match->id)
        );
});

test('match prop is populated for the taker on a Taken listing', function () {
    $creator = User::factory()->create();
    $taker = User::factory()->create();

    $listing = Listing::factory()->taken()->for($creator)->create();
    $match = GameMatch::factory()->for($listing)->for($taker, 'taker')->create();

    $this->actingAs($taker)
        ->get("/listings/{$listing->id}")
        ->assertInertia(fn ($page) => $page
            ->where('match.id', $match->id)
        );
});

// ─── Creator active mode exposed (M6 Phase 6.5) ───────────────────────────

test('creator.is_active_mode is exposed on the listing detail resource', function () {
    // The listing detail page is the one public surface where a non-owner
    // can see an inactive owner's listing (it bypasses `scopeOnPublicMarketplace`
    // so direct URLs still resolve). Frontend uses `creator.is_active_mode`
    // to gate the Take button + show an "inactive" banner; server-side gate
    // in `GameMatchController::take` remains authoritative.
    $active = User::factory()->create();
    $inactive = User::factory()->inactive()->create();

    $listingByActive = Listing::factory()->open()->for($active)->create();
    $listingByInactive = Listing::factory()->open()->for($inactive)->create();

    $this->get("/listings/{$listingByActive->id}")
        ->assertInertia(fn ($page) => $page->where('listing.creator.is_active_mode', true));

    $this->get("/listings/{$listingByInactive->id}")
        ->assertInertia(fn ($page) => $page->where('listing.creator.is_active_mode', false));
});
