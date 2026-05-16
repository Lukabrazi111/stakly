<?php

use App\Enums\ListingStatus;
use App\Enums\WalletTransactionType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/*
|--------------------------------------------------------------------------
| Listing pause / resume (M6 Phase 6.7–6.9)
|--------------------------------------------------------------------------
|
| Soft-pause semantics: status flip only, no wallet operations, escrow stays
| held. Cancel reaches Paused (refund-without-resume). `listings:expire`
| treats Paused like Open for expiry. Public marketplace + non-owner profile
| views hide Paused listings.
|
*/

// ─── pause: auth + verification gating ───────────────────────────────────

test('guests cannot pause a listing', function () {
    $listing = Listing::factory()->open()->create();

    $this->post("/listings/{$listing->id}/pause")
        ->assertRedirect(route('login'));
});

test('unverified users cannot pause a listing', function () {
    $user = User::factory()->unverified()->create();
    $listing = Listing::factory()->open()->for($user)->create();

    $this->actingAs($user)
        ->post("/listings/{$listing->id}/pause")
        ->assertRedirect(route('verification.notice'));
});

// ─── pause: policy ───────────────────────────────────────────────────────

test('a non-owner cannot pause a listing', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $listing = Listing::factory()->open()->for($owner)->create();

    $this->actingAs($stranger)
        ->post("/listings/{$listing->id}/pause")
        ->assertForbidden();

    expect($listing->fresh()->status)->toBe(ListingStatus::Open);
});

test('the owner can pause an Open listing', function () {
    $owner = User::factory()->create();
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");
    $listing = Listing::factory()
        ->open()
        ->for($owner)
        ->state(['stake_amount' => '100'])
        ->create();
    Wallet::hold(
        user: $owner,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );
    $balanceBefore = (string) $owner->fresh()->usdt_balance;

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/pause")
        ->assertRedirect();

    expect($listing->fresh()->status)->toBe(ListingStatus::Paused);

    // Balance unchanged — soft pause posts no wallet rows.
    expect((string) $owner->fresh()->usdt_balance)->toBe($balanceBefore);
});

test('pause does not write any new wallet_transactions rows', function () {
    $owner = User::factory()->create();
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");
    $listing = Listing::factory()->open()->for($owner)->state(['stake_amount' => '100'])->create();
    Wallet::hold(user: $owner, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");

    $ledgerCountBefore = WalletTransaction::query()->count();

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/pause")
        ->assertRedirect();

    expect(WalletTransaction::query()->count())->toBe($ledgerCountBefore);
});

test('cannot pause an already-Paused listing', function () {
    $owner = User::factory()->create();
    $listing = Listing::factory()->for($owner)->state(['status' => ListingStatus::Paused])->create();

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/pause")
        ->assertForbidden();
});

test('cannot pause a Taken listing', function () {
    $owner = User::factory()->create();
    $listing = Listing::factory()->taken()->for($owner)->create();

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/pause")
        ->assertForbidden();
});

test('cannot pause a Cancelled listing', function () {
    $owner = User::factory()->create();
    $listing = Listing::factory()->cancelled()->for($owner)->create();

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/pause")
        ->assertForbidden();
});

test('cannot pause an Expired listing', function () {
    $owner = User::factory()->create();
    $listing = Listing::factory()->expired()->for($owner)->create();

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/pause")
        ->assertForbidden();
});

// ─── resume: policy ──────────────────────────────────────────────────────

test('a non-owner cannot resume a listing', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $listing = Listing::factory()->for($owner)->state(['status' => ListingStatus::Paused])->create();

    $this->actingAs($stranger)
        ->post("/listings/{$listing->id}/resume")
        ->assertForbidden();

    expect($listing->fresh()->status)->toBe(ListingStatus::Paused);
});

