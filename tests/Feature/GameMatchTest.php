<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

// ─── Model casts ────────────────────────────────────────────────────────────

test('status casts to the MatchStatus enum', function () {
    $match = GameMatch::factory()->create();

    expect($match->status)->toBe(MatchStatus::Pending);
});

test('confirmed-outcome columns cast to MatchOutcome enum (when set)', function () {
    $match = GameMatch::factory()->create([
        'creator_confirmed_outcome' => MatchOutcome::Won,
        'taker_confirmed_outcome' => MatchOutcome::Lost,
    ]);

    expect($match->creator_confirmed_outcome)->toBe(MatchOutcome::Won)
        ->and($match->taker_confirmed_outcome)->toBe(MatchOutcome::Lost);
});

test('confirmed-outcome columns are null by default', function () {
    $match = GameMatch::factory()->create();

    expect($match->creator_confirmed_outcome)->toBeNull()
        ->and($match->taker_confirmed_outcome)->toBeNull();
});

test('datetime columns cast to Carbon instances', function () {
    $match = GameMatch::factory()->disputed()->settled()->create();

    expect($match->dispute_opened_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($match->settled_at)->toBeInstanceOf(CarbonInterface::class);
});

// ─── Relations ──────────────────────────────────────────────────────────────

test('listing relation resolves', function () {
    $match = GameMatch::factory()->create();

    expect($match->listing)->toBeInstanceOf(Listing::class);
});

test('taker relation resolves to the right user', function () {
    $taker = User::factory()->create();
    $match = GameMatch::factory()->create(['taker_user_id' => $taker->id]);

    expect($match->taker)->toBeInstanceOf(User::class)
        ->and($match->taker->id)->toBe($taker->id);
});

test('winner relation resolves when set, null when unset', function () {
    $winner = User::factory()->create();
    $settled = GameMatch::factory()->settled($winner)->create();
    $pending = GameMatch::factory()->create();

    expect($settled->winner->id)->toBe($winner->id)
        ->and($pending->winner)->toBeNull();
});

test('disputeOpener relation resolves when set', function () {
    $opener = User::factory()->create();
    $match = GameMatch::factory()->disputed($opener)->create();

    expect($match->disputeOpener->id)->toBe($opener->id);
});

test('listing has a gameMatch reverse relation (1:1)', function () {
    $match = GameMatch::factory()->create();

    expect($match->listing->gameMatch->id)->toBe($match->id);
});

test('user has a gameMatchesAsTaker relation', function () {
    $taker = User::factory()->create();
    $match1 = GameMatch::factory()->create(['taker_user_id' => $taker->id]);
    $match2 = GameMatch::factory()->create(['taker_user_id' => $taker->id]);
    GameMatch::factory()->create();  // unrelated, different taker

    expect($taker->gameMatchesAsTaker)->toHaveCount(2)
        ->and($taker->gameMatchesAsTaker->pluck('id')->all())
        ->toEqualCanonicalizing([$match1->id, $match2->id]);
});

// ─── Schema invariants ──────────────────────────────────────────────────────

test('listing_id is unique on game_matches (1:1 enforced at DB)', function () {
    $listing = Listing::factory()->taken()->create();
    GameMatch::factory()->create(['listing_id' => $listing->id]);

    expect(fn () => GameMatch::factory()->create(['listing_id' => $listing->id]))
        ->toThrow(QueryException::class);
});

// ─── Policy: view ───────────────────────────────────────────────────────────

test('listing creator can view the match', function () {
    $match = GameMatch::factory()->create();

    expect($match->listing->user->can('view', $match))->toBeTrue();
});

test('match taker can view the match', function () {
    $match = GameMatch::factory()->create();

    expect($match->taker->can('view', $match))->toBeTrue();
});

test('non-participant cannot view the match', function () {
    $match = GameMatch::factory()->create();
    $stranger = User::factory()->create();

    expect($stranger->can('view', $match))->toBeFalse();
});

// ─── Policy: openDispute (Pending only) ─────────────────────────────────────

test('participant can open dispute on Pending', function () {
    $match = GameMatch::factory()->create();

    expect($match->taker->can('openDispute', $match))->toBeTrue()
        ->and($match->listing->user->can('openDispute', $match))->toBeTrue();
});

test('participant cannot open dispute on non-Pending', function (string $factoryState) {
    $match = GameMatch::factory()->{$factoryState}()->create();

    expect($match->taker->can('openDispute', $match))->toBeFalse()
        ->and($match->listing->user->can('openDispute', $match))->toBeFalse();
})->with(['disputed', 'settled', 'manualReview']);

test('non-participant cannot open dispute even on Pending', function () {
    $match = GameMatch::factory()->create();
    $stranger = User::factory()->create();

    expect($stranger->can('openDispute', $match))->toBeFalse();
});

// ─── Policy: requestCancellation (Pending + no open + past cooldown) ────────

test('participant can request cancellation on a fresh Pending match', function () {
    $match = GameMatch::factory()->create();

    expect($match->taker->can('requestCancellation', $match))->toBeTrue()
        ->and($match->listing->user->can('requestCancellation', $match))->toBeTrue();
});

test('participant cannot request cancellation on non-Pending', function (string $factoryState) {
    $match = GameMatch::factory()->{$factoryState}()->create();

    expect($match->taker->can('requestCancellation', $match))->toBeFalse()
        ->and($match->listing->user->can('requestCancellation', $match))->toBeFalse();
})->with(['disputed', 'settled', 'manualReview', 'cancelled']);

test('non-participant cannot request cancellation even on Pending', function () {
    $match = GameMatch::factory()->create();
    $stranger = User::factory()->create();

    expect($stranger->can('requestCancellation', $match))->toBeFalse();
});

test('cannot request cancellation when an open request already exists', function () {
    $match = GameMatch::factory()->create();
    $match = $match->fresh();  // reload to bind taker relation
    $match->update([
        'cancellation_requested_by' => $match->taker_user_id,
        'cancellation_requested_at' => now(),
    ]);

    expect($match->fresh()->taker->can('requestCancellation', $match->fresh()))->toBeFalse()
        ->and($match->fresh()->listing->user->can('requestCancellation', $match->fresh()))->toBeFalse();
});

test('requester is in cooldown for 30 min after their request is rejected', function () {
    $match = GameMatch::factory()->create();
    // Simulate a rejected request from the taker 10 min ago — still inside cooldown.
    $match->update([
        'cancellation_requested_by' => $match->taker_user_id,
        'cancellation_requested_at' => null,
        'cancellation_rejected_at' => now()->subMinutes(10),
    ]);

    expect($match->fresh()->taker->can('requestCancellation', $match->fresh()))->toBeFalse();
});

test('requester can re-request after cooldown expires (31 min)', function () {
    $match = GameMatch::factory()->create();
    $match->update([
        'cancellation_requested_by' => $match->taker_user_id,
        'cancellation_requested_at' => null,
        'cancellation_rejected_at' => now()->subMinutes(31),
    ]);

    expect($match->fresh()->taker->can('requestCancellation', $match->fresh()))->toBeTrue();
});

test('cooldown is per-user — the OTHER participant can request immediately after a rejection', function () {
    $match = GameMatch::factory()->create();
    // Taker was rejected 5 min ago; creator should still be free to request.
    $match->update([
        'cancellation_requested_by' => $match->taker_user_id,
        'cancellation_requested_at' => null,
        'cancellation_rejected_at' => now()->subMinutes(5),
    ]);

    $creator = $match->fresh()->listing->user;
    expect($creator->can('requestCancellation', $match->fresh()))->toBeTrue();
});

// ─── Policy: acceptCancellation / rejectCancellation (non-requester only) ───

test('the OTHER participant can accept or reject an open cancellation request', function () {
    $match = GameMatch::factory()->create();
    $taker = $match->taker;
    $creator = $match->listing->user;
    // Creator requested; taker should be able to accept OR reject.
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    expect($taker->can('acceptCancellation', $match->fresh()))->toBeTrue()
        ->and($taker->can('rejectCancellation', $match->fresh()))->toBeTrue();
});

test('the REQUESTER cannot accept or reject their own request', function () {
    $match = GameMatch::factory()->create();
    $creator = $match->listing->user;
    $match->update([
        'cancellation_requested_by' => $creator->id,
        'cancellation_requested_at' => now(),
    ]);

    expect($creator->can('acceptCancellation', $match->fresh()))->toBeFalse()
        ->and($creator->can('rejectCancellation', $match->fresh()))->toBeFalse();
});

test('non-participant cannot accept or reject', function () {
    $match = GameMatch::factory()->create();
    $stranger = User::factory()->create();
    $match->update([
        'cancellation_requested_by' => $match->taker_user_id,
        'cancellation_requested_at' => now(),
    ]);

    expect($stranger->can('acceptCancellation', $match->fresh()))->toBeFalse()
        ->and($stranger->can('rejectCancellation', $match->fresh()))->toBeFalse();
});

test('cannot accept or reject when there is no open request', function () {
    $match = GameMatch::factory()->create();
    // Default — no cancellation cols set.

    expect($match->taker->can('acceptCancellation', $match))->toBeFalse()
        ->and($match->listing->user->can('rejectCancellation', $match))->toBeFalse();
});

test('cannot accept or reject on non-Pending match', function (string $factoryState) {
    $match = GameMatch::factory()->{$factoryState}()->create();
    // Even if a request was somehow set, non-Pending blocks both actions.
    $match->update([
        'cancellation_requested_by' => $match->taker_user_id,
        'cancellation_requested_at' => now(),
    ]);

    expect($match->fresh()->listing->user->can('acceptCancellation', $match->fresh()))->toBeFalse()
        ->and($match->fresh()->listing->user->can('rejectCancellation', $match->fresh()))->toBeFalse();
})->with(['disputed', 'settled', 'manualReview', 'cancelled']);
