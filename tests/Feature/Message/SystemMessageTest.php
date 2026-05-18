<?php

use App\Actions\GameMatch\ConfirmOutcomeAction;
use App\Actions\GameMatch\OpenDisputeAction;
use App\Actions\GameMatch\ResolveMatchTimeoutAction;
use App\Actions\GameMatch\TakeListingAction;
use App\Enums\MatchOutcome;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\Event;

/**
 * Asserts the system messages posted by lifecycle Actions during a match's
 * journey: started, outcome confirmed, settled, draw, dispute opened, API
 * resolved (3 branches), timeout. Each message is created with `user_id =
 * null` + `type = system` and broadcasts via the same `MessageSent` event
 * as user messages, so subscribed Echo clients render them inline.
 */

/**
 * Helper: latest system message content for a match. Lifecycle Actions
 * post multiple system messages over the course of a resolution; this
 * helper returns the most recent so we can assert on the chronologically
 * last announcement.
 */
function latestSystemMessage(GameMatch $match): ?string
{
    return Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->orderByDesc('id')
        ->value('content');
}

function systemMessageCount(GameMatch $match): int
{
    return Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->count();
}

// ─── TakeListingAction → "Match started." ───────────────────────────────────

test('taking a listing posts a "Match started" system message', function () {
    $creator = User::factory()->active()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:c:{$creator->id}");

    $listing = Listing::factory()->open()->for($creator)->state(['stake_amount' => '100'])->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");

    $taker = User::factory()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:t:{$taker->id}");

    $match = app(TakeListingAction::class)->handle($taker, $listing);

    expect($match)->toBeInstanceOf(GameMatch::class);

    $latest = latestSystemMessage($match);
    expect($latest)->toContain('Match started');

    // Assert message metadata: type=system, user_id=null.
    $message = Message::query()->where('match_id', $match->id)->firstOrFail();
    expect($message->type)->toBe(MessageType::System)
        ->and($message->user_id)->toBeNull();
});

// ─── ConfirmOutcomeAction → "X confirmed: Y." ───────────────────────────────

test('confirming an outcome posts a "{name} confirmed: {outcome}" system message', function () {
    [$creator, $taker, , $match] = pendingMatch();

    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Won);

    expect(latestSystemMessage($match))->toContain($creator->name)
        ->and(latestSystemMessage($match))->toContain('Won');
});

test('Lost confirmation posts "Lost" in the system message', function () {
    [$creator, , , $match] = pendingMatch();

    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Lost);

    expect(latestSystemMessage($match))->toContain('Lost');
});

test('Drawn confirmation posts "Drawn" in the system message', function () {
    [$creator, , , $match] = pendingMatch();

    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Drawn);

    expect(latestSystemMessage($match))->toContain('Drawn');
});

test('changing a confirmation posts a second system message (not just an overwrite)', function () {
    [$creator, , , $match] = pendingMatch();

    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Won);
    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Lost);

    expect(systemMessageCount($match))->toBe(2);
});

test('re-confirming the same outcome does not post a duplicate system message', function () {
    [$creator, , , $match] = pendingMatch();

    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Won);
    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Won);

    expect(systemMessageCount($match))->toBe(1);
});

// ─── SettleMatchAction → "Match settled. {winner} wins $X." ─────────────────

test('both players confirming mirror outcomes posts a "Match settled" system message', function () {
    [$creator, $taker, , $match] = pendingMatch();

    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Won);
    app(ConfirmOutcomeAction::class)->handle($taker, $match, MatchOutcome::Lost);

    $latest = latestSystemMessage($match);
    expect($latest)->toContain('Match settled')
        ->and($latest)->toContain($creator->name);
});

test('settlement message includes the payout amount', function () {
    [$creator, $taker, , $match] = pendingMatch(stake: '100');

    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Won);
    app(ConfirmOutcomeAction::class)->handle($taker, $match, MatchOutcome::Lost);

    // Pot = 200, fee = 10% = 20, payout = 180.
    expect(latestSystemMessage($match))->toContain('180.00');
});

