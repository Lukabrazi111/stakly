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
use Illuminate\Support\Facades\Bus;

/**
 * Wiring test for `ConfirmOutcomeAction` → `AutoFetchLichessGameJob`.
 *
 * The job itself is covered in `AutoFetchLichessGameJobTest`. Here we
 * only exercise the dispatch decision: fires once on the zero→one confirm
 * transition, gated on both snapshotted Lichess usernames being present.
 */
/**
 * @param  list<array{side?: string, provider?: LinkedAccountProvider, username?: string}>|null  $snapshots
 *                                                                                                           Null = default both-sides Lichess. Empty array / one-sided array =
 *                                                                                                           test the snapshot-missing dispatch branches.
 */
function confirmDispatchMatch(?array $snapshots = null): array
{
    platformUser();

    $creator = User::factory()->withLichess('alice-lichess')->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $taker = User::factory()->withLichess('bob-lichess')->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    // `->forLichess()` so the listing's platform matches the default
    // Lichess snapshots. Dispatch chooses the job based on listing.platform
    // (Phase 5 Slice B); the chess.com variant has its own test below.
    $listing = Listing::factory()->taken()->forLichess()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );
    Wallet::hold(
        user: $taker,
        amount: '100',
        listing: $listing,
        reference: "match-take:{$listing->id}",
    );

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
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            ...$row,
        ]);
    }

    return [$creator, $taker, $match->fresh()];
}

// ─── Positive: first confirm fires the job ──────────────────────────────────

test('first confirm with both Lichess snapshots dispatches AutoFetchLichessGameJob', function () {
    [$creator, , $match] = confirmDispatchMatch();
    Bus::fake([AutoFetchLichessGameJob::class]);

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect();

    Bus::assertDispatched(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->is($match),
    );
});

test('first confirm on a chess.com listing dispatches AutoFetchChessComGameJob', function () {
    // Mirror of the Lichess dispatch test for the chess.com platform.
    // Reproduces the M8 Phase 5 Slice B dispatch routing: listing.platform
    // selects which auto-fetch job runs.
    platformUser();

    $creator = User::factory()->withChessCom('alice-chesscom')->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $taker = User::factory()->withChessCom('bob-chesscom')->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()->taken()->forChessCom()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
    Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

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

    Bus::fake([AutoFetchChessComGameJob::class, AutoFetchLichessGameJob::class]);

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect();

    Bus::assertDispatched(AutoFetchChessComGameJob::class);
    Bus::assertNotDispatched(AutoFetchLichessGameJob::class);
});

// ─── Negative: missing snapshots skip the dispatch ──────────────────────────

test('first confirm without creator Lichess snapshot does NOT dispatch', function () {
    [$creator, , $match] = confirmDispatchMatch(snapshots: [
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'bob-lichess'],
    ]);
    Bus::fake([AutoFetchLichessGameJob::class]);

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect();

    Bus::assertNotDispatched(AutoFetchLichessGameJob::class);
});

test('first confirm without taker Lichess snapshot does NOT dispatch', function () {
    [$creator, , $match] = confirmDispatchMatch(snapshots: [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'alice-lichess'],
    ]);
    Bus::fake([AutoFetchLichessGameJob::class]);

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect();

    Bus::assertNotDispatched(AutoFetchLichessGameJob::class);
});

// ─── Negative: second confirm does NOT fire (only zero→one transition) ─────

test('second confirm does NOT dispatch — only the first-confirm transition fires', function () {
    [$creator, $taker, $match] = confirmDispatchMatch();

    // Taker confirms first — outside Bus::fake so the dispatch from this
    // call is real (and irrelevant to this test).
    $this->actingAs($taker)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'lost'])
        ->assertRedirect();

    // Now creator confirms — this is the SECOND confirm, must NOT dispatch.
    Bus::fake([AutoFetchLichessGameJob::class]);

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect();

    Bus::assertNotDispatched(AutoFetchLichessGameJob::class);
});

test('re-confirming the same outcome (no-change) does NOT dispatch', function () {
    [$creator, , $match] = confirmDispatchMatch();

    // First confirm — real dispatch, not under Bus::fake.
    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect();

    // Re-submit identical outcome — should hit the 'no-change' branch.
    Bus::fake([AutoFetchLichessGameJob::class]);

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect();

    Bus::assertNotDispatched(AutoFetchLichessGameJob::class);
});

test('changing outcome on a one-confirm match does NOT dispatch (still second-state)', function () {
    [$creator, , $match] = confirmDispatchMatch();

    // First confirm — outside Bus::fake.
    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'won'])
        ->assertRedirect();

    // Same user changes their confirm — column transitions from "Won" to
    // "Lost" but the match is no longer in zero-confirms state.
    Bus::fake([AutoFetchLichessGameJob::class]);

    $this->actingAs($creator)
        ->postJson(route('matches.confirm', $match), ['outcome' => 'lost'])
        ->assertRedirect();

    Bus::assertNotDispatched(AutoFetchLichessGameJob::class);
});
