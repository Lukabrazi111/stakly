<?php

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\MatchSettlement;
use App\Services\Wallet;

function pendingMatchForSettlement(string $stake = '100'): array
{
    // Seed the platform user — Wallet::fee on settlement looks it up.
    platformUser();

    $creator = User::factory()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $taker = User::factory()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()->taken()->for($creator)->state([
        'stake_amount' => $stake,
    ])->create();

    Wallet::hold(user: $creator, amount: $stake, listing: $listing, reference: "listing-create:{$listing->id}");
    Wallet::hold(user: $taker, amount: $stake, listing: $listing, reference: "match-take:{$listing->id}");

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    return [$creator, $taker, $listing, $match];
}

// ─── Conservation of money ──────────────────────────────────────────────────

test('settlement conserves money — sum of all match-related ledger entries is zero', function () {
    [$creator, , $listing, $match] = pendingMatchForSettlement(stake: '100');

    MatchSettlement::settle($match, $creator);

    // Sum all wallet transactions tied to this listing.
    $totalLedgerForMatch = WalletTransaction::query()
        ->where('related_listing_id', $listing->id)
        ->sum('amount');

    // Convert to BCMath-safe comparison.
    expect(bccomp((string) $totalLedgerForMatch, '0', 6))->toBe(0);
});

// ─── Idempotency ────────────────────────────────────────────────────────────

test('settle is idempotent — repeat call is a no-op', function () {
    [$creator, , , $match] = pendingMatchForSettlement(stake: '100');

    MatchSettlement::settle($match, $creator);
    $balanceAfterFirst = $creator->fresh()->usdt_balance;

    MatchSettlement::settle($match->fresh(), $creator);
    $balanceAfterSecond = $creator->fresh()->usdt_balance;

    expect((string) $balanceAfterSecond)->toBe((string) $balanceAfterFirst);

    // Only one payout row exists (second call short-circuited).
    expect(WalletTransaction::query()
        ->where('reference_id', "match-payout:{$match->id}")
        ->count())->toBe(1);

    expect(WalletTransaction::query()
        ->where('reference_id', "match-fee:{$match->id}")
        ->count())->toBe(1);
});

// ─── Validates winner is a participant ──────────────────────────────────────

test('settle throws if winner is not a participant', function () {
    [, , , $match] = pendingMatchForSettlement();
    $stranger = User::factory()->create();

    expect(fn () => MatchSettlement::settle($match, $stranger))
        ->toThrow(InvalidArgumentException::class);
});

// ─── BCMath precision ──────────────────────────────────────────────────────

test('settlement preserves exact BCMath precision on awkward stake values', function () {
    [$creator, , , $match] = pendingMatchForSettlement(stake: '123.45');

    MatchSettlement::settle($match, $creator);

    // Pot = 246.90, fee = 24.69, payout = 222.21.
    // Creator: $500 - $123.45 (held) + $222.21 (payout) = $598.76.
    expect(bccomp((string) $creator->fresh()->usdt_balance, '598.760000', 6))->toBe(0);

    $payout = WalletTransaction::query()
        ->where('reference_id', "match-payout:{$match->id}")
        ->firstOrFail();
    expect(bccomp($payout->amount, '222.210000', 6))->toBe(0);

    $fee = WalletTransaction::query()
        ->where('reference_id', "match-fee:{$match->id}")
        ->firstOrFail();
    expect(bccomp($fee->amount, '24.690000', 6))->toBe(0);
});

// ─── Fee rate config plumbing ──────────────────────────────────────────────