// ─── SettleDrawMatchAction → "Match ended as a draw. Stakes refunded." ──────

test('both players confirming Drawn posts a "Match ended as a draw" system message', function () {
    [$creator, $taker, , $match] = pendingMatch();

    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Drawn);
    app(ConfirmOutcomeAction::class)->handle($taker, $match, MatchOutcome::Drawn);

    expect(latestSystemMessage($match))->toContain('draw')
        ->and(latestSystemMessage($match))->toContain('refunded');
});

// ─── OpenDisputeAction → "Dispute opened by {name}." ────────────────────────

test('opening a dispute posts a "Dispute opened by {name}" system message', function () {
    [$creator, , , $match] = pendingMatch();

    // Mock API winner so post-dispute settle works deterministically.
    mockGameApi()->forceWinner($creator->id);

    app(OpenDisputeAction::class)->handle($creator, $match);

    // The dispute path posts at least the dispute message, plus the
    // settlement-after-resolution messages. Verify dispute appears in the
    // chain by checking ALL system messages.
    $allContents = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->pluck('content')
        ->all();

    $disputeMessage = collect($allContents)->first(fn ($c) => str_contains($c, 'Dispute opened'));
    expect($disputeMessage)->not->toBeNull()
        ->and($disputeMessage)->toContain($creator->name);
});

// ─── ResolveDisputeAction branches ──────────────────────────────────────────

test('API "Drawn" confidence posts the draw-settlement system message', function () {
    [$creator, , , $match] = pendingMatch();
    mockGameApi()->forceDraw();

    app(OpenDisputeAction::class)->handle($creator, $match);

    $allContents = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->pluck('content')
        ->all();

    $drawMessage = collect($allContents)->first(fn ($c) => str_contains($c, 'draw'));
    expect($drawMessage)->not->toBeNull();
});

test('API "Unknown" confidence posts the "admin review" system message', function () {
    [$creator, , , $match] = pendingMatch();
    mockGameApi()->forceUnknown();

    app(OpenDisputeAction::class)->handle($creator, $match);

    $allContents = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->pluck('content')
        ->all();

    $manualReviewMessage = collect($allContents)->first(fn ($c) => str_contains($c, 'admin review'));
    expect($manualReviewMessage)->not->toBeNull();
});

// ─── ResolveMatchTimeoutAction → "Confirmation window expired." ─────────────

test('timeout resolution posts a "window expired" system message', function () {
    [$creator, , , $match] = pendingMatch();

    // Force a Won confirmation so the timeout path takes the honored-claim
    // branch rather than dispute. We only care about the timeout system
    // message here; the subsequent settle message is tested above.
    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Won);

    $deadline = now()->subHours(5);
    // `created_at` isn't in $fillable — forceFill it.
    $match->forceFill(['created_at' => now()->subHours(6)])->save();

    app(ResolveMatchTimeoutAction::class)->handle($match->id, $deadline);

    $allContents = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->pluck('content')
        ->all();

    $expiredMessage = collect($allContents)->first(fn ($c) => str_contains($c, 'expired'));
    expect($expiredMessage)->not->toBeNull();
});

// ─── Broadcast: system messages broadcast via the same MessageSent event ────

test('system messages broadcast on the private match channel', function () {
    [$creator, , , $match] = pendingMatch();

    Event::fake([MessageSent::class]);

    app(ConfirmOutcomeAction::class)->handle($creator, $match, MatchOutcome::Won);

    Event::assertDispatched(
        MessageSent::class,
        function (MessageSent $event) use ($match) {
            $payload = $event->broadcastWith();

            return $payload['match_id'] === $match->id
                && $payload['type'] === 'system'
                && $payload['user_id'] === null;
        },
    );
});
