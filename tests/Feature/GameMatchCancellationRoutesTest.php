<?php

use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Services\Wallet;

/**
 * HTTP-layer coverage for the three M10 cancellation routes
 * (`matches.cancellation.{request,accept,reject}`).
 *
 * Phase 2's `GameMatchCancellationTest` already exercises the Action
 * internals end-to-end (race scenarios, ledger conservation, self-action
 * defensive blocks, etc.). This file is intentionally narrower — it
 * verifies the wiring: guest auth, policy → 403 mapping, FormRequest
 * validation, sentinel → flash toast mapping, and that a happy-path
 * POST actually persists the right state through the full HTTP stack.
 */
function cancellableRouteMatch(string $stake = '100'): array
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

// ═══════════════════════════════════════════════════════════════════════════
// POST /matches/{match}/cancellation  (matches.cancellation.request)
// ═══════════════════════════════════════════════════════════════════════════

test('guest cannot request cancellation — redirected to login', function () {
    [, , , $match] = cancellableRouteMatch();

    $this->post(route('matches.cancellation.request', $match))
        ->assertRedirect(route('login'));
});

test('non-participant cannot request cancellation (403)', function () {
    [, , , $match] = cancellableRouteMatch();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('matches.cancellation.request', $match))
        ->assertForbidden();
});

test('reason longer than 200 chars fails validation (422)', function () {
    [$creator, , , $match] = cancellableRouteMatch();

    $this->actingAs($creator)
        ->postJson(route('matches.cancellation.request', $match), [
            'reason' => str_repeat('a', 201),
        ])
        ->assertJsonValidationErrors('reason');
});

test('whitespace-only reason normalizes to null at the request boundary', function () {
    [$creator, , , $match] = cancellableRouteMatch();

    $this->actingAs($creator)
        ->post(route('matches.cancellation.request', $match), ['reason' => '   '])
        ->assertRedirect();

    expect($match->fresh()->cancellation_reason)->toBeNull()
        ->and($match->fresh()->cancellation_requested_by)->toBe($creator->id);
});

test('participant request happy path: 302 + state recorded + neutral system message', function () {
    [$creator, , , $match] = cancellableRouteMatch();

    $this->actingAs($creator)
        ->post(route('matches.cancellation.request', $match), [
            'reason' => 'Opponent went AFK',
        ])
        ->assertRedirect();

    $fresh = $match->fresh();
    expect($fresh->cancellation_requested_by)->toBe($creator->id)
        ->and($fresh->cancellation_reason)->toBe('Opponent went AFK')
        ->and($fresh->status)->toBe(MatchStatus::Pending);

    // Security invariant — reason is NOT in the system message body
    // (the structured banner carries it on the opponent's screen).
    $systemMessage = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->latest('id')
        ->first();
    expect($systemMessage->content)->toContain($creator->name)
        ->and($systemMessage->content)->not->toContain('Opponent went AFK');
});

test('cannot request cancellation on a Settled match (403 via policy)', function () {
    [$creator, , , $match] = cancellableRouteMatch();
    $match->update([
        'status' => MatchStatus::Settled,
        'winner_user_id' => $creator->id,
        'settled_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.cancellation.request', $match))
        ->assertForbidden();
});

test('cannot request cancellation while an open request already exists (403 via policy)', function () {
    [$creator, $taker, , $match] = cancellableRouteMatch();
    $match->update([
        'cancellation_requested_by' => $taker->id,
        'cancellation_requested_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.cancellation.request', $match))
        ->assertForbidden();
});

// ═══════════════════════════════════════════════════════════════════════════
// POST /matches/{match}/cancellation/accept  (matches.cancellation.accept)
// ═══════════════════════════════════════════════════════════════════════════

test('guest cannot accept cancellation — redirected to login', function () {
    [, , , $match] = cancellableRouteMatch();

    $this->post(route('matches.cancellation.accept', $match))
        ->assertRedirect(route('login'));
});

test('non-participant cannot accept cancellation (403)', function () {
    [$creator, , , $match] = cancellableRouteMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('matches.cancellation.accept', $match))
        ->assertForbidden();
});

test('requester cannot accept their own cancellation request (403)', function () {
    [$creator, , , $match] = cancellableRouteMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.cancellation.accept', $match))
        ->assertForbidden();
});

test('cannot accept when no open request exists (403 via policy)', function () {
    [, $taker, , $match] = cancellableRouteMatch();
    // No cancellation_requested_at set on this match.

    $this->actingAs($taker)
        ->postJson(route('matches.cancellation.accept', $match))
        ->assertForbidden();
});

test('accept happy path: 302 + match Cancelled + listing Cancelled + both stakes refunded', function () {
    [$creator, $taker, $listing, $match] = cancellableRouteMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    $this->actingAs($taker)
        ->post(route('matches.cancellation.accept', $match))
        ->assertRedirect();

    expect($match->fresh()->status)->toBe(MatchStatus::Cancelled)
        ->and($match->fresh()->cancelled_at)->not->toBeNull()
        ->and($listing->fresh()->status)->toBe(ListingStatus::Cancelled)
        // Both players' spendable balances restored to pre-match $500.
        ->and((float) $creator->fresh()->usdt_balance)->toBe(500.0)
        ->and((float) $taker->fresh()->usdt_balance)->toBe(500.0);
});

// ═══════════════════════════════════════════════════════════════════════════
// POST /matches/{match}/cancellation/reject  (matches.cancellation.reject)
// ═══════════════════════════════════════════════════════════════════════════

test('guest cannot reject cancellation — redirected to login', function () {
    [, , , $match] = cancellableRouteMatch();

    $this->post(route('matches.cancellation.reject', $match))
        ->assertRedirect(route('login'));
});

test('non-participant cannot reject cancellation (403)', function () {
    [$creator, , , $match] = cancellableRouteMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('matches.cancellation.reject', $match))
        ->assertForbidden();
});

test('requester cannot reject their own cancellation request (403)', function () {
    [$creator, , , $match] = cancellableRouteMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    $this->actingAs($creator)
        ->postJson(route('matches.cancellation.reject', $match))
        ->assertForbidden();
});

test('cannot reject when no open request exists (403 via policy)', function () {
    [, $taker, , $match] = cancellableRouteMatch();

    $this->actingAs($taker)
        ->postJson(route('matches.cancellation.reject', $match))
        ->assertForbidden();
});

test('reject happy path: 302 + request closed + cooldown clock set + requester preserved', function () {
    [$creator, $taker, , $match] = cancellableRouteMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
        'cancellation_reason' => 'Opponent went AFK',
    ]);

    $this->actingAs($taker)
        ->post(route('matches.cancellation.reject', $match))
        ->assertRedirect();

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Pending)  // unchanged
        ->and($fresh->cancellation_requested_at)->toBeNull()
        ->and($fresh->cancellation_reason)->toBeNull()
        ->and($fresh->cancellation_requested_by)->toBe($creator->id)  // preserved for cooldown
        ->and($fresh->cancellation_rejected_at)->not->toBeNull();
});
