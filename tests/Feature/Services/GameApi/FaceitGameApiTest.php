<?php

use App\Enums\Game;
use App\Enums\GameApiConfidence;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Services\GameApi\FaceitGameApi;
use App\Services\GameApi\MockGameApi;

function faceitGameApi(): FaceitGameApi
{
    // Wrap the same `MockGameApi` singleton tests force winners on via the
    // global `mockGameApi()` helper, so fall-through paths are deterministic.
    return new FaceitGameApi(app(MockGameApi::class));
}

/**
 * Build a Pending CS2 match for arbitration tests. No FACEIT snapshots
 * needed — `FaceitGameApi` reads `winner_user_id` directly off the card
 * (no snapshot cross-check at arbitration time).
 *
 * @return array{0: User, 1: User, 2: GameMatch}
 */
function faceitDisputeMatch(): array
{
    $creator = User::factory()->withFaceit('alice-faceit')->create();
    $taker = User::factory()->withFaceit('bob-faceit')->create();

    $listing = Listing::factory()->taken()->forGame(Game::Cs2)->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    return [$creator, $taker, $match->fresh(['listing.user', 'taker'])];
}

/**
 * Drop a FACEIT auto-fetch card directly on the match chat. Bypasses the
 * job so tests can pin the exact card contents without `Http::fake` setup.
 *
 * @param  array<string, mixed>  $overrides
 */
function postFaceitAutoFetchCard(GameMatch $match, ?int $winnerUserId, array $overrides = []): Message
{
    return Message::create([
        'match_id' => $match->id,
        'user_id' => null,
        'type' => MessageType::System,
        'content' => 'Verified FACEIT match record.',
        'attachments_json' => [array_merge([
            'type' => 'game_card',
            'provider' => 'faceit',
            'source' => 'auto_fetch',
            'match_id' => '1-abcd-1234',
            'url' => 'https://www.faceit.com/en/cs2/room/1-abcd-1234',
            'verified' => true,
            'game' => 'cs2',
            'competition_type' => 'matchmaking',
            'status' => 'FINISHED',
            'winner_faction' => 'faction1',
            'winner_username' => 'alice-faceit',
            'winner_user_id' => $winnerUserId,
            'ac_complete' => true,
            'started_at' => '2026-06-09T12:00:00+00:00',
            'finished_at' => '2026-06-09T12:33:00+00:00',
        ], $overrides)],
    ]);
}

// ─── Happy path — auto-fetched card present ────────────────────────────────

test('card with winner_user_id = creator returns Confirmed result for creator', function () {
    [$creator, , $match] = faceitDisputeMatch();
    postFaceitAutoFetchCard($match, winnerUserId: $creator->id);

    $result = faceitGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($creator->id)
        ->and($result->confidence)->toBe(GameApiConfidence::Confirmed)
        ->and($result->raw_response['driver'])->toBe('faceit')
        ->and($result->raw_response['mode'])->toBe('auto_fetched_card')
        ->and($result->raw_response['card']['provider'])->toBe('faceit');
});

test('card with winner_user_id = taker returns Confirmed result for taker', function () {
    [, $taker, $match] = faceitDisputeMatch();
    postFaceitAutoFetchCard(
        $match,
        winnerUserId: $taker->id,
        overrides: ['winner_faction' => 'faction2', 'winner_username' => 'bob-faceit'],
    );

    $result = faceitGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($taker->id)
        ->and($result->confidence)->toBe(GameApiConfidence::Confirmed);
});

// ─── Fallback paths ────────────────────────────────────────────────────────

test('no auto-fetch card on the match → falls through to MockGameApi', function () {
    [$creator, , $match] = faceitDisputeMatch();

    // Force the mock to a known winner so we can verify fall-through happened.
    mockGameApi()->forceWinner($creator->id);

    $result = faceitGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($creator->id);
});

test('card with winner_user_id = null (draw) → falls through to MockGameApi', function () {
    [$creator, , $match] = faceitDisputeMatch();
    postFaceitAutoFetchCard(
        $match,
        winnerUserId: null,
        overrides: ['winner_faction' => null, 'winner_username' => null],
    );

    mockGameApi()->forceWinner($creator->id);

    $result = faceitGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($creator->id);
});

test('card with winner_user_id pointing at a stranger → falls through to MockGameApi (defensive)', function () {
    [$creator, , $match] = faceitDisputeMatch();
    $stranger = User::factory()->create();

    // Forged card naming a foreign winner. The arbitrator refuses to settle
    // anyone who isn't a match participant.
    postFaceitAutoFetchCard($match, winnerUserId: $stranger->id);

    mockGameApi()->forceWinner($creator->id);

    $result = faceitGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($creator->id);
});

// ─── Discriminator — only reads FACEIT cards ───────────────────────────────

test('chess card on the same match → falls through (FaceitGameApi reads only faceit cards)', function () {
    [$creator, , $match] = faceitDisputeMatch();

    // Stray chess card on a CS2 match shouldn't happen in production but is
    // defensive coverage — FaceitGameApi must ignore it.
    Message::create([
        'match_id' => $match->id,
        'user_id' => null,
        'type' => MessageType::System,
        'content' => 'Verified chess.com game record.',
        'attachments_json' => [[
            'type' => 'game_card',
            'provider' => 'chess_com',
            'source' => 'auto_fetch',
            'winner_username' => 'alice-chesscom',
        ]],
    ]);

    mockGameApi()->forceWinner($creator->id);

    $result = faceitGameApi()->getMatchResult($match);

    // Fell through to mock, which is keyed to the creator.
    expect($result->winner_user_id)->toBe($creator->id);
});

test('latest FACEIT card wins when multiple cards exist on the same match', function () {
    [$creator, $taker, $match] = faceitDisputeMatch();

    // Earlier card naming the creator; later card naming the taker. The
    // arbitrator picks the latest by id (the chat is append-only so id
    // ordering matches chronology).
    postFaceitAutoFetchCard($match, winnerUserId: $creator->id);
    postFaceitAutoFetchCard(
        $match,
        winnerUserId: $taker->id,
        overrides: ['winner_faction' => 'faction2', 'winner_username' => 'bob-faceit', 'match_id' => '1-second'],
    );

    $result = faceitGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($taker->id);
});
