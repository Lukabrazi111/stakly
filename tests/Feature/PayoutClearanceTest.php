<?php

use App\Actions\GameMatch\SettleMatchAction;
use App\Enums\WithdrawalStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\PayoutClearance;
use App\Services\Wallet;
use Carbon\CarbonImmutable;

/**
 * Payout clearing (M9 Phase 0b): winnings are credited immediately but aren't
 * withdrawable until their insurance window elapses. Clearing moves no money —
 * only availability is deferred — so the ledger invariant is untouched.
 */
function settledMatchFor(User $winner, string $stake = '100'): array
{
    platformUser();

    Wallet::deposit($winner, '500', reference: "test:deposit:winner:{$winner->id}");

    $taker = User::factory()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()->taken()->for($winner)->state([
        'stake_amount' => $stake,
    ])->create();

    Wallet::hold(user: $winner, amount: $stake, listing: $listing, reference: "listing-create:{$listing->id}");
    Wallet::hold(user: $taker, amount: $stake, listing: $listing, reference: "match-take:{$listing->id}");

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    app(SettleMatchAction::class)->handle($match, $winner);

    return [$listing, $match];
}

/** An account old enough and proven enough to dodge every risk signal. */
function establishedUser(): User
{
    $user = User::factory()->create(['created_at' => now()->subMonths(6)]);

    Withdrawal::factory()->completed()->for($user)->create();

    return $user;
}

// ============================================================================
// Settlement stamps the window.
// ============================================================================

test('settlement stamps clears_at on the payout row', function () {
    $winner = establishedUser();
    [$listing] = settledMatchFor($winner);

    $payout = $winner->walletTransactions()
        ->where('type', 'payout')
        ->where('related_listing_id', $listing->id)
        ->sole();

    expect($payout->clears_at)->not->toBeNull();
    expect($payout->clears_at->diffInHours(now(), absolute: true))
        ->toBeGreaterThanOrEqual(47);
});

test('non-payout ledger rows never carry a clearance', function () {
    $user = establishedUser();
    $listing = Listing::factory()->create();

    $deposit = Wallet::deposit($user, '100');
    $hold = Wallet::hold($user, '25', $listing);
    $release = Wallet::release($user, '25', $listing);

    expect($deposit->clears_at)->toBeNull();
    expect($hold->clears_at)->toBeNull();
    expect($release->clears_at)->toBeNull();
});

// ============================================================================
// Availability.
// ============================================================================

test('uncleared winnings are excluded from the available balance but not the total', function () {
    $winner = establishedUser();
    settledMatchFor($winner, stake: '100');

    // 500 deposited − 100 staked + 180 payout = 580 total, 180 of it clearing.
    expect(Wallet::balanceFor($winner))->toBe('580.000000');
    expect(Wallet::availableBalance($winner))->toBe('400.000000');
    expect(Wallet::unclearedBalance($winner))->toBe('180.000000');
});

test('winnings become available once the window elapses', function () {
    $winner = establishedUser();
    settledMatchFor($winner, stake: '100');

    expect(Wallet::availableBalance($winner))->toBe('400.000000');

    $this->travelTo(CarbonImmutable::now()->addHours(49));

    expect(Wallet::availableBalance($winner))->toBe('580.000000');
    expect(Wallet::unclearedBalance($winner))->toBe('0');
});

test('nextClearanceAt reports the earliest held tranche and nothing when clear', function () {
    $winner = establishedUser();
    settledMatchFor($winner, stake: '100');

    expect(Wallet::nextClearanceAt($winner))->not->toBeNull();

    $this->travelTo(CarbonImmutable::now()->addHours(49));

    expect(Wallet::nextClearanceAt($winner))->toBeNull();
});

// ============================================================================
// Risk tiers.
// ============================================================================

test('an established account with a modest payout clears on the base window', function () {
    $user = establishedUser();

    $clearsAt = PayoutClearance::for($user, '100');

    expect($clearsAt->diffInHours(CarbonImmutable::now(), absolute: true))
        ->toBeGreaterThanOrEqual(47)
        ->toBeLessThanOrEqual(49);
});

