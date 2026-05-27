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

// ─── Pot breakdown payload (M23 Phase 2) ─────────────────────────────────

test('listing carries fee_rate from config for the pot breakdown', function () {
    // M23 Phase 2 — detail page renders pot / fee / payout breakdown
    // pre-take. Frontend reads `listing.fee_rate` (mirror of
    // `match.fee_rate` on `GameMatchResource`) and computes the math
    // client-side so the rate is single-sourced from config and never
    // duplicated on the FE.
    $listing = Listing::factory()->open()->create();

    $this->get("/listings/{$listing->id}")
        ->assertInertia(fn ($page) => $page
            ->where('listing.fee_rate', (float) config('stakly.platform_fee_rate'))
        );
});

// ─── Detail-page creator card payload (M23 Phase 1) ──────────────────────

test('creator carries bio, member_since, and linked_accounts on the detail page', function () {
    // Detail-page creator card (M23 Phase 1) renders bio, a Joined month-year
    // pill from member_since, and one VerificationChip per linked account.
    // Each chip needs (provider, username) to click out to the external profile
    // — that's a richer shape than the marketplace row's verified_providers
    // (provider list only). All three fields should be on the resource.
    $creator = User::factory()
        ->active()
        ->withChessCom('grandmaster99')
        ->withLichess('blitzqueen')
        ->create(['bio' => 'Endgame specialist, blitz enthusiast.']);
    $listing = Listing::factory()->open()->for($creator)->create();

    $this->get("/listings/{$listing->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('listing.creator.bio', 'Endgame specialist, blitz enthusiast.')
            ->where('listing.creator.member_since', $creator->created_at->toIso8601String())
            ->where('listing.creator.linked_accounts', function ($accounts) {
                $rows = collect($accounts)->values()->all();
                if (count($rows) !== 2) {
                    return false;
                }
                $byProvider = collect($rows)->keyBy(fn ($r) => $r['provider'] ?? null);

                return ($byProvider['chess_com']['username'] ?? null) === 'grandmaster99'
                    && ($byProvider['lichess']['username'] ?? null) === 'blitzqueen';
            })
        );
});

test('creator.bio is null when the user has not set one', function () {
    $creator = User::factory()->active()->create(['bio' => null]);
    $listing = Listing::factory()->open()->for($creator)->create();

    $this->get("/listings/{$listing->id}")
        ->assertInertia(fn ($page) => $page
            ->where('listing.creator.bio', null)
        );
});

test('creator.linked_accounts is an empty array when the user has linked none', function () {
    // No bio-code flow completed — Marketplace gate prevents this user from
    // *creating* a listing in practice, but the show endpoint must still
    // serialize cleanly with an empty array (rather than null or omitted).
    $creator = User::factory()->active()->create();
    $listing = Listing::factory()->open()->for($creator)->create();

    $this->get("/listings/{$listing->id}")
        ->assertInertia(fn ($page) => $page
            ->where('listing.creator.linked_accounts', [])
        );
});

// ─── Creator active mode exposed (M6 Phase 6.5) ───────────────────────────

test('creator.is_active_mode is exposed on the listing detail resource', function () {
    // The listing detail page is the one public surface where a non-owner
    // can see an inactive owner's listing (it bypasses `scopeOnPublicMarketplace`
    // so direct URLs still resolve). Frontend uses `creator.is_active_mode`
    // to gate the Take button + show an "inactive" banner; server-side gate
    // in `GameMatchController::take` remains authoritative.
    $active = User::factory()->active()->create();
    $inactive = User::factory()->inactive()->create();

    $listingByActive = Listing::factory()->open()->for($active)->create();
    $listingByInactive = Listing::factory()->open()->for($inactive)->create();

    $this->get("/listings/{$listingByActive->id}")
        ->assertInertia(fn ($page) => $page->where('listing.creator.is_active_mode', true));

    $this->get("/listings/{$listingByInactive->id}")
        ->assertInertia(fn ($page) => $page->where('listing.creator.is_active_mode', false));
});
