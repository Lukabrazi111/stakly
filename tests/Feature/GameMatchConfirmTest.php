<?php

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Enums\WalletTransactionType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
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

// ─── Both confirm + disagree → auto-dispute → API resolves ──────────────────

test('both confirm Won → auto-dispute → API resolves to creator', function () {
    [$creator, $taker, , $match] = pendingMatch();
    mockGameApi()->forceWinner($creator->id);

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $fresh = $match->fresh();

    // Match auto-disputed (dispute_opened_at set), then API resolved to
    // Settled in the same request.
    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->settled_at)->not->toBeNull()
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull()
        ->and($fresh->api_response)->toBeArray();

    // Pot = $200, fee = $20 (10%), winner payout = $180.
    expect((string) $creator->fresh()->usdt_balance)->toBe('580.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');
});

test('both confirm Won with Lichess card present → settles to card winner, ignoring MockGameApi', function () {
    // Reproduces the bug surfaced during real testing: previously both
    // claiming Won fell to MockGameApi → deterministic-by-parity winner
    // contradicted the Lichess card sitting RIGHT THERE in chat. With
    // `ChessGameApi` bound as the default driver, the card wins.
    //
    // Forcing MockGameApi to the OTHER player makes the assertion stronger:
    // if the test passes, the card was actually consulted (not just
    // coincidentally agreeing with the mock).
    [$creator, $taker, , $match] = pendingMatch(stake: '100');

    // Snapshot Lichess accounts on the match (TakeListingAction normally
    // does this; pendingMatch's bare-bones factory doesn't).
    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_CREATOR,
        'provider' => LinkedAccountProvider::Lichess,
        'username' => 'alice-lichess',
    ]);
    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_TAKER,
        'provider' => LinkedAccountProvider::Lichess,
        'username' => 'bob-lichess',
    ]);

    // Pretend the auto-fetch job ran and posted a card naming the TAKER
    // as the Lichess winner. If ChessGameApi works, dispute resolves to
    // the taker — not whoever MockGameApi would have picked.
    Message::create([
        'match_id' => $match->id,
        'user_id' => null,
        'type' => MessageType::System,
        'content' => 'Verified Lichess game record.',
        'attachments_json' => [[
            'type' => 'game_card',
            'provider' => 'lichess',
            'source' => 'auto_fetch',
            'game_id' => 'abcdefgh',
            'verified' => true,
            'winner_username' => 'bob-lichess',
            'status' => 'mate',
        ]],
    ]);

    // Force mock to creator — the opposite of what the card says. If the
    // card path runs, taker wins; if mock falls through, creator wins.
    // The test asserting taker wins proves the card path took priority.
    mockGameApi()->forceWinner($creator->id);

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($taker->id)
        ->and($fresh->api_response['driver'])->toBe('chess')
        ->and($fresh->api_response['mode'])->toBe('auto_fetched_card');

    // Pot $200 - $20 fee = $180 to the taker. Creator's stake stays held.
    expect((string) $taker->fresh()->usdt_balance)->toBe('580.000000');
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
});

test('auto-dispute posts a "conflict — resolving via game record" system message before settlement', function () {
    // Closes the UX gap where chat jumped silently from
    // "Bob confirmed: Won" to "Match settled. {name} wins" with no
    // explanation of how arbitration was triggered. The dispute-narration
    // message must land BEFORE the settlement message so the chat reads in
    // causal order.
    [$creator, $taker, , $match] = pendingMatch();
    mockGameApi()->forceWinner($creator->id);

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $systemMessages = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->orderBy('id')
        ->get();

    $conflictIdx = $systemMessages->search(
        fn ($m) => str_contains($m->content, 'confirmations conflict'),
    );
    $settledIdx = $systemMessages->search(
        fn ($m) => str_contains($m->content, 'Match settled.'),
    );

    expect($conflictIdx)->not->toBeFalse('conflict-narration system message missing')
        ->and($settledIdx)->not->toBeFalse('settlement system message missing')
        ->and($conflictIdx)->toBeLessThan($settledIdx,
            'conflict message must precede settlement message');
});

