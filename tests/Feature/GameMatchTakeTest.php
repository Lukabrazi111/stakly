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
use Illuminate\Support\Str;

/**
 * Helper: a verified creator with a deposit + an open listing whose stake
 * is already escrowed (the same shape `ListingController::store` produces).
 * Returns [creator, listing].
 */
function openListingWithCreator(string $stake = '100', string $deposit = '500', bool $linked = true): array
{
    // `->active()` so the take-gate (`scopeOnPublicMarketplace` +
    // `ownerIsActive` in `TakeListingAction`) doesn't reject every test.
    // Tests exercising the inactive branch override via `update(['is_active_mode' => false])`.
    //
    // `->withLichess()` so the M8 Phase 5 create-gate doesn't reject the
    // creator. Tests exercising the unlinked branch pass `linked: false`.
    $factory = User::factory()->active();
    if ($linked) {
        $factory = $factory->withLichess();
    }
    $creator = $factory->create();
    Wallet::deposit($creator, $deposit, reference: "test:deposit:creator:{$creator->id}");

    // `->forLichess()` so the listing's platform matches the default
    // Lichess-linked taker. Platform-specific gate (Slice B) requires
    // taker to be verified on the LISTING'S platform, not just any.
    $listing = Listing::factory()->open()->forLichess()->for($creator)->state([
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

function takerWithBalance(string $balance = '500', bool $linked = true): User
{
    // Default-linked so the M8 Phase 5 take-gate doesn't block every happy
    // path. Tests exercising the unlinked branch pass `linked: false`.
    $factory = User::factory();
    if ($linked) {
        $factory = $factory->withLichess();
    }
    $taker = $factory->create();
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
    // refuse to take it. `->forLichess()` keeps the listing on the same
    // platform as the default Lichess-linked taker so the platform gate
    // doesn't fire before the race-lost branch we're trying to exercise.
    $listing = Listing::factory()->open()->forLichess()->for($creator)->state([
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
    $taker = User::factory()->withLichess()->create();
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

// ─── Linked-account gate (M8 Phase 5 take-gate) ────────────────────────────

test('unlinked taker is redirected to linked-accounts settings with info toast', function () {
    [, $listing] = openListingWithCreator(stake: '100');
    $taker = takerWithBalance(balance: '500', linked: false);

    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");

    $response->assertRedirect(route('linked-accounts.edit'));
    // Listing is Lichess (helper default); copy names the specific platform.
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'Link a Lichess account before taking this match.',
    ]);

    // Listing untouched, no match created, balance unchanged.
    expect($listing->fresh()->status)->toBe(ListingStatus::Open)
        ->and(GameMatch::count())->toBe(0)
        ->and((string) $taker->fresh()->usdt_balance)->toBe('500.000000')
        ->and(WalletTransaction::query()
            ->where('user_id', $taker->id)
            ->where('type', WalletTransactionType::EscrowHold)
            ->count()
        )->toBe(0);
});

test('chess.com-linked taker CAN take a chess.com listing', function () {
    // Both sides linked + verified on chess.com — listing is chess.com,
    // taker has chess.com, platform-specific gate passes.
    $creator = User::factory()->active()->withChessCom()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");
    $listing = Listing::factory()->open()->forChessCom()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $taker = User::factory()->withChessCom()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();
    $response->assertRedirect(route('matches.show', $match));
    expect($listing->fresh()->status)->toBe(ListingStatus::Taken);
});

test('lichess-linked taker is gate-blocked from a chess.com listing (platform-specific)', function () {
    // The cross-platform case the user originally asked about — a Lichess-
    // linked taker cannot take a chess.com listing even though they have
    // SOME chess link. They'd need to verify chess.com first.
    $creator = User::factory()->active()->withChessCom()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");
    $listing = Listing::factory()->open()->forChessCom()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $taker = User::factory()->withLichess()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $response = $this->actingAs($taker)->postJson("/listings/{$listing->id}/take");

    $response->assertRedirect(route('linked-accounts.edit'));
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'Link a chess.com account before taking this match.',
    ]);

    expect(GameMatch::count())->toBe(0);
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

// "No snapshot when neither side has linked accounts" used to live here,
// but the M8 Phase 5 take-gate now refuses the take entirely in that case
// (the user is redirected to /settings/linked-accounts before the snapshot
// writer ever runs). See the new gate tests above for the redirect behavior.

test('match creation snapshots only the verified provider per side', function () {
    // Asymmetric verification — creator has BOTH providers, taker has only
    // Lichess. Listing is Lichess so the take-gate passes (both have Lichess);
    // the chess.com snapshot on the taker is correctly SKIPPED. Proves
    // snapshot is per-(side, provider), not a blanket copy.
    //
    // Previously this test used creator-Lichess-only vs taker-chess.com-only
    // — that asymmetric pair can no longer share a match under the
    // Phase 5 Slice B platform-specific gate (they have no common platform).
    $creator = User::factory()
        ->active()
        ->withLichess('alice-lichess')
        ->withChessCom('alice-chesscom')
        ->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $listing = Listing::factory()->open()->forLichess()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $taker = User::factory()->withLichess('bob-lichess')->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $this->actingAs($taker)->postJson("/listings/{$listing->id}/take")->assertRedirect();

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();

    expect($match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::Lichess))->toBe('alice-lichess')
        ->and($match->snapshotUsername(GameMatch::SIDE_CREATOR, LinkedAccountProvider::ChessCom))->toBe('alice-chesscom')
        ->and($match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::Lichess))->toBe('bob-lichess')
        // Taker hasn't verified chess.com — snapshot slot stays null.
        ->and($match->snapshotUsername(GameMatch::SIDE_TAKER, LinkedAccountProvider::ChessCom))->toBeNull()
        // 3 slots populated (2 creator + 1 taker), not 4.
        ->and($match->providerSnapshots()->count())->toBe(3);
});

// NOTE: the M8 "unverified-but-set username on the OTHER provider" test
// was deleted in the M18 Phase 3 normalisation refactor. With the new
// `linked_accounts` table that scenario can't exist by construction — a
// row in `linked_accounts` always implies verification (the column
// `verified_at` is NOT NULL). The boundary is now structural, not
// behavioural; no test needed.

test('match creation snapshots provider_user_id + skill_rating for FACEIT-linked players (M15)', function () {
    // M15 Phase 1 — when a player has a FACEIT linked account (with stable
    // provider_user_id + current skill_rating), the snapshot captures both
    // alongside the display username. Chess accounts on the same player
    // continue to snapshot null for these columns. The take itself is a
    // Lichess take because the FACEIT verify + create flow hasn't landed
    // yet (Phase 2/3) — this test just exercises the snapshot writer.
    $creator = User::factory()
        ->active()
        ->withLichess('alice-lichess')
        ->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $listing = Listing::factory()->open()->forLichess()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $faceitId = (string) Str::uuid();
    $taker = User::factory()
        ->withLichess('bob-lichess')
        ->withFaceit('bob-faceit', $faceitId, 1850)
        ->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $this->actingAs($taker)->postJson("/listings/{$listing->id}/take")->assertRedirect();

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();

    // 1 creator account (lichess) + 2 taker accounts (lichess + faceit) = 3 snapshots.
    expect($match->providerSnapshots()->count())->toBe(3);

    // Chess snapshot — provider_user_id + skill_rating_snapshot stay null.
    $takerLichess = $match->providerSnapshots()
        ->where('side', GameMatch::SIDE_TAKER)
        ->where('provider', LinkedAccountProvider::Lichess)
        ->firstOrFail();
    expect($takerLichess->username)->toBe('bob-lichess')
        ->and($takerLichess->provider_user_id)->toBeNull()
        ->and($takerLichess->skill_rating_snapshot)->toBeNull();

    // FACEIT snapshot — provider_user_id + skill_rating_snapshot populated.
    $takerFaceit = $match->providerSnapshots()
        ->where('side', GameMatch::SIDE_TAKER)
        ->where('provider', LinkedAccountProvider::Faceit)
        ->firstOrFail();
    expect($takerFaceit->username)->toBe('bob-faceit')
        ->and($takerFaceit->provider_user_id)->toBe($faceitId)
        ->and($takerFaceit->skill_rating_snapshot)->toBe(1850);
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
