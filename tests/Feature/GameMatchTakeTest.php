<?php

use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Enums\WalletTransactionType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/**
 * Helper: a verified creator with a deposit + an open listing whose stake
 * is already escrowed (the same shape `ListingController::store` produces).
 * Returns [creator, listing].
 */
function openListingWithCreator(string $stake = '100', string $deposit = '500'): array
{
    $creator = User::factory()->create();
    Wallet::deposit($creator, $deposit, reference: "test:deposit:creator:{$creator->id}");

    $listing = Listing::factory()->open()->for($creator)->state([
        'stake_amount' => $stake,
    ])->create();

    Wallet::hold(
        user: $creator,
        amount: $stake,
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    return [$creator, $listing];
}

function takerWithBalance(string $balance = '500'): User
{
    $taker = User::factory()->create();
    Wallet::deposit($taker, $balance, reference: "test:deposit:taker:{$taker->id}");

    return $taker;
}

// ─── Happy path ─────────────────────────────────────────────────────────────

test('verified user with balance can take an open listing', function () {
    [, $listing] = openListingWithCreator(stake: '100');
    $taker = takerWithBalance(balance: '500');

    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();

    $response->assertRedirect(route('matches.show', $match));

    expect($listing->fresh()->status)->toBe(ListingStatus::Taken)
        ->and($match->taker_user_id)->toBe($taker->id)
        ->and($match->status)->toBe(MatchStatus::Pending);

    $hold = WalletTransaction::query()
        ->where('user_id', $taker->id)
        ->where('type', WalletTransactionType::EscrowHold)
        ->where('reference_id', "match-take:{$listing->id}")
        ->firstOrFail();

    expect($hold->amount)->toBe('-100.000000')
        ->and($hold->related_listing_id)->toBe($listing->id);

    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');
});

test('successful take flashes a success toast', function () {
    [, $listing] = openListingWithCreator();
    $taker = takerWithBalance();

    $this->actingAs($taker)
        ->postJson("/listings/{$listing->id}/take")
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Match started.',
        ]);
});

// ─── Authorization + state checks ───────────────────────────────────────────

test('user cannot take their own listing (403)', function () {
    [$creator, $listing] = openListingWithCreator();

    $this->actingAs($creator)
        ->postJson("/listings/{$listing->id}/take")
        ->assertForbidden();

    expect($listing->fresh()->status)->toBe(ListingStatus::Open)
        ->and(GameMatch::count())->toBe(0);
});

test('guest cannot take — redirected to login', function () {
    [, $listing] = openListingWithCreator();

    $this->post("/listings/{$listing->id}/take")
        ->assertRedirect(route('login'));

    expect(GameMatch::count())->toBe(0);
});

test('unverified user cannot take — redirected to verification notice', function () {
    [, $listing] = openListingWithCreator();
    $taker = User::factory()->unverified()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:{$taker->id}");

    $this->actingAs($taker)
        ->post("/listings/{$listing->id}/take")
        ->assertRedirect(route('verification.notice'));

    expect(GameMatch::count())->toBe(0);
});

// ─── Race-lost branch (302 redirect + info toast, NOT 422) ──────────────────

test('taking a listing that is no longer open redirects with info toast', function (string $listingState) {
    [, $listing] = openListingWithCreator();
    // Force the listing into the non-Open state for this iteration.
    $status = match ($listingState) {
        'taken' => ListingStatus::Taken,
        'expired' => ListingStatus::Expired,
        'cancelled' => ListingStatus::Cancelled,
    };
    $listing->update(['status' => $status]);

    $taker = takerWithBalance();

    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");

    $response->assertRedirect(route('listings.show', $listing));
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'This listing is no longer available.',
    ]);

    expect(GameMatch::count())->toBe(0)
        ->and((string) $taker->fresh()->usdt_balance)->toBe('500.000000');
})->with(['taken', 'expired', 'cancelled']);

test('taking an open-but-past-expiry listing also hits the race-lost branch', function () {
    $creator = User::factory()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    // Open status but expires_at is in the past — listings:expire would flip
    // this to Expired on the next tick, but until it runs we should still
    // refuse to take it.
    $listing = Listing::factory()->open()->for($creator)->state([
        'stake_amount' => '100',
        'expires_at' => now()->subMinutes(5),
    ])->create();
    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $taker = takerWithBalance();

    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");

    $response->assertRedirect(route('listings.show', $listing));
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'This listing is no longer available.',
    ]);

    expect(GameMatch::count())->toBe(0);
});

// ─── Insufficient balance (422 keyed on `amount`) ───────────────────────────

test('taker without enough balance gets 422 keyed on amount', function () {
    [, $listing] = openListingWithCreator(stake: '100');
    $taker = User::factory()->create();
    Wallet::deposit($taker, '50', reference: "test:deposit:{$taker->id}");

    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['amount']);

    expect(GameMatch::count())->toBe(0)
        ->and((string) $listing->fresh()->status->value)->toBe('open')
        ->and((string) $taker->fresh()->usdt_balance)->toBe('50.000000');
});

// ─── Idempotency / double-submit safety ─────────────────────────────────────

test('a retried POST after a successful take hits the race-lost branch (no double charge)', function () {
    [, $listing] = openListingWithCreator(stake: '100');
    $taker = takerWithBalance(balance: '500');

    // First take succeeds.
    $this->actingAs($taker)->postJson("/listings/{$listing->id}/take")->assertRedirect();
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    // Second POST (e.g. a double-click) hits the race-lost branch — listing is
    // now Taken, so the controller returns the friendly redirect instead of
    // double-debiting or 500-ing.
    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");
    $response->assertRedirect(route('listings.show', $listing));
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'This listing is no longer available.',
    ]);

    // Balance and match count unchanged from after the first take.
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000')
        ->and(GameMatch::count())->toBe(1);
});

// ─── BCMath round-trip on the taker hold ────────────────────────────────────

test('taker hold uses exact BCMath precision matching the listing stake', function () {
    [, $listing] = openListingWithCreator(stake: '123.45', deposit: '500');
    $taker = takerWithBalance(balance: '500');

    $this->actingAs($taker)
        ->postJson("/listings/{$listing->id}/take")
        ->assertRedirect();

    $hold = WalletTransaction::query()
        ->where('user_id', $taker->id)
        ->where('reference_id', "match-take:{$listing->id}")
        ->firstOrFail();

    // Exact BCMath equality at scale 6 — no float drift on awkward stake values.
    expect(bccomp($hold->amount, '-123.450000', 6))->toBe(0)
        ->and(bccomp((string) $taker->fresh()->usdt_balance, '376.550000', 6))->toBe(0);
});
