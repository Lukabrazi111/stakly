<?php

use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * M16 — `matches:resolve-timeouts` now does one thing: Pending matches
 * past the 4h deadline flip to ManualReview. No honor-claim path (player
 * confirms were removed). No API arbitration (the Phase 2 auto-fetch cron
 * + page-visit + chat-send triggers have been retrying every 5 min for
 * the full window; if no game has been auto-fetched + settled by now,
 * none is going to be — admin / cooling-off owns the resolution).
 */

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

// ─── Happy path: Pending past deadline → ManualReview ──────────────────────

test('Pending match past deadline flips to ManualReview with narration + dispute_prompt', function () {
    [$creator, $taker, , $match] = timedOutMatch(stake: '100');

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::ManualReview)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->toBeNull();

    // Stakes still escrowed — admin owns the resolution.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    // No payout / fee posted.
    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeFalse()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();

    // Two system messages: "expired" narration + dispute_prompt evidence call-to-action.
    $messages = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->orderBy('id')
        ->get();

    expect($messages)->toHaveCount(2);
    expect($messages[0]->content)->toContain('expired')
        ->and($messages[0]->content)->toContain('admin review');
    expect($messages[1]->content)->toContain('Submit evidence')
        ->and($messages[1]->attachments_json)->toBe([['type' => 'dispute_prompt']]);
});

// ─── Eligibility filtering ──────────────────────────────────────────────────

test('Pending match younger than the deadline is left alone', function () {
    [$creator, $taker, , $match] = timedOutMatch(stake: '100', hoursOld: 1);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    expect($match->fresh()->status)->toBe(MatchStatus::Pending)
        ->and($match->fresh()->winner_user_id)->toBeNull();

    // Held balances unchanged.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');
});

test('already-Settled match past deadline is not re-touched', function () {
    [$creator, , , $match] = timedOutMatch();
    $match->update([
        'status' => MatchStatus::Settled,
        'winner_user_id' => $creator->id,
        'settled_at' => now(),
    ]);

    $balanceBefore = $creator->fresh()->usdt_balance;

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    expect((string) $creator->fresh()->usdt_balance)->toBe((string) $balanceBefore);
    expect($match->fresh()->status)->toBe(MatchStatus::Settled);
});

test('already-ManualReview match past deadline is not re-touched', function () {
    [, , , $match] = timedOutMatch();
    $match->update(['status' => MatchStatus::ManualReview]);

    $messagesBefore = Message::query()->where('match_id', $match->id)->count();

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    expect($match->fresh()->status)->toBe(MatchStatus::ManualReview);
    // No duplicate narration / dispute_prompt messages posted.
    expect(Message::query()->where('match_id', $match->id)->count())->toBe($messagesBefore);
});

test('already-Cancelled match past deadline is not re-touched', function () {
    [, , , $match] = timedOutMatch();
    $match->update([
        'status' => MatchStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    expect($match->fresh()->status)->toBe(MatchStatus::Cancelled);
});

test('already-Disputed match past deadline is not re-touched by the timeout resolver', function () {
    [, , , $match] = timedOutMatch();
    $match->update([
        'status' => MatchStatus::Disputed,
        'dispute_opened_at' => now(),
    ]);

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    // Disputed has its own resolution path (ResolveDisputeAction via the
    // OpenDispute trigger); the timeout cron leaves it alone.
    expect($match->fresh()->status)->toBe(MatchStatus::Disputed);
});

// ─── Idempotency ────────────────────────────────────────────────────────────

test('running the command twice on the same timed-out match is idempotent', function () {
    [, , , $match] = timedOutMatch(stake: '100');

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();
    $messagesAfterFirst = Message::query()->where('match_id', $match->id)->count();

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    // Status guard short-circuits the second run; no new messages, status unchanged.
    expect($match->fresh()->status)->toBe(MatchStatus::ManualReview);
    expect(Message::query()->where('match_id', $match->id)->count())->toBe($messagesAfterFirst);
});

// ─── Money does NOT move on timeout (stakes stay escrowed for admin) ───────

test('timeout → ManualReview does not post any payout / fee / refund ledger entries', function () {
    [, , $listing, $match] = timedOutMatch(stake: '100');

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    // Only the two pre-existing holds should remain — no settlement-side
    // entries posted (admin / cooling-off owns the resolution).
    $relatedRefs = WalletTransaction::query()
        ->where('related_listing_id', $listing->id)
        ->pluck('reference_id')
        ->all();

    expect($relatedRefs)
        ->toContain("listing-create:{$listing->id}")
        ->toContain("match-take:{$listing->id}")
        ->not->toContain("match-payout:{$match->id}")
        ->not->toContain("match-fee:{$match->id}")
        ->not->toContain("match-draw-creator:{$match->id}")
        ->not->toContain("match-draw-taker:{$match->id}");
});

// ─── Batch processing across multiple matches ───────────────────────────────

test('processes multiple timed-out matches in one run', function () {
    [, , , $matchA] = timedOutMatch(stake: '100');
    [, , , $matchB] = timedOutMatch(stake: '200');

    $this->artisan('matches:resolve-timeouts')->assertSuccessful();

    expect($matchA->fresh()->status)->toBe(MatchStatus::ManualReview);
    expect($matchB->fresh()->status)->toBe(MatchStatus::ManualReview);
});
