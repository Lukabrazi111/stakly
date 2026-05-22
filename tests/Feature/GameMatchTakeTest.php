<?php

use App\Enums\LinkedAccountProvider;
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
    // `->active()` so the take-gate (`scopeOnPublicMarketplace` +
    // `ownerIsActive` in `TakeListingAction`) doesn't reject every test.
    // Tests exercising the inactive branch override via `update(['is_active_mode' => false])`.
    $creator = User::factory()->active()->create();
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

// ─── Active Mode gate (M6 Phase 6.5) ──────────────────────────────────────

test('taking an inactive owner listing is blocked with an info toast — no match, no hold', function () {
    // The visibility filter (`scopeOnPublicMarketplace`) keeps inactive
    // owners' listings off the marketplace + public profile. But a taker
    // who already has the listing detail page loaded — or knows the direct
    // URL — could still POST /take. Active Mode is "I'm not available";
    // it must gate the actual match-start, not just visibility, otherwise
    // stale tabs bypass the intent. Server bails inside the locked tx with
    // `$ownerActive` check and returns the same friendly redirect pattern
    // as the existing race-lost branch.
    [$creator, $listing] = openListingWithCreator(stake: '100');
    $creator->update(['is_active_mode' => false]);

    $taker = takerWithBalance(balance: '500');

    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");

    $response->assertRedirect(route('listings.show', $listing));
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'This player is currently inactive. Their listings are temporarily unavailable.',
    ]);

    // No match created, listing stays Open, taker is not charged, creator's
    // escrow is untouched.
    expect(GameMatch::count())->toBe(0)
        ->and($listing->fresh()->status)->toBe(ListingStatus::Open)
        ->and((string) $taker->fresh()->usdt_balance)->toBe('500.000000')
        ->and(WalletTransaction::query()
            ->where('user_id', $taker->id)
            ->where('type', WalletTransactionType::EscrowHold)
            ->count()
        )->toBe(0);
});

test('owner reactivating between page load + take request lets the take succeed', function () {
    // Closed-loop check: the gate is live state, not cached. If the owner
    // flips back to active before the taker submits, the take should go
    // through normally.
    [$creator, $listing] = openListingWithCreator(stake: '100');
    $creator->update(['is_active_mode' => false]);
    $creator->update(['is_active_mode' => true]);

    $taker = takerWithBalance(balance: '500');

    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();
    $response->assertRedirect(route('matches.show', $match));
    expect($listing->fresh()->status)->toBe(ListingStatus::Taken);
});

// ─── Race-lost ──────────────────────────────────────────────────────────────

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

// ─── Linked-account snapshot (M8 Phase 4 — "snapshot, don't link") ──────────

test('match creation snapshots each side\'s verified external usernames', function () {
    // Both players verified on both providers — the most-populated case.
    // Snapshot rows must mirror the live `users.{provider}_username`
    // values at match creation. After a mid-match unlink, the snapshot
    // remains the cross-check anchor for paste-path + auto-fetch.
    $creator = User::factory()
        ->active()
        ->withLichess('alice-lichess')
        ->withChessCom('alice-chesscom')
        ->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $listing = Listing::factory()->open()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $taker = User::factory()
        ->withLichess('bob-lichess')
        ->withChessCom('bob-chesscom')
        ->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $this->actingAs($taker)->postJson("/listings/{$listing->id}/take")->assertRedirect();

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();

    expect($match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::Lichess))->toBe('alice-lichess')
        ->and($match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::ChessCom))->toBe('alice-chesscom')
        ->and($match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::Lichess))->toBe('bob-lichess')
        ->and($match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::ChessCom))->toBe('bob-chesscom')
        // Insertion count: 2 sides × 2 providers, all populated.
        ->and($match->providerSnapshots()->count())->toBe(4);
});

test('match creation inserts NO snapshot rows when neither side has verified accounts', function () {
    // No linked accounts on either side — the auto-fetch path will skip
    // silently and any pasted URL will fail the cross-check (rendering a
    // plain link card via the OG fetcher fallback).
    [, $listing] = openListingWithCreator();
    $taker = takerWithBalance();

    $this->actingAs($taker)->postJson("/listings/{$listing->id}/take")->assertRedirect();

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();

    expect($match->providerSnapshots()->count())->toBe(0)
        ->and($match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::Lichess))->toBeNull()
        ->and($match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::Lichess))->toBeNull();
});

test('match creation snapshots only the verified provider per side', function () {
    // Asymmetric verification — creator only Lichess, taker only chess.com.
    // This proves the snapshot is per-(side, provider), not a blanket copy.
    // Smart-link enrichment for a Lichess URL would only have the creator's
    // side to cross-check; a chess.com URL would only have the taker's. Card
    // verification logic in Phase 4 / 4b reads both ends; missing-one-side
    // is a soft fail that still renders an unverified card.
    $creator = User::factory()
        ->active()
        ->withLichess('alice-lichess')
        ->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $listing = Listing::factory()->open()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $taker = User::factory()->withChessCom('bob-chesscom')->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $this->actingAs($taker)->postJson("/listings/{$listing->id}/take")->assertRedirect();

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();

    expect($match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::Lichess))->toBe('alice-lichess')
        ->and($match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::ChessCom))->toBeNull()
        ->and($match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::Lichess))->toBeNull()
        ->and($match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::ChessCom))->toBe('bob-chesscom')
        // Only two slots populated, not four.
        ->and($match->providerSnapshots()->count())->toBe(2);
});

test('match creation does NOT snapshot an unverified-but-set username', function () {
    // Verification state matters: a `lichess_username` row value with a
    // null `lichess_verified_at` is treated as "not linked" for evidence
    // purposes. Otherwise a user could set arbitrary usernames in their
    // profile and have them snapshotted onto match rows as anchors for
    // dispute cards. The DB-level unique on `users.lichess_username`
    // technically allows setting the column via tinker even without
    // going through the verification flow — defense in depth.
    $creator = User::factory()->active()->create();
    // Direct attribute set bypassing the verification flow:
    $creator->lichess_username = 'unverified-handle';
    $creator->save();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $listing = Listing::factory()->open()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $taker = takerWithBalance();

    $this->actingAs($taker)->postJson("/listings/{$listing->id}/take")->assertRedirect();

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();

    expect($match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::Lichess))->toBeNull()
        ->and($match->providerSnapshots()->count())->toBe(0);
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
