<?php

use App\Enums\GameApiConfidence;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\GameApi\ChessGameApi;
use App\Services\GameApi\MockGameApi;

/**
 * Real-data dispute arbitration. The driver reads the auto-fetched card
 * from the match chat and returns the named winner; falls through to
 * `MockGameApi` for everything else (no card, paste-only card, card with
 * an unmappable winner).
 *
 * `MockGameApi` is the same singleton instance the test calls
 * `forceWinner` on via `mockGameApi()`, so fall-through paths are
 * deterministic.
 */
function chessGameApi(): ChessGameApi
{
    // Resolve via the container so the wrapped `MockGameApi` singleton is
    // the same instance the test's `mockGameApi()` helper returns.
    return new ChessGameApi(app(MockGameApi::class));
}

function disputeMatch(?array $snapshots = null): array
{
    $creator = User::factory()->withLichess('alice-lichess')->create();
    $taker = User::factory()->withLichess('bob-lichess')->create();

    $listing = Listing::factory()->taken()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    $snapshots ??= [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'alice-lichess'],
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'bob-lichess'],
    ];

    foreach ($snapshots as $row) {
        MatchProviderSnapshot::create(['match_id' => $match->id, ...$row]);
    }

    return [$creator, $taker, $match->fresh(['listing.user', 'taker', 'providerSnapshots'])];
}

/**
 * Insert an auto-fetched system card directly. Bypasses
 * `AutoFetchLichessGameJob` so tests can pin the exact card contents
 * without orchestrating Lichess Http::fake state.
 *
 * @param  array<string, mixed>  $overrides
 */
function postAutoFetchCard(GameMatch $match, string $winnerUsername, array $overrides = []): Message
{
    return Message::create([
        'match_id' => $match->id,
        'user_id' => null,
        'type' => MessageType::System,
        'content' => 'Verified Lichess game record.',
        'attachments_json' => [array_merge([
            'type' => 'game_card',
            'provider' => 'lichess',
            'source' => 'auto_fetch',
            'game_id' => 'abcdefgh',
            'url' => 'https://lichess.org/abcdefgh',
            'verified' => true,
            'white_username' => 'alice-lichess',
            'black_username' => 'bob-lichess',
            'winner_color' => 'white',
            'winner_username' => $winnerUsername,
            'status' => 'mate',
            'speed' => 'blitz',
            'variant' => 'standard',
            'rated' => true,
            'played_at' => '2026-05-22T18:00:00+00:00',
        ], $overrides)],
    ]);
}

// ─── Card-driven arbitration ────────────────────────────────────────────────

test('auto-fetched card with creator winner returns creator user id', function () {
    [$creator, , $match] = disputeMatch();
    postAutoFetchCard($match, 'alice-lichess');

    $result = chessGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($creator->id)
        ->and($result->confidence)->toBe(GameApiConfidence::Confirmed)
        ->and($result->raw_response['driver'])->toBe('chess')
        ->and($result->raw_response['mode'])->toBe('auto_fetched_card')
        ->and($result->raw_response['card']['winner_username'])->toBe('alice-lichess');
});

test('auto-fetched card with taker winner returns taker user id', function () {
    [, $taker, $match] = disputeMatch();
    postAutoFetchCard($match, 'bob-lichess');

    $result = chessGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($taker->id)
        ->and($result->confidence)->toBe(GameApiConfidence::Confirmed);
});

test('winner-username match is case-insensitive', function () {
    [$creator, , $match] = disputeMatch();
    // Card surfaces Lichess's case-preserved display form; snapshot is
    // lowercase. Both should still resolve to creator.
    postAutoFetchCard($match, 'Alice-Lichess');

    $result = chessGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($creator->id);
});

// ─── Fallback to mock when card is absent / wrong shape ─────────────────────

test('no card present → falls through to MockGameApi (forced winner honoured)', function () {
    [$creator, , $match] = disputeMatch();
    mockGameApi()->forceWinner($creator->id);

    $result = chessGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($creator->id)
        ->and($result->raw_response['driver'])->toBe('mock');
});

