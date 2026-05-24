<?php

use App\Actions\GameMatch\AcceptCancellationAction;
use App\Actions\GameMatch\RejectCancellationAction;
use App\Actions\GameMatch\RequestCancellationAction;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Enums\WalletTransactionType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;
use Carbon\CarbonImmutable;

/**
 * Helper: a Pending match with both stakes already escrowed and the
 * platform user seeded — same shape as `pendingMatch()` from
 * GameMatchConfirmTest, just lifted in here so this file is
 * self-contained. Returns [creator, taker, listing, match].
 *
 * Stake fixed at $100 each — keeps the conservation arithmetic readable
 * in the ledger tests below ($100 hold debit ↔ $100 release credit on
 * each side, net 0).
 */
function cancellableMatch(): array
{
    platformUser();

    $creator = User::factory()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $taker = User::factory()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()->taken()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();

    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );
    Wallet::hold(
        user: $taker,
        amount: '100',
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
// RequestCancellationAction
// ═══════════════════════════════════════════════════════════════════════════

test('request happy path: records cols + posts message + returns "requested"', function () {
    [$creator, , , $match] = cancellableMatch();

    $result = app(RequestCancellationAction::class)->handle($creator, $match, 'Opponent went AFK');

    expect($result)->toBe('requested');

    $fresh = $match->fresh();
    expect($fresh->cancellation_requested_by)->toBe($creator->id)
        ->and($fresh->cancellation_requested_at)->not->toBeNull()
        ->and($fresh->cancellation_reason)->toBe('Opponent went AFK')
        ->and($fresh->status)->toBe(MatchStatus::Pending);  // status doesn't flip on request
});

test('request system message includes the reason when supplied', function () {
    [$creator, , , $match] = cancellableMatch();

    app(RequestCancellationAction::class)->handle($creator, $match, 'Opponent went AFK');

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->latest('id')
        ->first();

    expect($message->content)->toContain($creator->name)
        ->and($message->content)->toContain('Opponent went AFK')
        ->and($message->content)->toContain('Reason:');
});

test('request system message omits the reason clause when null', function () {
    [$creator, , , $match] = cancellableMatch();

    app(RequestCancellationAction::class)->handle($creator, $match, null);

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->latest('id')
        ->first();

    expect($message->content)->toContain($creator->name)
        ->and($message->content)->not->toContain('Reason:');
});

test('request system message treats whitespace-only reason as no-reason', function () {
    [$creator, , , $match] = cancellableMatch();

    app(RequestCancellationAction::class)->handle($creator, $match, '   ');

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->latest('id')
        ->first();

    expect($message->content)->not->toContain('Reason:');
});

test('request clears a stale cancellation_rejected_at marker', function () {
    [$creator, , , $match] = cancellableMatch();
    // Simulate a previous request from the same user that got rejected
    // 31 minutes ago (past cooldown — policy would now permit re-request).
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => null,
        'cancellation_rejected_at' => CarbonImmutable::now()->subMinutes(31),
    ]);

    app(RequestCancellationAction::class)->handle($creator->fresh(), $match->fresh());

    expect($match->fresh()->cancellation_rejected_at)->toBeNull();
});

test('request returns "race_lost" when status is no longer Pending', function () {
    [$creator, , , $match] = cancellableMatch();
    $match->update(['status' => MatchStatus::Settled, 'settled_at' => now()]);

    $result = app(RequestCancellationAction::class)->handle($creator, $match->fresh());

    expect($result)->toBe('race_lost')
        ->and(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->exists())
        ->toBeFalse();
});

test('request returns "race_lost" when an open request already exists', function () {
    [$creator, $taker, , $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $taker->id,
        'cancellation_requested_at' => now(),
    ]);

    // Creator tries to request while taker's request is in-flight.
    $result = app(RequestCancellationAction::class)->handle($creator, $match->fresh());

    expect($result)->toBe('race_lost')
        ->and($match->fresh()->cancellation_requested_by)->toBe($taker->id);  // unchanged
});

// ═══════════════════════════════════════════════════════════════════════════
// AcceptCancellationAction
// ═══════════════════════════════════════════════════════════════════════════

