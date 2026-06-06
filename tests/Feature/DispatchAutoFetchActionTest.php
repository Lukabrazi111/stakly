<?php

use App\Actions\GameMatch\DispatchAutoFetchAction;
use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Jobs\AutoFetchChessComGameJob;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchAutoFetchAttempt;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Cache::flush();
});

/**
 * Returns a Pending match with snapshots populated for the given platform on
 * both sides. The default `pendingMatch()` helper from Pest.php doesn't seed
 * snapshots, which is what `DispatchAutoFetchAction` needs to actually dispatch.
 *
 * @return array{0: User, 1: User, 2: Listing, 3: GameMatch}
 */
function pendingMatchWithSnapshots(LinkedAccountProvider $platform = LinkedAccountProvider::Lichess): array
{
    [$creator, $taker, $listing, $match] = pendingMatch();

    $listing->update(['platform' => $platform]);

    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_CREATOR,
        'provider' => $platform,
        'username' => 'alice-handle',
    ]);

    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_TAKER,
        'provider' => $platform,
        'username' => 'bob-handle',
    ]);

    return [$creator, $taker, $listing->fresh(), $match->fresh()];
}

test('dispatches the Lichess job for a Pending Lichess match with full snapshots', function () {
    [, , , $match] = pendingMatchWithSnapshots(LinkedAccountProvider::Lichess);

    app(DispatchAutoFetchAction::class)->handle($match);

    Queue::assertPushed(AutoFetchLichessGameJob::class, fn ($job) => $job->match->is($match));
    Queue::assertNotPushed(AutoFetchChessComGameJob::class);
    expect(MatchAutoFetchAttempt::count())->toBe(0);
});

test('dispatches the chess.com job for a Pending chess.com match with full snapshots', function () {
    [, , , $match] = pendingMatchWithSnapshots(LinkedAccountProvider::ChessCom);

    app(DispatchAutoFetchAction::class)->handle($match);

    Queue::assertPushed(AutoFetchChessComGameJob::class, fn ($job) => $job->match->is($match));
    Queue::assertNotPushed(AutoFetchLichessGameJob::class);
    expect(MatchAutoFetchAttempt::count())->toBe(0);
});

test('records a skip with not_pending reason when match is not Pending', function () {
    [, , , $match] = pendingMatchWithSnapshots(LinkedAccountProvider::Lichess);
    $match->update(['status' => MatchStatus::Settled]);

    app(DispatchAutoFetchAction::class)->handle($match->fresh());

    Queue::assertNothingPushed();
    expect(MatchAutoFetchAttempt::count())->toBe(1);

    $attempt = MatchAutoFetchAttempt::first();
    expect($attempt->match_id)->toBe($match->id)
        ->and($attempt->provider)->toBe(LinkedAccountProvider::Lichess)
        ->and($attempt->outcome)->toBe(AutoFetchOutcome::Skipped)
        ->and($attempt->outcome_reason)->toBe('not_pending')
        ->and($attempt->latency_ms)->toBeNull()
        ->and($attempt->candidates_count)->toBeNull();
});

test('records a skip with snapshot_missing reason when no snapshots exist', function () {
    // pendingMatch() returns a Pending match with no snapshots seeded.
    [, , , $match] = pendingMatch();

    app(DispatchAutoFetchAction::class)->handle($match);

    Queue::assertNothingPushed();
    expect(MatchAutoFetchAttempt::count())->toBe(1);

    $attempt = MatchAutoFetchAttempt::first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Skipped)
        ->and($attempt->outcome_reason)->toBe('snapshot_missing');
});

test('records snapshot_missing when only one side has a snapshot', function () {
    [, , , $match] = pendingMatch();
    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_CREATOR,
        'provider' => LinkedAccountProvider::Lichess,
        'username' => 'alice-handle',
    ]);

    app(DispatchAutoFetchAction::class)->handle($match->fresh());

    Queue::assertNothingPushed();
    expect(MatchAutoFetchAttempt::where('outcome_reason', 'snapshot_missing')->count())->toBe(1);
});

test('skips with circuit_open reason when the provider breaker has tripped', function () {
    [, , , $match] = pendingMatchWithSnapshots(LinkedAccountProvider::Lichess);

    // Trip the breaker by recording 5 failures.
    $breaker = app(ProviderCircuitBreaker::class);
    foreach (range(1, 5) as $_) {
        $breaker->recordFailure(LinkedAccountProvider::Lichess);
    }
    expect($breaker->isOpen(LinkedAccountProvider::Lichess))->toBeTrue();

    app(DispatchAutoFetchAction::class)->handle($match);

    Queue::assertNothingPushed();

    $attempt = MatchAutoFetchAttempt::query()
        ->where('match_id', $match->id)
        ->first();
    expect($attempt)->not->toBeNull()
        ->and($attempt->outcome)->toBe(AutoFetchOutcome::Skipped)
        ->and($attempt->outcome_reason)->toBe('circuit_open')
        ->and($attempt->provider)->toBe(LinkedAccountProvider::Lichess);
});

test('circuit_open is checked per-provider — chess.com dispatches while Lichess is open', function () {
    [, , , $match] = pendingMatchWithSnapshots(LinkedAccountProvider::ChessCom);

    $breaker = app(ProviderCircuitBreaker::class);
    foreach (range(1, 5) as $_) {
        $breaker->recordFailure(LinkedAccountProvider::Lichess);
    }

    app(DispatchAutoFetchAction::class)->handle($match);

    Queue::assertPushed(AutoFetchChessComGameJob::class);
});

test('not_pending skip records the provider from the listing', function () {
    [, , $listing, $match] = pendingMatchWithSnapshots(LinkedAccountProvider::ChessCom);
    $match->update(['status' => MatchStatus::Cancelled]);

    app(DispatchAutoFetchAction::class)->handle($match->fresh());

    $attempt = MatchAutoFetchAttempt::first();
    expect($attempt->provider)->toBe(LinkedAccountProvider::ChessCom);
});
