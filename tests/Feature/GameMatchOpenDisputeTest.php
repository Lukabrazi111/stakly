<?php

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/**
 * Helper: a Pending match with both stakes already escrowed — the state in
 * which `openDispute` is allowed. Mirrors `pendingMatch` in GameMatchConfirmTest
 * but local-named to keep the two test files self-contained.
 */
function disputableMatch(string $stake = '100'): array
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

    return [$creator, $taker, $listing, $match];
}

// ─── Authorization ──────────────────────────────────────────────────────────

test('guest cannot open dispute — redirected to login', function () {
    [, , , $match] = disputableMatch();

    $this->post(route('matches.openDispute', $match))
        ->assertRedirect(route('login'));
});

test('non-participant cannot open dispute (403)', function () {
    [, , , $match] = disputableMatch();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('matches.openDispute', $match))
        ->assertForbidden();
});

test('cannot open dispute on a Settled match (403 via policy)', function () {
    [$creator, , , $match] = disputableMatch();
    $match->update([
        'status' => MatchStatus::Settled,
        'winner_user_id' => $creator->id,
        'settled_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertForbidden();
});

test('cannot open dispute on a ManualReview match (403 via policy)', function () {
    [$creator, , , $match] = disputableMatch();
    $match->update([
        'status' => MatchStatus::ManualReview,
        'dispute_opened_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertForbidden();
});

// ─── Happy path: API confirmed → Settled ─────────────────────────────────────

test('creator opens dispute → API confirmed → Settled with API winner', function () {
    [$creator, $taker, , $match] = disputableMatch();
    mockGameApi()->forceWinner($taker->id);

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertRedirect();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($taker->id)
        ->and($fresh->dispute_opened_by)->toBe($creator->id)
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->settled_at)->not->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull()
        ->and($fresh->api_response)->toBeArray();

    // Pot = $200, fee = $20, payout = $180 to taker.
    expect((string) $taker->fresh()->usdt_balance)->toBe('580.000000');
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
});

test('taker opens dispute → API confirmed → Settled with API winner', function () {
    [$creator, $taker, , $match] = disputableMatch();
    mockGameApi()->forceWinner($creator->id);

    $this->actingAs($taker)
        ->postJson(route('matches.openDispute', $match))
        ->assertRedirect();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->dispute_opened_by)->toBe($taker->id);

    expect((string) $creator->fresh()->usdt_balance)->toBe('580.000000');
});

// ─── ManualReview branch ────────────────────────────────────────────────────

test('opens dispute → API unknown → ManualReview, money locked', function () {
    [$creator, $taker, , $match] = disputableMatch();
    mockGameApi()->forceUnknown();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match));

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::ManualReview)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->toBeNull()
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->dispute_opened_by)->toBe($creator->id)
        ->and($fresh->api_resolved_at)->not->toBeNull();

    // Both stakes still escrowed.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeFalse()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});

// ─── Race / idempotency ─────────────────────────────────────────────────────

test('repeat openDispute after settlement is blocked by policy', function () {
    [$creator, , , $match] = disputableMatch();
    mockGameApi()->forceWinner($creator->id);

    // First call settles via API.
    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match));

    expect($match->fresh()->status)->toBe(MatchStatus::Settled);

    // Second call: policy blocks (status != Pending). The "too-late" toast
    // path is for the rarer in-flight race of opponent confirming during
    // our request lifetime, not user double-clicks.
    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertForbidden();
});

// ─── Toast assertions ───────────────────────────────────────────────────────

test('successful dispute resolution flashes settled-by-api toast', function () {
    [$creator, , , $match] = disputableMatch();
    mockGameApi()->forceWinner($creator->id);

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Dispute resolved — game API determined the winner.',
        ]);
});

test('unknown API resolution flashes manual-review toast', function () {
    [$creator, , , $match] = disputableMatch();
    mockGameApi()->forceUnknown();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => 'Dispute opened — game API could not determine a winner. Match flagged for admin review.',
        ]);
});