test('paste-source card (no auto_fetch) is ignored → falls through to mock', function () {
    [, $taker, $match] = disputeMatch();
    mockGameApi()->forceWinner($taker->id);

    // Manually paste a card on a user (non-system) message. The driver must
    // ignore paste-source cards and use the mock fallback.
    Message::create([
        'match_id' => $match->id,
        'user_id' => $taker->id,
        'type' => MessageType::Text,
        'content' => 'see https://lichess.org/abcdefgh',
        'attachments_json' => [[
            'type' => 'game_card',
            'provider' => 'lichess',
            'source' => 'paste',
            'game_id' => 'abcdefgh',
            'verified' => true,
            'winner_username' => 'alice-lichess',
        ]],
    ]);

    $result = chessGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($taker->id)
        ->and($result->raw_response['driver'])->toBe('mock');
});

test('card with unmappable winner_username → falls through to mock', function () {
    [$creator, , $match] = disputeMatch();
    mockGameApi()->forceWinner($creator->id);

    // Card names a Lichess user that isn't snapshotted on this match.
    // Defensive — auto-fetch validates snapshot, but a snapshot mutated
    // post-card-post (or a corrupt card) shouldn't crash arbitration.
    postAutoFetchCard($match, 'stranger-lichess');

    $result = chessGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($creator->id)
        ->and($result->raw_response['driver'])->toBe('mock');
});

test('match without snapshots → falls through to mock even if card present', function () {
    [, $taker, $match] = disputeMatch(snapshots: []);
    mockGameApi()->forceWinner($taker->id);

    // Card written but no snapshot rows to map against — winner is
    // unresolvable, mock takes over.
    postAutoFetchCard($match, 'alice-lichess');

    $result = chessGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($taker->id)
        ->and($result->raw_response['driver'])->toBe('mock');
});

// ─── Card-source filtering ─────────────────────────────────────────────────

test('auto-fetched chess.com card resolves winner via the chess.com snapshot', function () {
    // Reproduces the cross-provider arbitration path: a chess.com auto-card
    // exists, ChessGameApi must read the chess.com snapshot (not Lichess) to
    // map winner_username → user_id. Players are snapshotted on chess.com
    // only — Lichess snapshot is empty.
    $creator = User::factory()->withChessCom('alice-chesscom')->create();
    $taker = User::factory()->withChessCom('bob-chesscom')->create();

    $listing = Listing::factory()->taken()->forChessCom()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_CREATOR,
        'provider' => LinkedAccountProvider::ChessCom,
        'username' => 'alice-chesscom',
    ]);
    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_TAKER,
        'provider' => LinkedAccountProvider::ChessCom,
        'username' => 'bob-chesscom',
    ]);

    Message::create([
        'match_id' => $match->id,
        'user_id' => null,
        'type' => MessageType::System,
        'content' => 'Verified chess.com game record.',
        'attachments_json' => [[
            'type' => 'game_card',
            'provider' => 'chess_com',
            'source' => 'auto_fetch',
            'game_id' => '55555555555',
            'verified' => true,
            'winner_username' => 'bob-chesscom',
            'status' => 'resigned',
        ]],
    ]);

    $result = chessGameApi()->getMatchResult($match->fresh(['listing', 'providerSnapshots']));

    expect($result->winner_user_id)->toBe($taker->id)
        ->and($result->confidence)->toBe(GameApiConfidence::Confirmed)
        ->and($result->raw_response['card']['provider'])->toBe('chess_com');
});

test('multiple auto-fetched cards → uses the most recent one', function () {
    [, $taker, $match] = disputeMatch();

    // First auto-fetch posted alice as winner (older).
    postAutoFetchCard($match, 'alice-lichess');
    // Second auto-fetch updated to bob (newer — auto-fetch idempotency
    // should normally prevent this, but defending against the case).
    postAutoFetchCard($match, 'bob-lichess');

    $result = chessGameApi()->getMatchResult($match);

    expect($result->winner_user_id)->toBe($taker->id);
});
