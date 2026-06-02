<?php

use App\Actions\GameMatch\OpenDisputeAction;
use App\Actions\GameMatch\ResolveMatchTimeoutAction;
use App\Actions\GameMatch\SettleDrawMatchAction;
use App\Actions\GameMatch\SettleMatchAction;
use App\Actions\GameMatch\TakeListingAction;
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
 * journey: started, settled (winner / draw), dispute opened, API resolved
 * (3 branches), timeout → ManualReview. Each message is created with
 * `user_id = null` + `type = system` and broadcasts via the same
 * `MessageSent` event as user messages, so subscribed Echo clients render
 * them inline.
 *
 * M16 removed the player Won/Lost/Drawn confirm flow — `ConfirmOutcomeAction`
 * and its system messages ("X confirmed: Won.", auto-dispute narration,
 * etc.) are gone. Settlement system messages now fire from the same
 * `SettleMatchAction` / `SettleDrawMatchAction` whether triggered by the
 * auto-fetch card path (`SettleFromCardAction`) or the dispute path
 * (`ResolveDisputeAction`).
 */

/**
 * Helper: latest system message content for a match.
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
    $creator = User::factory()->active()->withLichess()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:c:{$creator->id}");

    $listing = Listing::factory()->open()->forLichess()->for($creator)->state(['stake_amount' => '100'])->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");

    $taker = User::factory()->withLichess()->create();
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

// ─── SettleMatchAction → "Match settled. {winner} wins $X USDT." ────────────

test('settling a match posts a "Match settled" system message naming the winner', function () {
    [$creator, , , $match] = pendingMatch();

    app(SettleMatchAction::class)->handle($match, $creator);

    $latest = latestSystemMessage($match);
    expect($latest)->toContain('Match settled')
        ->and($latest)->toContain($creator->name);
});

test('settlement message includes the payout amount', function () {
    [$creator, , , $match] = pendingMatch(stake: '100');

    app(SettleMatchAction::class)->handle($match, $creator);

    // Pot = 200, fee = 10% = 20, payout = 180.
    expect(latestSystemMessage($match))->toContain('180.00');
});

// ─── SettleDrawMatchAction → "Match ended as a draw. Stakes refunded." ──────

test('settling as draw posts a "Match ended as a draw" system message', function () {
    [, , , $match] = pendingMatch();

    app(SettleDrawMatchAction::class)->handle($match);

    expect(latestSystemMessage($match))->toContain('draw')
        ->and(latestSystemMessage($match))->toContain('refunded');
});

// ─── OpenDisputeAction → "Dispute opened by {name}." ────────────────────────

test('opening a dispute posts a "Dispute opened by {name}" system message with dispute_prompt marker', function () {
    // M12 Phase 3 — OpenDisputeAction no longer dispatches game-API
    // resolution. It posts ONE system message: the dispute narration +
    // evidence call-to-action, carrying the `dispute_prompt` attachment
    // marker so the React `SystemBubble` renders the warning variant.
    [$creator, , , $match] = pendingMatch();

    app(OpenDisputeAction::class)->handle($creator, $match, 'opponent claims they won but the game shows me winning');

    $message = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->whereJsonContains('attachments_json', [['type' => 'dispute_prompt']])
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->content)->toContain('Dispute opened')
        ->and($message->content)->toContain($creator->name)
        ->and($message->content)->toContain('admin will review')
        ->and($message->attachments_json)->toBe([['type' => 'dispute_prompt']]);
});

// ─── ResolveMatchTimeoutAction → "expired without API-verified game record" ─

test('timeout resolution posts an "expired" narration + a dispute_prompt evidence message', function () {
    [, , , $match] = pendingMatch();

    $deadline = now()->subHours(5);
    // `created_at` isn't in $fillable — forceFill it past the deadline.
    $match->forceFill(['created_at' => now()->subHours(6)])->save();

    app(ResolveMatchTimeoutAction::class)->handle($match->id, $deadline);

    $allContents = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->pluck('content')
        ->all();

    $expiredMessage = collect($allContents)->first(fn ($c) => str_contains($c, 'expired'));
    expect($expiredMessage)->not->toBeNull()
        ->and($expiredMessage)->toContain('admin review');

    // The second message carries the dispute_prompt attachment marker so
    // the React `SystemBubble` renders the warning-toned evidence variant
    // — same shape as `ResolveDisputeAction::flipToManualReview`.
    $promptMessage = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->whereJsonContains('attachments_json', [['type' => 'dispute_prompt']])
        ->first();

    expect($promptMessage)->not->toBeNull()
        ->and($promptMessage->content)->toContain('Submit evidence');
});

// ─── Broadcast: system messages broadcast via the same MessageSent event ────

test('system messages broadcast on the private match channel', function () {
    [$creator, , , $match] = pendingMatch();

    Event::fake([MessageSent::class]);

    app(SettleMatchAction::class)->handle($match, $creator);

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
