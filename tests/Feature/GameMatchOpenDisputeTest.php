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

// ─── Happy path: dispute opens → Disputed status, money stays escrowed ─────
// M12 Phase 3 — OpenDisputeAction no longer auto-resolves via the game API.
// It flips the match to Disputed and surfaces it in the admin queue
// (Filament panel). Money stays escrowed until admin clicks Settle/Draw.

test('creator opens dispute → status flips to Disputed, money stays escrowed', function () {
    [$creator, $taker, , $match] = disputableMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertRedirect();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Disputed)
        ->and($fresh->dispute_opened_by)->toBe($creator->id)
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->toBeNull()
        ->and($fresh->api_resolved_at)->toBeNull();

    // Both stakes still escrowed — nothing moves until admin resolves.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeFalse()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});

test('taker opens dispute → status flips to Disputed, dispute_opened_by is taker', function () {
    [, $taker, , $match] = disputableMatch();

    $this->actingAs($taker)
        ->postJson(route('matches.openDispute', $match))
        ->assertRedirect();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Disputed)
        ->and($fresh->dispute_opened_by)->toBe($taker->id);
});

// ─── Race / idempotency ─────────────────────────────────────────────────────

test('second openDispute on the same match is blocked by policy (already Disputed)', function () {
    [$creator, , , $match] = disputableMatch();

    // First call flips to Disputed.
    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match));

    expect($match->fresh()->status)->toBe(MatchStatus::Disputed);

    // Second call: policy blocks (openDispute policy requires Pending).
    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertForbidden();
});

// ─── Toast assertion ────────────────────────────────────────────────────────

test('opening a dispute flashes the admin-review toast', function () {
    [$creator, , , $match] = disputableMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.openDispute', $match))
        ->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => 'Dispute opened — an admin will review and resolve this match.',
        ]);
});
