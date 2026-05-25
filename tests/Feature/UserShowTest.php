<?php

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Services\Wallet;

// ─── Basic show + auth context ────────────────────────────────────────────

test('public profile renders for a guest visitor', function () {
    $user = User::factory()->create(['username' => 'alice', 'name' => 'Alice']);

    $response = $this->get('/users/alice');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('users/show')
        ->where('user.username', 'alice')
        ->where('user.name', 'Alice')
        ->has('user.member_since')
        ->where('user.avatar_url', null)
        ->where('user.avatar_thumb_url', null)
        ->where('stats.total_matches', 0)
        ->where('stats.total_volume', 0)
        ->where('stats.win_rate', null)
    );
});

// ─── 404 paths ────────────────────────────────────────────────────────────

test('unknown username returns 404', function () {
    $this->get('/users/does-not-exist')->assertNotFound();
});

test('platform user returns 404 even when the username is known', function () {
    User::factory()->create([
        'is_platform' => true,
        'username' => 'stakly-platform',
        'email' => 'platform@stakly.internal',
        'name' => 'Stakly Platform',
    ]);

    $this->get('/users/stakly-platform')->assertNotFound();
});

// ─── PII safety ───────────────────────────────────────────────────────────

test('profile resource never leaks email, usdt_balance, or is_platform', function () {
    $user = User::factory()->create([
        'username' => 'dave',
        'email' => 'dave-secret@example.com',
    ]);
    Wallet::deposit($user, '12345.67', reference: "test:deposit:{$user->id}");

    $response = $this->get('/users/dave');

    $response->assertOk();
    // Hard-string sweep: the full HTML response must not contain any of these.
    $response->assertDontSee('dave-secret@example.com');
    $response->assertDontSee('12345.67');
    $response->assertDontSee('is_platform');
    // Resource-level sweep: only whitelisted keys exist on the `user` prop.
    $response->assertInertia(fn ($page) => $page
        ->missing('user.email')
        ->missing('user.usdt_balance')
        ->missing('user.is_platform')
        ->missing('user.two_factor_secret')
        ->missing('user.password')
    );
});

// ─── openListings scoping ────────────────────────────────────────────────

test('openListings includes only the profile owner open listings', function () {
    $owner = User::factory()->active()->create(['username' => 'erin']);
    $other = User::factory()->active()->create(['username' => 'frank']);

    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");
    Wallet::deposit($other, '1000', reference: "test:deposit:{$other->id}");

    $ownerListing = Listing::factory()->open()->for($owner)->state(['stake_amount' => '50'])->create();
    Wallet::hold(user: $owner, amount: '50', listing: $ownerListing, reference: "listing-create:{$ownerListing->id}");

    $otherListing = Listing::factory()->open()->for($other)->state(['stake_amount' => '50'])->create();
    Wallet::hold(user: $other, amount: '50', listing: $otherListing, reference: "listing-create:{$otherListing->id}");

    $response = $this->get('/users/erin');

    $response->assertInertia(fn ($page) => $page
        ->has('openListings.data', 1)
        ->where('openListings.data.0.id', $ownerListing->id)
    );
});

test('openListings excludes taken / expired / cancelled listings', function () {
    $owner = User::factory()->active()->create(['username' => 'george']);
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");

    $open = Listing::factory()->open()->for($owner)->state(['stake_amount' => '50'])->create();
    Wallet::hold(user: $owner, amount: '50', listing: $open, reference: "listing-create:{$open->id}");

    // Non-open statuses — their wallet history is not reconstructed here.
    Listing::factory()->taken()->for($owner)->create();
    Listing::factory()->expired()->for($owner)->create();
    Listing::factory()->cancelled()->for($owner)->create();

    $response = $this->get('/users/george');

    $response->assertInertia(fn ($page) => $page
        ->has('openListings.data', 1)
        ->where('openListings.data.0.id', $open->id)
    );
});

test('openListings is capped at 5, newest first', function () {
    $owner = User::factory()->active()->create(['username' => 'helen']);
    Wallet::deposit($owner, '10000', reference: "test:deposit:{$owner->id}");

    foreach (range(1, 7) as $i) {
        $listing = Listing::factory()->open()->for($owner)->state(['stake_amount' => '50'])->create();
        Wallet::hold(user: $owner, amount: '50', listing: $listing, reference: "listing-create:{$listing->id}");
    }

    $response = $this->get('/users/helen');

    $response->assertInertia(fn ($page) => $page->has('openListings.data', 5));
});

// ─── Stats (M18 Phase 2 — profile stats hero) ────────────────────────────

