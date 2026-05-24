<?php

use App\Actions\GameMatch\SettleFromCardAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\MatchProviderSnapshot;
use App\Models\WalletTransaction;

/**
 * Direct coverage of `SettleFromCardAction` (M16). The auto-fetch job
 * tests cover the integration (card-then-settle in one job run); this
 * file covers the action in isolation — status guards, draw vs winner
 * branching, unmappable-winner defense, idempotency.
 */

/**
 * Build a Pending match with both sides' Lichess snapshots populated +
 * stakes escrowed. Mirrors the production state when `AutoFetchLichessGameJob`
 * is about to call SettleFromCardAction. Returns the match alongside
 * the creator + taker so tests can assert payout direction.
 */
function settleFromCardMatch(string $stake = '100'): array
{
    [$creator, $taker, $listing, $match] = pendingMatch(stake: $stake);

    foreach ([
        ['side' => GameMatch::SIDE_CREATOR, 'username' => 'alice-lichess'],
        ['side' => GameMatch::SIDE_TAKER, 'username' => 'bob-lichess'],
    ] as $row) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            'provider' => LinkedAccountProvider::Lichess,
            ...$row,
        ]);
    }

    return [$creator, $taker, $listing, $match->fresh(['listing.user', 'taker', 'providerSnapshots'])];
}

function lichessCard(array $overrides = []): array
{
    return array_merge([
        'type' => 'game_card',
        'provider' => 'lichess',
        'source' => 'auto_fetch',
        'game_id' => 'abcdefgh',
        'url' => 'https://lichess.org/abcdefgh',
        'verified' => true,
        'white_username' => 'alice-lichess',
        'black_username' => 'bob-lichess',
        'winner_color' => 'white',
        'winner_username' => 'alice-lichess',
        'status' => 'mate',
        'speed' => 'blitz',
        'variant' => 'standard',
        'rated' => true,
        'played_at' => '2026-05-22T12:00:00+00:00',
    ], $overrides);
}

// ─── Happy path — winner card → SettleMatchAction ──────────────────────────

test('card with winner settles match to that user via SettleMatchAction', function () {
    [$creator, , , $match] = settleFromCardMatch(stake: '100');

    app(SettleFromCardAction::class)->handle($match, lichessCard());

    $fresh = $match->fresh();

    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->settled_at)->not->toBeNull();

    // Standard payout math: pot=200, fee=20, payout=180.
    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->exists())->toBeTrue()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeTrue();
});

test('card winner = bob-lichess settles to taker', function () {
    [, $taker, , $match] = settleFromCardMatch();

    app(SettleFromCardAction::class)->handle(
        $match,
        lichessCard(['winner_color' => 'black', 'winner_username' => 'bob-lichess']),
    );

    expect($match->fresh()->winner_user_id)->toBe($taker->id);
});

test('winner-username match is case-insensitive', function () {
    [$creator, , , $match] = settleFromCardMatch();

    // Snapshot says "alice-lichess"; card says "ALICE-LICHESS".
    app(SettleFromCardAction::class)->handle(
        $match,
        lichessCard(['winner_username' => 'ALICE-LICHESS']),
    );

    expect($match->fresh()->winner_user_id)->toBe($creator->id);
});

// ─── Draw card → SettleDrawMatchAction ─────────────────────────────────────

test('card with winner_color=null settles as draw via SettleDrawMatchAction', function () {
    [$creator, $taker, , $match] = settleFromCardMatch(stake: '100');

    app(SettleFromCardAction::class)->handle(
        $match,
        lichessCard([
            'winner_color' => null,
            'winner_username' => null,
            'status' => 'draw',
        ]),
    );

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBeNull();

    // Both refunded — no fee posted.
    expect(WalletTransaction::query()->where('reference_id', "match-draw-creator:{$match->id}")->exists())->toBeTrue()
        ->and(WalletTransaction::query()->where('reference_id', "match-draw-taker:{$match->id}")->exists())->toBeTrue()
        ->and(WalletTransaction::query()->where('reference_id', "match-fee:{$match->id}")->exists())->toBeFalse();

    // Each player's balance back to 500 (deposit 500 - hold 100 + release 100).
    expect((string) $creator->fresh()->usdt_balance)->toBe('500.000000');
    expect((string) $taker->fresh()->usdt_balance)->toBe('500.000000');
});

// ─── Status guards (no-op on terminal / non-Pending statuses) ──────────────

test('no-op on Settled match (idempotent re-call)', function () {
    [$creator, , , $match] = settleFromCardMatch();

    app(SettleFromCardAction::class)->handle($match, lichessCard());
    $firstRunBalance = $creator->fresh()->usdt_balance;

    // Re-invoke — should be a no-op (status === Settled).
    app(SettleFromCardAction::class)->handle($match->fresh(), lichessCard());

    expect((string) $creator->fresh()->usdt_balance)->toBe((string) $firstRunBalance);
    expect(WalletTransaction::query()->where('reference_id', "match-payout:{$match->id}")->count())->toBe(1);
});

test('no-op on Cancelled match', function () {
    [, , , $match] = settleFromCardMatch();
    $match->update(['status' => MatchStatus::Cancelled, 'cancelled_at' => now()]);

    app(SettleFromCardAction::class)->handle($match->fresh(), lichessCard());

    expect($match->fresh()->status)->toBe(MatchStatus::Cancelled)
        ->and($match->fresh()->winner_user_id)->toBeNull();
});

test('no-op on ManualReview match (admin owns the path)', function () {
    [, , , $match] = settleFromCardMatch();
    $match->update(['status' => MatchStatus::ManualReview]);

    app(SettleFromCardAction::class)->handle($match->fresh(), lichessCard());

    expect($match->fresh()->status)->toBe(MatchStatus::ManualReview)
        ->and($match->fresh()->winner_user_id)->toBeNull();
});

test('no-op on Disputed match (ResolveDisputeAction owns that path)', function () {
    [, , , $match] = settleFromCardMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    app(SettleFromCardAction::class)->handle($match->fresh(), lichessCard());

    expect($match->fresh()->status)->toBe(MatchStatus::Disputed)
        ->and($match->fresh()->winner_user_id)->toBeNull();
});

// ─── Unmappable winner — defensive log + no-op (no throw) ──────────────────

test('card winner not in snapshots → silent no-op, match stays Pending', function () {
    [, , , $match] = settleFromCardMatch();

    app(SettleFromCardAction::class)->handle(
        $match,
        lichessCard(['winner_username' => 'cheater-lichess']),
    );

    expect($match->fresh()->status)->toBe(MatchStatus::Pending)
        ->and($match->fresh()->winner_user_id)->toBeNull();
});

test('card with unknown provider → no-op', function () {
    [, , , $match] = settleFromCardMatch();

    app(SettleFromCardAction::class)->handle(
        $match,
        lichessCard(['provider' => 'faceit']),
    );

    expect($match->fresh()->status)->toBe(MatchStatus::Pending);
});

test('card with chess.com provider resolves against ChessCom snapshot, not Lichess', function () {
    [$creator, , , $match] = settleFromCardMatch();

    // Add chess.com snapshots alongside the existing Lichess ones.
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

    app(SettleFromCardAction::class)->handle(
        $match->fresh(['providerSnapshots']),
        lichessCard([
            'provider' => 'chess_com',
            'winner_username' => 'alice-chesscom',
        ]),
    );

    expect($match->fresh()->winner_user_id)->toBe($creator->id);
});
