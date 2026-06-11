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

test('flag on but game has no arbitration driver (Dota2): dispute stays Disputed, no API call', function () {
    // M15 P5 — the gate is now `Game::hasArbitrationDriver()`. Dota2 has
    // no `GameApi` adapter wired into the production chain yet, so
    // disputes route to slow-path admin review. Once a Dota2 adapter
    // ships (M15 follow-on or M-future), this test should flip to
    // confirm the fast-path now fires.
    [$creator, , , $match] = pendingMatch();
    $match->listing->update(['game' => Game::Dota2]);
    config(['stakly.dispute_fast_path_enabled' => true]);

    app(OpenDisputeAction::class)->handle($creator, $match->fresh(), 'opponent cheated');

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Disputed)
        ->and($fresh->settled_at)->toBeNull()
        ->and($fresh->api_resolved_at)->toBeNull();
});

test('flag on: CS2 dispute is now eligible for fast-path (M15 P5 capability gate)', function () {
    // Before M15 P5 this would have been blocked by the hardcoded
    // `Game::Chess` check. After P5 the gate is capability-based and
    // CS2 has `FaceitGameApi` in the production chain. With no FACEIT
    // card on the match the composition falls through to `MockGameApi`,
    // which a forced winner resolves to Confirmed → match Settled.
    [$creator, , , $match] = pendingMatch();
    $match->listing->update(['game' => Game::Cs2]);
    config(['stakly.dispute_fast_path_enabled' => true]);
    mockGameApi()->forceWinner($creator->id);

    app(OpenDisputeAction::class)->handle($creator, $match->fresh(), 'opponent cheated');

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Settled)
        ->and($fresh->winner_user_id)->toBe($creator->id)
        ->and($fresh->api_resolved_at)->not->toBeNull();
});