test('stats.total_matches counts only Settled matches, not Pending/Cancelled', function () {
    $owner = User::factory()->create(['username' => 'iris']);
    $opp = User::factory()->create();

    $settledListing = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '100'])->create();
    GameMatch::factory()->for($settledListing)->for($opp, 'taker')->settled($owner)->create();

    $pendingListing = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '100'])->create();
    GameMatch::factory()->for($pendingListing)->for($opp, 'taker')->create();

    $cancelledListing = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '100'])->create();
    GameMatch::factory()->for($cancelledListing)->for($opp, 'taker')->cancelled($owner)->create();

    $this->get('/users/iris')
        ->assertInertia(fn ($page) => $page->where('stats.total_matches', 1));
});

test('stats.total_volume sums stake_amount across settled matches', function () {
    $owner = User::factory()->create(['username' => 'jules']);
    $opp = User::factory()->create();

    $first = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '100'])->create();
    GameMatch::factory()->for($first)->for($opp, 'taker')->settled($owner)->create();

    $second = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '250'])->create();
    GameMatch::factory()->for($second)->for($opp, 'taker')->settled($opp)->create();

    // Pending match shouldn't contribute.
    $pending = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '999'])->create();
    GameMatch::factory()->for($pending)->for($opp, 'taker')->create();

    $this->get('/users/jules')
        ->assertInertia(fn ($page) => $page->where('stats.total_volume', 350));
});

test('stats.win_rate is included only when viewing own profile', function () {
    $owner = User::factory()->create(['username' => 'kara']);
    $opp = User::factory()->create();

    $listing = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '100'])->create();
    GameMatch::factory()->for($listing)->for($opp, 'taker')->settled($owner)->create();

    // Visitor — no win_rate.
    $this->get('/users/kara')
        ->assertInertia(fn ($page) => $page->where('stats.win_rate', null));

    // Owner — win_rate present.
    $this->actingAs($owner)
        ->get('/users/kara')
        ->assertInertia(fn ($page) => $page
            ->has('stats.win_rate', fn ($w) => $w
                ->where('wins', 1)
                ->where('draws', 0)
                ->where('losses', 0)
                ->where('percentage', 100)
            )
        );
});

test('stats.win_rate percentage excludes draws from denominator', function () {
    $owner = User::factory()->create(['username' => 'liam']);
    $opp = User::factory()->create();

    // 2 wins
    foreach (range(1, 2) as $i) {
        $listing = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '50'])->create();
        GameMatch::factory()->for($listing)->for($opp, 'taker')->settled($owner)->create();
    }
    // 1 draw (Settled but winner_user_id is null)
    $drawListing = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '50'])->create();
    GameMatch::factory()->for($drawListing)->for($opp, 'taker')->state([
        'status' => MatchStatus::Settled,
        'settled_at' => now(),
        'winner_user_id' => null,
    ])->create();
    // 1 loss
    $lossListing = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '50'])->create();
    GameMatch::factory()->for($lossListing)->for($opp, 'taker')->settled($opp)->create();

    // 2W / 1D / 1L → decided = 3 → 2/3 = 67%
    $this->actingAs($owner)
        ->get('/users/liam')
        ->assertInertia(fn ($page) => $page
            ->has('stats.win_rate', fn ($w) => $w
                ->where('wins', 2)
                ->where('draws', 1)
                ->where('losses', 1)
                ->where('percentage', 67)
            )
        );
});

test('stats.win_rate.percentage is null when every settled match is a draw', function () {
    $owner = User::factory()->create(['username' => 'mira']);
    $opp = User::factory()->create();

    foreach (range(1, 2) as $i) {
        $listing = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '50'])->create();
        GameMatch::factory()->for($listing)->for($opp, 'taker')->state([
            'status' => MatchStatus::Settled,
            'settled_at' => now(),
            'winner_user_id' => null,
        ])->create();
    }

    $this->actingAs($owner)
        ->get('/users/mira')
        ->assertInertia(fn ($page) => $page
            ->has('stats.win_rate', fn ($w) => $w
                ->where('wins', 0)
                ->where('draws', 2)
                ->where('losses', 0)
                ->where('percentage', null)
            )
        );
});

test('stats.win_rate is null on own profile when there are no settled matches', function () {
    $owner = User::factory()->create(['username' => 'nico']);

    $this->actingAs($owner)
        ->get('/users/nico')
        ->assertInertia(fn ($page) => $page
            ->where('stats.total_matches', 0)
            ->where('stats.total_volume', 0)
            ->where('stats.win_rate', null)
        );
});

test('stats count matches where the user is creator OR taker', function () {
    $owner = User::factory()->create(['username' => 'omar']);
    $opp = User::factory()->create();

    // As creator
    $creatorListing = Listing::factory()->taken()->for($owner)->state(['stake_amount' => '100'])->create();
    GameMatch::factory()->for($creatorListing)->for($opp, 'taker')->settled($owner)->create();

    // As taker
    $takerListing = Listing::factory()->taken()->for($opp)->state(['stake_amount' => '200'])->create();
    GameMatch::factory()->for($takerListing)->for($owner, 'taker')->settled($owner)->create();

    $this->get('/users/omar')
        ->assertInertia(fn ($page) => $page
            ->where('stats.total_matches', 2)
            ->where('stats.total_volume', 300)
        );
});

