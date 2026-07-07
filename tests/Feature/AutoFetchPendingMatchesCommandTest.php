<?php

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Jobs\AutoFetchChessComGameJob;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * M16 Phase 2 — `stakly:auto-fetch-pending` cron scans Pending matches in
 * the [10min, 4h] window and dispatches the per-platform auto-fetch job
 * for each. Idempotency lives in `DispatchAutoFetchAction` (snapshot +
 * status gates) and in the jobs themselves (`ShouldBeUnique` + `alreadyPosted`).
 */

/**
 * Build a Pending match aged $minutesOld minutes, with both sides linked
 * to $platform. The platform binding drives which AutoFetch job we expect.
 */
function pendingForCron(
    LinkedAccountProvider $platform = LinkedAccountProvider::Lichess,
    int $minutesOld = 30,
    bool $withSnapshots = true,
): GameMatch {
    platformUser();

    $linkMethod = $platform === LinkedAccountProvider::Lichess ? 'withLichess' : 'withChessCom';
    $listingState = $platform === LinkedAccountProvider::Lichess ? 'forLichess' : 'forChessCom';

    $creator = User::factory()->active()->{$linkMethod}('alice-handle')->create();
    $taker = User::factory()->{$linkMethod}('bob-handle')->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:c:{$creator->id}");
    Wallet::deposit($taker, '500', reference: "test:deposit:t:{$taker->id}");

    $listing = Listing::factory()->taken()->{$listingState}()->for($creator)->state(['stake_amount' => '100'])->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
    Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    DB::table('game_matches')
        ->where('id', $match->id)
        ->update(['created_at' => now()->subMinutes($minutesOld)]);

    if ($withSnapshots) {
        foreach ([GameMatch::SIDE_CREATOR => 'alice-handle', GameMatch::SIDE_TAKER => 'bob-handle'] as $side => $username) {
            MatchProviderSnapshot::create([
                'match_id' => $match->id,
                'side' => $side,
                'provider' => $platform,
                'username' => $username,
            ]);
        }
    }

    return $match->fresh();
}

// ─── Dispatch routing per platform ─────────────────────────────────────────

test('Lichess listing in the window dispatches AutoFetchLichessGameJob', function () {
    Queue::fake();

    $match = pendingForCron(platform: LinkedAccountProvider::Lichess, minutesOld: 30);

    $this->artisan('stakly:auto-fetch-pending')->assertSuccessful();

    Queue::assertPushed(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->id === $match->id,
    );
    Queue::assertNotPushed(AutoFetchChessComGameJob::class);
});

test('chess.com listing in the window dispatches AutoFetchChessComGameJob', function () {
    Queue::fake();

    $match = pendingForCron(platform: LinkedAccountProvider::ChessCom, minutesOld: 30);

    $this->artisan('stakly:auto-fetch-pending')->assertSuccessful();

    Queue::assertPushed(
        AutoFetchChessComGameJob::class,
        fn (AutoFetchChessComGameJob $job) => $job->match->id === $match->id,
    );
    Queue::assertNotPushed(AutoFetchLichessGameJob::class);
});

// ─── Window filtering ──────────────────────────────────────────────────────

test('match younger than 10 minutes is skipped (page-visit / chat-send covers fresh matches)', function () {
    Queue::fake();

    pendingForCron(minutesOld: 5);

    $this->artisan('stakly:auto-fetch-pending')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('match older than 4h is skipped (timeout cron owns that zone)', function () {
    Queue::fake();

    pendingForCron(minutesOld: 4 * 60 + 5);

    $this->artisan('stakly:auto-fetch-pending')->assertSuccessful();

    Queue::assertNothingPushed();
});

// ─── M46 P3 — young-match tier (parameterized age window) ──────────────────

test('young tier (--min-age-minutes=1 --max-age-minutes=10) dispatches a 3-min-old match the backstop skips', function () {
    Queue::fake();

    $match = pendingForCron(platform: LinkedAccountProvider::ChessCom, minutesOld: 3);

    // Default backstop ignores it (younger than 10min)...
    $this->artisan('stakly:auto-fetch-pending')->assertSuccessful();
    Queue::assertNothingPushed();

    // ...but the young tier picks it up.
    $this->artisan('stakly:auto-fetch-pending --min-age-minutes=1 --max-age-minutes=10')
        ->assertSuccessful();

    Queue::assertPushed(
        AutoFetchChessComGameJob::class,
        fn (AutoFetchChessComGameJob $job) => $job->match->id === $match->id,
    );
});

test('young tier skips a 30-min-old match (outside its max-age=10 window — the backstop owns that zone)', function () {
    Queue::fake();

    pendingForCron(platform: LinkedAccountProvider::ChessCom, minutesOld: 30);

    $this->artisan('stakly:auto-fetch-pending --min-age-minutes=1 --max-age-minutes=10')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('young tier skips a match younger than its min-age=1 (initial trigger owns fresh matches)', function () {
    Queue::fake();

    pendingForCron(platform: LinkedAccountProvider::ChessCom, minutesOld: 0);

    $this->artisan('stakly:auto-fetch-pending --min-age-minutes=1 --max-age-minutes=10')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('young tier is provider-agnostic — a young Lichess match is dispatched too (stream-outage backstop)', function () {
    Queue::fake();

    $match = pendingForCron(platform: LinkedAccountProvider::Lichess, minutesOld: 3);

    $this->artisan('stakly:auto-fetch-pending --min-age-minutes=1 --max-age-minutes=10')
        ->assertSuccessful();

    Queue::assertPushed(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->id === $match->id,
    );
});

// ─── Status filtering ──────────────────────────────────────────────────────

test('Settled match in the window is skipped', function () {
    Queue::fake();

    $match = pendingForCron(minutesOld: 30);
    $match->update(['status' => MatchStatus::Settled, 'settled_at' => now()]);

    $this->artisan('stakly:auto-fetch-pending')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('Disputed match in the window is skipped', function () {
    Queue::fake();

    $match = pendingForCron(minutesOld: 30);
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    $this->artisan('stakly:auto-fetch-pending')->assertSuccessful();

    Queue::assertNothingPushed();
});

// ─── Snapshot guards (delegated to DispatchAutoFetchAction) ────────────────

test('match without snapshots is queried but the action no-ops (no dispatch)', function () {
    Queue::fake();

    pendingForCron(minutesOld: 30, withSnapshots: false);

    $this->artisan('stakly:auto-fetch-pending')->assertSuccessful();

    Queue::assertNothingPushed();
});

// ─── Batch ──────────────────────────────────────────────────────────────────

test('processes multiple matches in one run', function () {
    Queue::fake();

    $lichess = pendingForCron(platform: LinkedAccountProvider::Lichess, minutesOld: 30);
    $chessCom = pendingForCron(platform: LinkedAccountProvider::ChessCom, minutesOld: 30);

    $this->artisan('stakly:auto-fetch-pending')->assertSuccessful();

    Queue::assertPushed(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->id === $lichess->id,
    );
    Queue::assertPushed(
        AutoFetchChessComGameJob::class,
        fn (AutoFetchChessComGameJob $job) => $job->match->id === $chessCom->id,
    );
});
