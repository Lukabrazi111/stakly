<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Build a Pending match whose `created_at` has been backdated by `$hoursOld`
 * hours so the timeout command picks it up. Returns
 * [$creator, $taker, $listing, $match].
 *
 * The DB-direct update on `created_at` is intentional — bypasses Eloquent
 * timestamps and `$fillable` (which doesn't include `created_at`) so we
 * surgically age the match without affecting the listing / ledger rows.
 */
function timedOutMatch(string $stake = '100', int $hoursOld = 5): array
{
    platformUser();

    $creator = User::factory()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $taker = User::factory()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()->taken()->for($creator)->state([
        'stake_amount' => $stake,
    ])->create();

    Wallet::hold(
        user: $creator,
        amount: $stake,
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );
    Wallet::hold(
        user: $taker,
        amount: $stake,
        listing: $listing,
        reference: "match-take:{$listing->id}",
    );

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    DB::table('game_matches')
        ->where('id', $match->id)
        ->update(['created_at' => now()->subHours($hoursOld)]);

    $match->refresh();

    return [$creator, $taker, $listing, $match];
}

// ─── Single Won confirmer (honor claim) ─────────────────────────────────────

test('single Won confirmer (creator) past deadline → creator wins, fee posted', function () {
    [$creator, $taker, , $match] = timedOutMatch(stake: '100');

    $match->update(['creator_confirmed_outcome' => MatchOutcome::Won]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->settled_at)->not->toBeNull();

    // Pot=$200, fee=$20, payout=$180. Creator: 500 - 100 + 180 = 580.
    expect((string) $creator->fresh()->usdt_balance)->toBe('580.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeTrue()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeTrue();
});

test('single Won confirmer (taker) past deadline → taker wins', function () {
    [$creator, $taker, , $match] = timedOutMatch(stake: '100');

    $match->update(['taker_confirmed_outcome' => MatchOutcome::Won]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($taker->id);

    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('580.000000');
});

// ─── Single Lost confirmer (honor claim → OPPONENT wins) ────────────────────

test('single Lost confirmer (creator) past deadline → taker wins (claim honored)', function () {
    [$creator, $taker, , $match] = timedOutMatch(stake: '100');

    $match->update(['creator_confirmed_outcome' => MatchOutcome::Lost]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    $fresh = $match->fresh();

    // Creator said "I lost" — we honor it. Taker wins.
    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($taker->id);

    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('580.000000');
});

test('single Lost confirmer (taker) past deadline → creator wins (claim honored)', function () {
    [$creator, $taker, , $match] = timedOutMatch(stake: '100');

    $match->update(['taker_confirmed_outcome' => MatchOutcome::Lost]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id);

    expect((string) $creator->fresh()->usdt_balance)->toBe('580.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');
});

// ─── Single Drawn confirmer → game-API arbitrates ───────────────────────────

test('single Drawn confirmer past deadline → API arbitrates (mock returns winner)', function () {
    [$creator, $taker, , $match] = timedOutMatch();
    $match->update(['creator_confirmed_outcome' => MatchOutcome::Drawn]);
    mockGameApi()->forceWinner($creator->id);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    $fresh = $match->fresh();

    // Routed to dispute, then API resolved to creator.
    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull()
        ->and($fresh->api_response)->toBeArray();
});

test('single Drawn confirmer past deadline + API returns Drawn → both refunded', function () {
    [$creator, $taker, , $match] = timedOutMatch(stake: '100');
    $match->update(['taker_confirmed_outcome' => MatchOutcome::Drawn]);
    mockGameApi()->forceDraw();

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->dispute_opened_at)->not->toBeNull();

    // Both refunded.
    expect((string) $creator->fresh()->usdt_balance)->toBe('500.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('500.000000');

    expect(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});

// ─── Neither confirmed → game-API arbitrates ────────────────────────────────

test('neither confirmed past deadline → API arbitrates', function () {
    [$creator, , , $match] = timedOutMatch();
    mockGameApi()->forceWinner($creator->id);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull();
});

test('neither confirmed past deadline + API returns Unknown → ManualReview, money stays locked', function () {
    [$creator, $taker, , $match] = timedOutMatch();
    mockGameApi()->forceUnknown();

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::ManualReview)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->toBeNull();

    // Stakes still escrowed.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');
});

// ─── Eligibility filtering ──────────────────────────────────────────────────

test('Pending match younger than the deadline is left alone', function () {
    [$creator, $taker, , $match] = timedOutMatch(stake: '100', hoursOld: 1);
    $match->update(['creator_confirmed_outcome' => MatchOutcome::Won]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    expect($match->fresh()->status)->toBe(MatchStatus::Pending)
        ->and($match->fresh()->winner_user_id)->toBeNull();

    // Held balances unchanged.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');
});

test('already-Settled match past deadline is not re-touched', function () {
    [$creator, $taker, , $match] = timedOutMatch();
    $match->update([
        'status' => MatchStatus::Settled,
        'winner_user_id' => $creator->id,
        'settled_at' => now(),
    ]);

    $balanceBefore = $creator->fresh()->usdt_balance;

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    // No second payout posted; balance unchanged.
    expect((string) $creator->fresh()->usdt_balance)->toBe((string) $balanceBefore);
    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->count())->toBe(0);
});

test('already-ManualReview match past deadline is not re-touched', function () {
    [$creator, , , $match] = timedOutMatch();
    $match->update(['status' => MatchStatus::ManualReview]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    expect($match->fresh()->status)->toBe(MatchStatus::ManualReview);
});

// ─── Idempotency ────────────────────────────────────────────────────────────

test('running the command twice on the same timed-out match does not double-pay', function () {
    [$creator, $taker, , $match] = timedOutMatch(stake: '100');
    $match->update(['creator_confirmed_outcome' => MatchOutcome::Won]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();
    $balanceAfterFirst = $creator->fresh()->usdt_balance;

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    // Status guard short-circuits the second run; balance unchanged.
    expect((string) $creator->fresh()->usdt_balance)->toBe((string) $balanceAfterFirst);
    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->count())->toBe(1);
});

// ─── Defensive: both confirmed but still Pending ────────────────────────────

test('match with both confirmations set but status Pending is logged + skipped', function () {
    [, , , $match] = timedOutMatch();

    // Anomaly state: both confirmed but somehow still Pending. The
    // synchronous resolver in GameMatchController::confirm should never leave
    // this state, but defense in depth.
    $match->update([
        'creator_confirmed_outcome' => MatchOutcome::Won,
        'taker_confirmed_outcome' => MatchOutcome::Lost,
    ]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    // Untouched — no auto-settlement.
    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Pending)
        ->and($fresh->winner_user_id)->toBeNull();
    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeFalse();
});

// ─── Conservation across timeout path ───────────────────────────────────────

test('full create → take → timeout flow conserves money across all participants', function () {
    [, , $listing, $match] = timedOutMatch(stake: '100');
    $match->update(['creator_confirmed_outcome' => MatchOutcome::Won]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    // Sum of all ledger entries tied to this listing = 0.
    $sum = WalletTransaction::query()
        ->where('related_listing_id', $listing->id)
        ->sum('amount');

    expect(bccomp((string) $sum, '0', 6))->toBe(0);
});

// ─── Batch processing across multiple matches ───────────────────────────────

test('processes multiple timed-out matches in one run', function () {
    [$creatorA, $takerA, , $matchA] = timedOutMatch(stake: '100');
    $matchA->update(['creator_confirmed_outcome' => MatchOutcome::Won]);

    [$creatorB, $takerB, , $matchB] = timedOutMatch(stake: '200');
    $matchB->update(['taker_confirmed_outcome' => MatchOutcome::Lost]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    // A: creator says Won → creator wins.
    expect($matchA->fresh()->winner_user_id)->toBe($creatorA->id);
    // B: taker says Lost → creator wins (claim honored).
    expect($matchB->fresh()->winner_user_id)->toBe($creatorB->id);

    expect($matchA->fresh()->status)->toBe(MatchStatus::Settled);
    expect($matchB->fresh()->status)->toBe(MatchStatus::Settled);
});