// ─── Bio shape ────────────────────────────────────────────────────────────

test('bio renders when set', function () {
    User::factory()->create([
        'username' => 'jane',
        'bio' => 'I love chess and I will beat you.',
    ]);

    $response = $this->get('/users/jane');

    $response->assertInertia(fn ($page) => $page
        ->where('user.bio', 'I love chess and I will beat you.')
    );
});

test('bio is null when unset', function () {
    User::factory()->create(['username' => 'kate', 'bio' => null]);

    $response = $this->get('/users/kate');

    $response->assertInertia(fn ($page) => $page->where('user.bio', null));
});

// ─── matchHistory (M6 Phase 6.2) ─────────────────────────────────────────

test('matchHistory includes settled matches where the profile user was a participant', function () {
    $alice = User::factory()->create(['username' => 'alice']);
    $bob = User::factory()->create();

    $listing = Listing::factory()->taken()->for($alice)->create();
    $match = GameMatch::factory()
        ->for($listing)
        ->for($bob, 'taker')
        ->settled($alice)
        ->create();

    $response = $this->get('/users/alice');

    $response->assertInertia(fn ($page) => $page
        ->has('matchHistory.data', 1)
        ->where('matchHistory.data.0.id', $match->id)
        ->where('matchHistory.data.0.status', MatchStatus::Settled->value)
    );
});

test('matchHistory excludes pending / disputed / manual_review matches', function () {
    $alice = User::factory()->create(['username' => 'alice']);
    $bob = User::factory()->create();

    foreach ([MatchStatus::Pending, MatchStatus::Disputed, MatchStatus::ManualReview] as $status) {
        $listing = Listing::factory()->taken()->for($alice)->create();
        GameMatch::factory()
            ->for($listing)
            ->for($bob, 'taker')
            ->state(['status' => $status])
            ->create();
    }

    $this->get('/users/alice')
        ->assertInertia(fn ($page) => $page->has('matchHistory.data', 0));
});

test('matchHistory excludes matches the profile user was NOT in', function () {
    $alice = User::factory()->create(['username' => 'alice']);
    $bob = User::factory()->create();
    $carol = User::factory()->create();

    // Match between Bob (creator) and Carol (taker) — Alice unrelated
    $listing = Listing::factory()->taken()->for($bob)->create();
    GameMatch::factory()
        ->for($listing)
        ->for($carol, 'taker')
        ->settled($bob)
        ->create();

    $this->get('/users/alice')
        ->assertInertia(fn ($page) => $page->has('matchHistory.data', 0));
});

test('matchHistory caps at 10, newest settled first', function () {
    $alice = User::factory()->create(['username' => 'alice']);
    $bob = User::factory()->create();

    foreach (range(1, 12) as $i) {
        $listing = Listing::factory()->taken()->for($alice)->create();
        GameMatch::factory()
            ->for($listing)
            ->for($bob, 'taker')
            ->settled($alice)
            ->create(['settled_at' => now()->subHours(12 - $i)]);
    }

    $this->get('/users/alice')
        ->assertInertia(fn ($page) => $page->has('matchHistory.data', 10));
});

// ─── Active Mode visibility (M6 Phase 6.5) ────────────────────────────────

test('a visitor on an inactive owner profile sees no openListings', function () {
    // Visitor branch uses `onPublicMarketplace` which checks
    // `user.is_active_mode = true`. An inactive owner's Open listings still
    // exist in the DB (escrow held) but disappear from the public profile.
    $owner = User::factory()->inactive()->create(['username' => 'noah']);
    Listing::factory()->open()->for($owner)->count(3)->create();

    $this->get('/users/noah')
        ->assertInertia(fn ($page) => $page->has('openListings.data', 0));
});

test('the owner viewing their own inactive profile still sees their listings', function () {
    // Owner branch uses `open` (not `onPublicMarketplace`) so the listings
    // are still visible to the owner regardless of Active Mode — they need
    // to see what's hidden so they can manage from the profile too.
    $owner = User::factory()->inactive()->create(['username' => 'olive']);
    Listing::factory()->open()->for($owner)->count(3)->create();

    $this->actingAs($owner)
        ->get('/users/olive')
        ->assertInertia(fn ($page) => $page->has('openListings.data', 3));
});

test('flipping the owner back to active republishes their listings on the public profile', function () {
    $owner = User::factory()->inactive()->create(['username' => 'pam']);
    Listing::factory()->open()->for($owner)->count(2)->create();

    $this->get('/users/pam')
        ->assertInertia(fn ($page) => $page->has('openListings.data', 0));

    $owner->update(['is_active_mode' => true]);

    $this->get('/users/pam')
        ->assertInertia(fn ($page) => $page->has('openListings.data', 2));
});
