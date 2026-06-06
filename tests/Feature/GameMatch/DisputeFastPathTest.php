<?php

use App\Actions\GameMatch\OpenDisputeAction;
use App\Enums\Game;
use App\Enums\MatchStatus;

/**
 * M14 Slice 4a — `stakly.dispute_fast_path_enabled` flag-gated synchronous
 * arbitration. When false (default), `OpenDisputeAction` only flips status
 * to Disputed and waits for admin / cron. When true, `ResolveDisputeAction`
 * runs immediately after the flip — chess matches only.
 */
test('flag off (default): chess dispute opens → status stays Disputed, no API call', function () {
    [$creator, , , $match] = pendingMatch();
    // Sanity — flag should default false in test env.
    expect(config('stakly.dispute_fast_path_enabled'))->toBeFalse();

    app(OpenDisputeAction::class)->handle($creator, $match, 'opponent cheated');

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Disputed)
        ->and($fresh->settled_at)->toBeNull()
        ->and($fresh->api_resolved_at)->toBeNull();
});

test('flag on: chess dispute with confirmed winner → match Settled, payout to winner', function () {
    [$creator, , , $match] = pendingMatch();
    config(['stakly.dispute_fast_path_enabled' => true]);
    mockGameApi()->forceWinner($creator->id);

    app(OpenDisputeAction::class)->handle($creator, $match, 'opponent cheated');

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->api_resolved_at)->not->toBeNull();
});

test('flag on: chess dispute with drawn API result → match Settled, no winner', function () {
    [$creator, , , $match] = pendingMatch();
    config(['stakly.dispute_fast_path_enabled' => true]);
    mockGameApi()->forceDraw();

    app(OpenDisputeAction::class)->handle($creator, $match, 'we agreed to draw');

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->api_resolved_at)->not->toBeNull();
});

test('flag on: chess dispute with unknown API result → match flips to ManualReview', function () {
    [$creator, , , $match] = pendingMatch();
    config(['stakly.dispute_fast_path_enabled' => true]);
    mockGameApi()->forceUnknown();

    app(OpenDisputeAction::class)->handle($creator, $match, 'opponent cheated');

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::ManualReview)
        ->and($fresh->winner_user_id)->toBeNull()
        ->and($fresh->settled_at)->toBeNull();
});

test('flag on but non-chess match: dispute opens → status stays Disputed (chess gate)', function () {
    [$creator, , , $match] = pendingMatch();
    $match->listing->update(['game' => Game::Cs2]);
    config(['stakly.dispute_fast_path_enabled' => true]);

    app(OpenDisputeAction::class)->handle($creator, $match->fresh(), 'opponent cheated');

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Disputed)
        ->and($fresh->settled_at)->toBeNull()
        ->and($fresh->api_resolved_at)->toBeNull();
});