test('both confirm Lost → auto-dispute → API resolves to taker', function () {
    [, , , $match] = pendingMatch();
    [$creator, $taker] = [$match->listing->user, $match->taker];
    mockGameApi()->forceWinner($taker->id);

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'lost']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'lost']);

    expect($match->fresh()->status)->toBe(MatchStatus::Settled)
        ->and($match->fresh()->winner_user_id)->toBe($taker->id);
});

test('both confirm + API unknown → ManualReview, money stays locked', function () {
    [$creator, $taker, , $match] = pendingMatch();
    mockGameApi()->forceUnknown();

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::ManualReview)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->toBeNull()
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull();

    // Both stakes still escrowed — no payout, no fee, balances unchanged
    // from post-hold state.
    expect((string) $creator->fresh()->usdt_balance)->toBe('400.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('400.000000');

    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeFalse()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
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

test('disagreement → API confirmed → settled-by-api toast', function () {
    [$creator, $taker, , $match] = pendingMatch();
    mockGameApi()->forceWinner($creator->id);

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $this->actingAs($taker)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Players disagreed — game API resolved the match.',
        ]);
});

test('disagreement → API unknown → manual-review toast', function () {
    [$creator, $taker, , $match] = pendingMatch();
    mockGameApi()->forceUnknown();

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $this->actingAs($taker)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => 'Game API could not determine a winner — match flagged for admin review.',
        ]);
});

// ─── Drawn outcome (Phase 6.7) ──────────────────────────────────────────────

test('both confirm Drawn → settled as draw, both refunded, no fee, no dispute', function () {
    [$creator, $taker, , $match] = pendingMatch(stake: '100');

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'drawn']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'drawn']);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->not->toBeNull()
        // Both-agree-on-draw skips the dispute path entirely.
        ->and($fresh->dispute_opened_at)->toBeNull()
        ->and($fresh->api_resolved_at)->toBeNull();

    // Both refunded back to $500.
    expect((string) $creator->fresh()->usdt_balance)->toBe('500.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('500.000000');

    // No fee.
    expect(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});

test('both confirm Drawn flashes a settled-as-draw toast', function () {
    [$creator, $taker, , $match] = pendingMatch();

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'drawn']);

    $this->actingAs($taker)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'drawn'])
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Both players agreed it was a draw. Stakes refunded.',
        ]);
});

test('creator Drawn + taker Won → auto-dispute → API arbitrates to winner', function () {
    [$creator, $taker, , $match] = pendingMatch();
    mockGameApi()->forceWinner($creator->id);

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'drawn']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull();
});

test('creator Won + taker Drawn → auto-dispute → API arbitrates to winner', function () {
    [$creator, $taker, , $match] = pendingMatch();
    mockGameApi()->forceWinner($taker->id);

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'drawn']);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($taker->id)
        ->and($fresh->dispute_opened_at)->not->toBeNull();
});

test('disagreement involving Drawn → API ruled draw → both refunded, no fee', function () {
    [$creator, $taker, , $match] = pendingMatch(stake: '100');
    mockGameApi()->forceDraw();

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'drawn']);
    $this->actingAs($taker)->postJson(route('matches.confirm', $match), ['outcome' => 'lost']);

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->not->toBeNull()
        ->and($fresh->dispute_opened_at)->not->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull();

    expect((string) $creator->fresh()->usdt_balance)->toBe('500.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('500.000000');

    expect(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();
});

test('API ruled draw on auto-dispute flashes settled-by-api-draw toast', function () {
    [$creator, $taker, , $match] = pendingMatch();
    mockGameApi()->forceDraw();

    $this->actingAs($creator)->postJson(route('matches.confirm', $match), ['outcome' => 'won']);

    $this->actingAs($taker)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Players disagreed — game API ruled it a draw. Stakes refunded.',
        ]);
});