test('fee rate is read from config (changing config changes the fee)', function () {
    config(['stakly.platform_fee_rate' => '0.20']); // 20%
    [$creator, , , $match] = pendingMatchForSettlement(stake: '100');

    MatchSettlement::settle($match, $creator);

    // Pot = 200, fee = 40, payout = 160.
    $fee = WalletTransaction::query()
        ->where('reference_id', "match-fee:{$match->id}")
        ->firstOrFail();
    expect(bccomp($fee->amount, '40.000000', 6))->toBe(0);

    $payout = WalletTransaction::query()
        ->where('reference_id', "match-payout:{$match->id}")
        ->firstOrFail();
    expect(bccomp($payout->amount, '160.000000', 6))->toBe(0);
});

// ─── Match status transition ───────────────────────────────────────────────

test('settle flips match status to Settled and sets winner_user_id + settled_at', function () {
    [$creator, , , $match] = pendingMatchForSettlement();

    MatchSettlement::settle($match, $creator);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->settled_at)->not->toBeNull();
});

// ─── resolveDispute: confirmed branch ──────────────────────────────────────

test('resolveDispute calls API and settles when confidence is Confirmed', function () {
    [$creator, , , $match] = pendingMatchForSettlement();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);
    mockGameApi()->forceWinner($creator->id);

    MatchSettlement::resolveDispute($match);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->settled_at)->not->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull()
        ->and($fresh->api_response)->toBeArray()
        ->and($fresh->api_response['driver'])->toBe('mock')
        ->and($fresh->api_response['mode'])->toBe('forced');
});

// ─── resolveDispute: unknown branch ────────────────────────────────────────

test('resolveDispute moves to ManualReview when confidence is Unknown', function () {
    [$creator, $taker, , $match] = pendingMatchForSettlement();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);
    mockGameApi()->forceUnknown();

    MatchSettlement::resolveDispute($match);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::ManualReview)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull()
        ->and($fresh->api_response)->toBeArray();

    // Money stays escrowed.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeFalse()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});

// ─── resolveDispute: idempotency on terminal states ────────────────────────

test('resolveDispute is a no-op on already-Settled match', function () {
    [$creator, , , $match] = pendingMatchForSettlement();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);
    mockGameApi()->forceWinner($creator->id);

    MatchSettlement::resolveDispute($match);
    $balanceAfterFirst = $creator->fresh()->usdt_balance;

    // Repeat — should short-circuit on the Settled status guard.
    MatchSettlement::resolveDispute($match->fresh());
    $balanceAfterSecond = $creator->fresh()->usdt_balance;

    expect((string) $balanceAfterSecond)->toBe((string) $balanceAfterFirst);

    // Still only one payout / fee row.
    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->count())->toBe(1)
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->count())->toBe(1);
});

test('resolveDispute is a no-op on already-ManualReview match', function () {
    [, , , $match] = pendingMatchForSettlement();
    $match->update(['status' => MatchStatus::ManualReview, 'dispute_opened_at' => now()]);
    mockGameApi()->forceWinner($match->listing->user_id);

    MatchSettlement::resolveDispute($match);

    // ManualReview is terminal — no transition to Settled even though API
    // would have returned a Confirmed winner. Admin tooling owns this state.
    expect($match->fresh()->status)->toBe(MatchStatus::ManualReview)
        ->and($match->fresh()->winner_user_id)->toBeNull();
});

// ─── resolveDispute: sanity guards ─────────────────────────────────────────

test('resolveDispute throws on a Pending match (caller must transition to Disputed first)', function () {
    [, , , $match] = pendingMatchForSettlement();
    // Status is Pending by default — never transitioned to Disputed.

    expect(fn () => MatchSettlement::resolveDispute($match))
        ->toThrow(InvalidArgumentException::class);
});

// ─── resolveDispute: BCMath precision through API path ─────────────────────