test('the owner can resume a Paused listing', function () {
    $owner = User::factory()->create();
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");
    $listing = Listing::factory()
        ->for($owner)
        ->state(['stake_amount' => '100', 'status' => ListingStatus::Paused, 'expires_at' => now()->addHours(2)])
        ->create();
    Wallet::hold(user: $owner, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
    $balanceBefore = (string) $owner->fresh()->usdt_balance;

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/resume")
        ->assertRedirect();

    expect($listing->fresh()->status)->toBe(ListingStatus::Open);
    // No wallet activity on resume either.
    expect((string) $owner->fresh()->usdt_balance)->toBe($balanceBefore);
});

test('cannot resume an Open listing', function () {
    $owner = User::factory()->create();
    $listing = Listing::factory()->open()->for($owner)->create();

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/resume")
        ->assertForbidden();
});

test('cannot resume a Taken listing', function () {
    $owner = User::factory()->create();
    $listing = Listing::factory()->taken()->for($owner)->create();

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/resume")
        ->assertForbidden();
});

test('cannot resume a Cancelled listing', function () {
    $owner = User::factory()->create();
    $listing = Listing::factory()->cancelled()->for($owner)->create();

    $this->actingAs($owner)
        ->post("/listings/{$listing->id}/resume")
        ->assertForbidden();
});

// ─── public marketplace hides Paused listings ────────────────────────────

test('Paused listings are hidden from the /listings index', function () {
    $owner = User::factory()->create();
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");

    $open = Listing::factory()->open()->for($owner)->state(['stake_amount' => '50'])->create();
    Wallet::hold(user: $owner, amount: '50', listing: $open, reference: "listing-create:{$open->id}");

    $paused = Listing::factory()
        ->for($owner)
        ->state(['stake_amount' => '50', 'status' => ListingStatus::Paused, 'expires_at' => now()->addHours(4)])
        ->create();
    Wallet::hold(user: $owner, amount: '50', listing: $paused, reference: "listing-create:{$paused->id}");

    $response = $this->get('/listings');

    $response->assertInertia(fn ($page) => $page
        ->has('listings.data', 1)
        ->where('listings.data.0.id', $open->id)
    );
});

// ─── Paused listings can't be taken ─────────────────────────────────────

test('a paused listing cannot be taken via direct POST', function () {
    $owner = User::factory()->create();
    $taker = User::factory()->create();
    Wallet::deposit($taker, '1000', reference: "test:deposit:{$taker->id}");

    $paused = Listing::factory()
        ->for($owner)
        ->state(['stake_amount' => '100', 'status' => ListingStatus::Paused, 'expires_at' => now()->addHours(2)])
        ->create();

    $this->actingAs($taker)
        ->post("/listings/{$paused->id}/take")
        // Race-lost path in GameMatchController::take redirects to the
        // listing detail with an info toast — listing is "no longer available".
        ->assertRedirect(route('listings.show', $paused));

    // No match created, listing status unchanged.
    expect(GameMatch::query()->where('listing_id', $paused->id)->count())->toBe(0);
    expect($paused->fresh()->status)->toBe(ListingStatus::Paused);
});

// ─── cancel-from-Paused refunds the escrow ──────────────────────────────

test('cancel works on a Paused listing and refunds the escrow', function () {
    $owner = User::factory()->create();
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");
    $listing = Listing::factory()
        ->for($owner)
        ->state(['stake_amount' => '100', 'status' => ListingStatus::Paused, 'expires_at' => now()->addHours(2)])
        ->create();
    Wallet::hold(user: $owner, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");

    expect((string) $owner->fresh()->usdt_balance)->toBe('900.000000');

    $this->actingAs($owner)
        ->delete("/listings/{$listing->id}/cancel")
        ->assertRedirect();

    expect($listing->fresh()->status)->toBe(ListingStatus::Cancelled);
    expect((string) $owner->fresh()->usdt_balance)->toBe('1000.000000');

    // Exactly one EscrowRelease row was written.
    expect(
        WalletTransaction::query()
            ->where('user_id', $owner->id)
            ->where('type', WalletTransactionType::EscrowRelease)
            ->count()
    )->toBe(1);
});

// ─── ExpireListings handles Paused like Open ────────────────────────────

test('listings:expire refunds and flips Paused listings past expiry', function () {
    $owner = User::factory()->create();
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");

    $paused = Listing::factory()
        ->for($owner)
        ->state(['stake_amount' => '100', 'status' => ListingStatus::Paused, 'expires_at' => now()->subMinute()])
        ->create();
    Wallet::hold(user: $owner, amount: '100', listing: $paused, reference: "listing-create:{$paused->id}");

    expect((string) $owner->fresh()->usdt_balance)->toBe('900.000000');

    $this->artisan('listings:expire')->assertSuccessful();

    expect($paused->fresh()->status)->toBe(ListingStatus::Expired);
    expect((string) $owner->fresh()->usdt_balance)->toBe('1000.000000');
});

test('listings:expire leaves non-expired Paused listings untouched', function () {
    $owner = User::factory()->create();
    $paused = Listing::factory()
        ->for($owner)
        ->state(['status' => ListingStatus::Paused, 'expires_at' => now()->addHours(2)])
        ->create();

    $this->artisan('listings:expire')->assertSuccessful();

    expect($paused->fresh()->status)->toBe(ListingStatus::Paused);
});

// ─── Profile listings visibility ─────────────────────────────────────────

test('own profile shows Paused listings to the owner', function () {
    $owner = User::factory()->create(['username' => 'alice']);
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");

    $open = Listing::factory()->open()->for($owner)->state(['stake_amount' => '50'])->create();
    Wallet::hold(user: $owner, amount: '50', listing: $open, reference: "listing-create:{$open->id}");

    $paused = Listing::factory()
        ->for($owner)
        ->state(['stake_amount' => '50', 'status' => ListingStatus::Paused, 'expires_at' => now()->addHours(4)])
        ->create();
    Wallet::hold(user: $owner, amount: '50', listing: $paused, reference: "listing-create:{$paused->id}");

    $this->actingAs($owner)
        ->get('/users/alice')
        ->assertInertia(fn ($page) => $page->has('openListings.data', 2));
});

test('other peoples profiles hide Paused listings', function () {
    $owner = User::factory()->create(['username' => 'alice']);
    $visitor = User::factory()->create();
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");

    $open = Listing::factory()->open()->for($owner)->state(['stake_amount' => '50'])->create();
    Wallet::hold(user: $owner, amount: '50', listing: $open, reference: "listing-create:{$open->id}");

    $paused = Listing::factory()
        ->for($owner)
        ->state(['stake_amount' => '50', 'status' => ListingStatus::Paused, 'expires_at' => now()->addHours(4)])
        ->create();
    Wallet::hold(user: $owner, amount: '50', listing: $paused, reference: "listing-create:{$paused->id}");

    $this->actingAs($visitor)
        ->get('/users/alice')
        ->assertInertia(fn ($page) => $page
            ->has('openListings.data', 1)
            ->where('openListings.data.0.id', $open->id)
        );
});

test('guest profile views hide Paused listings', function () {
    $owner = User::factory()->create(['username' => 'alice']);
    Wallet::deposit($owner, '1000', reference: "test:deposit:{$owner->id}");

    $open = Listing::factory()->open()->for($owner)->state(['stake_amount' => '50'])->create();
    Wallet::hold(user: $owner, amount: '50', listing: $open, reference: "listing-create:{$open->id}");

    $paused = Listing::factory()
        ->for($owner)
        ->state(['stake_amount' => '50', 'status' => ListingStatus::Paused, 'expires_at' => now()->addHours(4)])
        ->create();
    Wallet::hold(user: $owner, amount: '50', listing: $paused, reference: "listing-create:{$paused->id}");

    $this->get('/users/alice')
        ->assertInertia(fn ($page) => $page->has('openListings.data', 1));
});
