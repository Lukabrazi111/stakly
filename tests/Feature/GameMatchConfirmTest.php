<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Enums\WalletTransactionType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/**
 * Helper: a Pending match with both stakes already escrowed — the state
 * that exists right after Phase 2's `take` action. Returns
 * [creator, taker, listing, match].
 */
function pendingMatch(string $stake = '100'): array
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

    return [$creator, $taker, $listing, $match];
}

// ─── Authorization (participant + Pending only) ─────────────────────────────

test('non-participant cannot confirm (403)', function () {
    [, , , $match] = pendingMatch();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertForbidden();
});

test('guest cannot confirm — redirected to login', function () {
    [, , , $match] = pendingMatch();

    $this->post(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect(route('login'));
});

test('cannot confirm a Settled match (403 via policy)', function () {
    [$creator, , , $match] = pendingMatch();
    $match->update([
        'status' => MatchStatus::Settled,
        'winner_user_id' => $creator->id,
        'settled_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertForbidden();
});

test('cannot confirm a Disputed match (403 via policy)', function () {
    [$creator, , , $match] = pendingMatch();
    $match->update([
        'status' => MatchStatus::Disputed,
        'dispute_opened_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertForbidden();
});

// ─── Body validation ────────────────────────────────────────────────────────

test('outcome is required', function () {
    [$creator, , , $match] = pendingMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), [])
        ->assertJsonValidationErrors('outcome');
});

test('outcome must be a valid enum value', function () {
    [$creator, , , $match] = pendingMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'maybe'])
        ->assertJsonValidationErrors('outcome');
});

// ─── Happy path: first confirmation ─────────────────────────────────────────

test('creator first confirmation records the outcome and stays Pending', function () {
    [$creator, , , $match] = pendingMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect();

    $fresh = $match->fresh();

    expect($fresh->creator_confirmed_outcome)->toBe(MatchOutcome::Won)
        ->and($fresh->taker_confirmed_outcome)->toBeNull()
        ->and($fresh->status)->toBe(MatchStatus::Pending);
});

test('taker first confirmation records the outcome and stays Pending', function () {
    [, $taker, , $match] = pendingMatch();

    $this->actingAs($taker)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'lost'])
        ->assertRedirect();

    $fresh = $match->fresh();

    expect($fresh->taker_confirmed_outcome)->toBe(MatchOutcome::Lost)
        ->and($fresh->creator_confirmed_outcome)->toBeNull()
        ->and($fresh->status)->toBe(MatchStatus::Pending);
});

// ─── Change confirmation freely while Pending ───────────────────────────────

test('player can change confirmation multiple times while Pending', function () {
    [$creator, , , $match] = pendingMatch();

    $this->actingAs($creator);

    // First: Won
    $this->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    expect($match->fresh()->creator_confirmed_outcome)->toBe(MatchOutcome::Won);

    // Change: Lost
    $this->postJson(route('matches.confirm', $match), ['outcome' => 'lost']);
    expect($match->fresh()->creator_confirmed_outcome)->toBe(MatchOutcome::Lost);

    // Change again: Won
    $this->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    expect($match->fresh()->creator_confirmed_outcome)->toBe(MatchOutcome::Won);

    // Match still Pending (taker hasn't confirmed).
    expect($match->fresh()->status)->toBe(MatchStatus::Pending);
});

// ─── Both confirm + agree → settle (real money moves) ───────────────────────

test('both confirm with creator winning settles correctly', function () {
    [$creator, $taker, , $match] = pendingMatch(stake: '100');

    // Creator: Won. Taker: Lost. Mirror images → settle, creator wins.
    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'lost']);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->settled_at)->not->toBeNull();

    // Pot = $200, fee = $20 (10%), winner payout = $180.
    // Creator: $500 (deposit) - $100 (held) + $180 (payout) = $580.
    expect((string) $creator->fresh()->usdt_balance)->toBe('580.000000');

    // Taker: $500 (deposit) - $100 (held). No further wallet op — loss is
    // the permanent hold debit.
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    $payout = WalletTransaction::query()
        ->where('user_id', $creator->id)
        ->where('reference_id', "match-payout:{$match->id}")
        ->firstOrFail();
    expect($payout->amount)->toBe('180.000000')
        ->and($payout->type)->toBe(WalletTransactionType::Payout);

    $fee = WalletTransaction::query()
        ->where('reference_id', "match-fee:{$match->id}")
        ->firstOrFail();
    expect($fee->amount)->toBe('20.000000')
        ->and($fee->type)->toBe(WalletTransactionType::Fee);
});

test('both confirm with taker winning settles correctly', function () {
    [$creator, $taker, , $match] = pendingMatch(stake: '100');

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'lost']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($taker->id);

    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('580.000000');
});

// ─── Both confirm + disagree → dispute ──────────────────────────────────────

test('both confirm Won → dispute (no settlement, no money moves)', function () {
    [$creator, $taker, , $match] = pendingMatch();

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Disputed)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->toBeNull()
        ->and($fresh->dispute_opened_at)->not->toBeNull();

    // Balances unchanged from post-hold state.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    // No payout / fee ledger rows.
    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeFalse()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});

test('both confirm Lost → dispute', function () {
    [, , , $match] = pendingMatch();
    [$creator, $taker] = [$match->listing->user, $match->taker];

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'lost']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'lost']);

    expect($match->fresh()->status)->toBe(MatchStatus::Disputed);
});

// ─── Late-confirm change flips the agreement ────────────────────────────────

test('player can change their mind mid-match (only one confirmed) and outcome resolves correctly', function () {
    [$creator, $taker, , $match] = pendingMatch();

    // Creator says Won.
    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    // Creator changes mind: Lost.
    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'lost']);
    // Now taker says Won — mirror with creator's Lost → settle, taker wins.
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    expect($match->fresh()->winner_user_id)->toBe($taker->id)
        ->and($match->fresh()->status)->toBe(MatchStatus::Settled);
});

// ─── Toast assertions ───────────────────────────────────────────────────────

test('first confirmation flashes a recorded toast', function () {
    [$creator, , , $match] = pendingMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Confirmation recorded.',
        ]);
});

test('settlement flashes a settled toast', function () {
    [$creator, $taker, , $match] = pendingMatch();

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $this->actingAs($taker)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'lost'])
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Both players agreed — match settled.',
        ]);
});

test('disagreement flashes a disputed toast', function () {
    [$creator, $taker, , $match] = pendingMatch();

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $this->actingAs($taker)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => 'Both players disagree — match flagged for review.',
        ]);
});