test('resolveDispute preserves BCMath precision on awkward stake values', function () {
    [$creator, , , $match] = pendingMatchForSettlement(stake: '123.45');
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);
    mockGameApi()->forceWinner($creator->id);

    MatchSettlement::resolveDispute($match);

    // Pot = 246.90, fee = 24.69, payout = 222.21.
    // Creator: $500 - $123.45 (held) + $222.21 (payout) = $598.76.
    expect(bccomp((string) $creator->fresh()->usdt_balance, '598.760000', 6))->toBe(0);

    $payout = WalletTransaction::query()
        ->where('reference_id', "match-payout:{$match->id}")
        ->firstOrFail();
    expect(bccomp($payout->amount, '222.210000', 6))->toBe(0);
});

// ─── settleDraw: happy path ─────────────────────────────────────────────────

test('settleDraw refunds both stakes, posts no fee, flips status with no winner', function () {
    [$creator, $taker, , $match] = pendingMatchForSettlement(stake: '100');

    MatchSettlement::settleDraw($match);

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->not->toBeNull();

    // Both balances restored to pre-hold values:
    // $500 (deposit) - $100 (held) + $100 (release) = $500.
    expect((string) $creator->fresh()->usdt_balance)->toBe('500.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('500.000000');

    // One refund row per player.
    expect(WalletTransaction::query()->where('reference_id', "match-draw-creator:{$match->id}")->exists())->toBeTrue()
        ->and(WalletTransaction::query()->where('reference_id', "match-draw-taker:{$match->id}")->exists())->toBeTrue();

    // No platform fee row exists for this match.
    expect(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});

// ─── settleDraw: conservation ───────────────────────────────────────────────

test('settleDraw conserves money — sum of all match-related ledger entries is zero', function () {
    [, , $listing, $match] = pendingMatchForSettlement(stake: '100');

    MatchSettlement::settleDraw($match);

    $totalLedgerForMatch = WalletTransaction::query()
        ->where('related_listing_id', $listing->id)
        ->sum('amount');

    expect(bccomp((string) $totalLedgerForMatch, '0', 6))->toBe(0);
});

// ─── settleDraw: idempotency ────────────────────────────────────────────────

test('settleDraw is idempotent — repeat call is a no-op', function () {
    [$creator, $taker, , $match] = pendingMatchForSettlement(stake: '100');

    MatchSettlement::settleDraw($match);
    $creatorBalanceAfterFirst = $creator->fresh()->usdt_balance;
    $takerBalanceAfterFirst = $taker->fresh()->usdt_balance;

    MatchSettlement::settleDraw($match->fresh());

    expect((string) $creator->fresh()->usdt_balance)->toBe((string) $creatorBalanceAfterFirst);
    expect((string) $taker->fresh()->usdt_balance)->toBe((string) $takerBalanceAfterFirst);

    expect(WalletTransaction::query()->where('reference_id', "match-draw-creator:{$match->id}")->count())->toBe(1)
        ->and(WalletTransaction::query()->where('reference_id', "match-draw-taker:{$match->id}")->count())->toBe(1);
});

// ─── settleDraw: BCMath precision on awkward stakes ─────────────────────────

test('settleDraw preserves exact BCMath precision on awkward stake values', function () {
    [$creator, $taker, , $match] = pendingMatchForSettlement(stake: '123.45');

    MatchSettlement::settleDraw($match);

    // Each refunded $123.45 → back to $500.
    expect(bccomp((string) $creator->fresh()->usdt_balance, '500.000000', 6))->toBe(0);
    expect(bccomp((string) $taker->fresh()->usdt_balance, '500.000000', 6))->toBe(0);
});

// ─── resolveDispute: drawn branch ───────────────────────────────────────────

test('resolveDispute refunds both stakes when API confidence is Drawn', function () {
    [$creator, $taker, , $match] = pendingMatchForSettlement(stake: '100');
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);
    mockGameApi()->forceDraw();

    MatchSettlement::resolveDispute($match);

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->not->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull()
        ->and($fresh->api_response)->toBeArray();

    // Both balances restored — settlement was a refund, not a payout.
    expect((string) $creator->fresh()->usdt_balance)->toBe('500.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('500.000000');

    // No fee posted.
    expect(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});