test('accept happy path: status → Cancelled, listing → Cancelled, returns "cancelled"', function () {
    [$creator, $taker, $listing, $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    $result = app(AcceptCancellationAction::class)->handle($taker, $match->fresh());

    expect($result)->toBe('cancelled')
        ->and($match->fresh()->status)->toBe(MatchStatus::Cancelled)
        ->and($match->fresh()->cancelled_at)->not->toBeNull()
        ->and($listing->fresh()->status)->toBe(ListingStatus::Cancelled);
});

test('accept refunds both stakes — conservation invariant holds per match', function () {
    [$creator, $taker, $listing, $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    $creatorBalanceBefore = $creator->fresh()->usdt_balance;
    $takerBalanceBefore = $taker->fresh()->usdt_balance;

    app(AcceptCancellationAction::class)->handle($taker, $match->fresh());

    // Each side: original $500 deposit - $100 hold + $100 release = $500.
    // The hold/release pair nets to zero on the spendable balance.
    $creatorAfter = $creator->fresh()->usdt_balance;
    $takerAfter = $taker->fresh()->usdt_balance;

    // Released amounts equal the held amounts; spendable balance is restored.
    expect((float) $creatorAfter)->toBe(500.0)
        ->and((float) $takerAfter)->toBe(500.0)
        // The hold transactions reduced spendable by 100 each; the releases
        // restored that 100 each — net change from pre-match state is zero.
        ->and((float) $creatorAfter - (float) $creatorBalanceBefore)->toBe(100.0)
        ->and((float) $takerAfter - (float) $takerBalanceBefore)->toBe(100.0);

    // Conservation: per-match wallet transactions sum to zero
    // (-100 hold creator + -100 hold taker + +100 release creator + +100 release taker = 0).
    $perMatchSum = (float) WalletTransaction::query()
        ->where('related_listing_id', $listing->id)
        ->sum('amount');

    expect($perMatchSum)->toBe(0.0);
});

test('accept posts a "cancelled, stakes refunded" system message', function () {
    [$creator, $taker, , $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    app(AcceptCancellationAction::class)->handle($taker, $match->fresh());

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->latest('id')
        ->first();

    expect($message->content)->toContain($taker->name)
        ->and($message->content)->toContain('cancelled')
        ->and($message->content)->toContain('refunded');
});

test('accept is idempotent on already-Cancelled match (returns "already_cancelled", no double refund)', function () {
    [$creator, $taker, $listing, $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    app(AcceptCancellationAction::class)->handle($taker, $match->fresh());
    $balanceAfterFirstAccept = $taker->fresh()->usdt_balance;
    $releaseCountAfterFirst = WalletTransaction::query()
        ->where('related_listing_id', $listing->id)
        ->where('type', WalletTransactionType::EscrowRelease)
        ->count();

    $result = app(AcceptCancellationAction::class)->handle($taker, $match->fresh());

    expect($result)->toBe('already_cancelled')
        ->and((float) $taker->fresh()->usdt_balance)->toBe((float) $balanceAfterFirstAccept)
        ->and(WalletTransaction::query()
            ->where('related_listing_id', $listing->id)
            ->where('type', WalletTransactionType::EscrowRelease)
            ->count())->toBe($releaseCountAfterFirst);
});

test('accept blocks the requester from accepting their own request (self_accept_forbidden)', function () {
    [$creator, , , $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    // Creator tries to accept their own request (bypassing the UI / policy).
    $result = app(AcceptCancellationAction::class)->handle($creator, $match->fresh());

    expect($result)->toBe('self_accept_forbidden')
        ->and($match->fresh()->status)->toBe(MatchStatus::Pending);  // unchanged
});

test('accept returns "request_missing" when there is no open request', function () {
    [, $taker, , $match] = cancellableMatch();

    $result = app(AcceptCancellationAction::class)->handle($taker, $match);

    expect($result)->toBe('request_missing')
        ->and($match->fresh()->status)->toBe(MatchStatus::Pending);
});

test('accept returns "race_lost" when status flipped off Pending before our lock', function () {
    [$creator, $taker, , $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
        'status' => MatchStatus::Disputed,
        'dispute_opened_at' => now(),
    ]);

    $result = app(AcceptCancellationAction::class)->handle($taker, $match->fresh());

    expect($result)->toBe('race_lost')
        ->and($match->fresh()->status)->toBe(MatchStatus::Disputed);
});

// ═══════════════════════════════════════════════════════════════════════════
// RejectCancellationAction
// ═══════════════════════════════════════════════════════════════════════════

test('reject happy path: closes request, sets cooldown clock, returns "rejected"', function () {
    [$creator, $taker, , $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
        'cancellation_reason' => 'I changed my mind',
    ]);

    $result = app(RejectCancellationAction::class)->handle($taker, $match->fresh());

    $fresh = $match->fresh();
    expect($result)->toBe('rejected')
        ->and($fresh->status)->toBe(MatchStatus::Pending)  // status unchanged
        ->and($fresh->cancellation_requested_at)->toBeNull()  // request closed
        ->and($fresh->cancellation_reason)->toBeNull()
        ->and($fresh->cancellation_requested_by)->toBe($creator->id)  // preserved for cooldown
        ->and($fresh->cancellation_rejected_at)->not->toBeNull();  // cooldown clock started
});

test('reject preserves cancellation_requested_by so the cooldown gate finds it', function () {
    [$creator, $taker, , $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    app(RejectCancellationAction::class)->handle($taker, $match->fresh());

    // Policy now sees creator in cooldown.
    expect($creator->fresh()->can('requestCancellation', $match->fresh()))->toBeFalse()
        // And taker (the rejecter) is free to request immediately.
        ->and($taker->fresh()->can('requestCancellation', $match->fresh()))->toBeTrue();
});

test('reject posts a "declined" system message', function () {
    [$creator, $taker, , $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    app(RejectCancellationAction::class)->handle($taker, $match->fresh());

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->latest('id')
        ->first();

    expect($message->content)->toContain($taker->name)
        ->and($message->content)->toContain('declined');
});

test('reject blocks the requester from rejecting their own request (self_reject_forbidden)', function () {
    [$creator, , , $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    $result = app(RejectCancellationAction::class)->handle($creator, $match->fresh());

    expect($result)->toBe('self_reject_forbidden')
        // Request should remain open since rejection was forbidden.
        ->and($match->fresh()->cancellation_requested_at)->not->toBeNull();
});

test('reject returns "request_missing" when there is no open request', function () {
    [, $taker, , $match] = cancellableMatch();

    $result = app(RejectCancellationAction::class)->handle($taker, $match);

    expect($result)->toBe('request_missing');
});

test('reject returns "race_lost" when status is no longer Pending', function () {
    [$creator, $taker, , $match] = cancellableMatch();
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
        'status' => MatchStatus::Settled,
        'winner_user_id' => $creator->id,
        'settled_at' => now(),
    ]);

    $result = app(RejectCancellationAction::class)->handle($taker, $match->fresh());

    expect($result)->toBe('race_lost');
});