test('a brand-new account is escalated to the long window', function () {
    $user = User::factory()->create(['created_at' => now()->subDay()]);
    Withdrawal::factory()->completed()->for($user)->create();

    $clearsAt = PayoutClearance::for($user, '100');

    expect($clearsAt->diffInHours(CarbonImmutable::now(), absolute: true))
        ->toBeGreaterThanOrEqual(167);
});

test('a large payout is escalated to the long window', function () {
    $user = establishedUser();

    $clearsAt = PayoutClearance::for($user, '500');

    expect($clearsAt->diffInHours(CarbonImmutable::now(), absolute: true))
        ->toBeGreaterThanOrEqual(167);
});

test('a player who has never cashed out is escalated to the long window', function () {
    $user = User::factory()->create(['created_at' => now()->subMonths(6)]);

    $clearsAt = PayoutClearance::for($user, '100');

    expect($clearsAt->diffInHours(CarbonImmutable::now(), absolute: true))
        ->toBeGreaterThanOrEqual(167);
});

test('a withdrawal that did not complete does not count as a track record', function () {
    $user = User::factory()->create(['created_at' => now()->subMonths(6)]);
    Withdrawal::factory()->for($user)->create(['status' => WithdrawalStatus::Rejected]);

    $clearsAt = PayoutClearance::for($user, '100');

    expect($clearsAt->diffInHours(CarbonImmutable::now(), absolute: true))
        ->toBeGreaterThanOrEqual(167);
});

// ============================================================================
// Kill-switch.
// ============================================================================

test('disabling insurance stamps no clearance on new payouts', function () {
    config()->set('stakly.withdrawal_insurance_enabled', false);

    $winner = establishedUser();
    [$listing] = settledMatchFor($winner);

    $payout = $winner->walletTransactions()
        ->where('type', 'payout')
        ->where('related_listing_id', $listing->id)
        ->sole();

    expect($payout->clears_at)->toBeNull();
    expect(Wallet::availableBalance($winner))->toBe(Wallet::balanceFor($winner));
});

test('disabling insurance releases holds already stamped on existing rows', function () {
    $winner = establishedUser();
    settledMatchFor($winner, stake: '100');

    expect(Wallet::availableBalance($winner))->toBe('400.000000');

    // The switch must be retroactive — otherwise flipping it off would strand
    // funds behind a window nothing is enforcing any more.
    config()->set('stakly.withdrawal_insurance_enabled', false);

    expect(Wallet::availableBalance($winner))->toBe('580.000000');
    expect(Wallet::unclearedBalance($winner))->toBe('0');
});

test('a zero-hour window means immediately withdrawable', function () {
    config()->set('stakly.withdrawal_insurance_base_hours', 0);
    config()->set('stakly.withdrawal_insurance_elevated_hours', 0);

    $user = establishedUser();

    expect(PayoutClearance::for($user, '100'))->toBeNull();
});

// ============================================================================
// Uncleared winnings stay playable.
// ============================================================================

test('uncleared winnings can still be staked into a new match', function () {
    $winner = establishedUser();
    settledMatchFor($winner, stake: '100');

    // 400 available, 580 total — a 500 stake dips into uncleared winnings.
    $newListing = Listing::factory()->create();

    $hold = Wallet::hold($winner, '500', $newListing);

    expect($hold->amount)->toBe('-500.000000');
    expect(Wallet::balanceFor($winner))->toBe('80.000000');
});

test('the ledger invariant holds across settlement and clearing', function () {
    $winner = establishedUser();
    settledMatchFor($winner, stake: '100');

    $assertInvariant = function () use ($winner) {
        $balance = (string) $winner->fresh()->usdt_balance;
        $ledgerSum = (string) $winner->walletTransactions()->sum('amount');
        expect(bccomp($balance, $ledgerSum, 6))->toBe(0);
    };

    $assertInvariant();

    $this->travelTo(CarbonImmutable::now()->addHours(49));

    $assertInvariant();
});
