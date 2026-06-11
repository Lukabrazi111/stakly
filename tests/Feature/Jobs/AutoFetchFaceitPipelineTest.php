<?php

use App\Actions\GameMatch\DispatchAutoFetchAction;
use App\Actions\GameMatch\SettleMatchAction;
use App\Actions\GameMatch\TakeListingAction;
use App\Enums\AutoFetchOutcome;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchAutoFetchAttempt;
use App\Models\Message;
use App\Models\User;
use App\Services\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * M15 P4 Slice 3 — end-to-end FACEIT outcome pipeline.
 *
 * Exercises the full path that goes live the moment `DispatchAutoFetchAction`
 * accepts FACEIT listings: a CS2 listing is created with the creator's stake
 * escrowed, the taker takes it (TakeListingAction holds the taker stake +
 * snapshots both linked accounts), DispatchAutoFetchAction queues the FACEIT
 * job, the sync queue runs it inline, the job calls the Data API (faked),
 * posts a card with `winner_user_id` resolved at job time, SettleFromCardAction
 * pays the winner, credits the platform fee, and flips the match to Settled.
 *
 * The per-layer tests (FaceitGameClientTest, AutoFetchFaceitGameJobTest,
 * FaceitGameApiTest, SettleFromCardActionTest, DispatchAutoFetchActionTest)
 * cover their layer's behavior. This test guards the seams between them and
 * the BCMath-precision of the resulting wallet ledger.
 */
beforeEach(function () {
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
    Http::preventStrayRequests();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('CS2 listing → take → dispatch → API poll → card → settle → wallet ledger (BCMath exact)', function () {
    $platform = platformUser();

    $creator = User::factory()->active()->withFaceit('alice-faceit', 'guid-a1')->create();
    $taker = User::factory()->withFaceit('bob-faceit', 'guid-b1')->create();

    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()
        ->forGame(Game::Cs2)
        ->for($creator)
        ->state([
            'stake_amount' => '100',
            'platform' => LinkedAccountProvider::Faceit,
            'status' => ListingStatus::Open,
        ])
        ->create();

    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    // Sanity-check the pre-take ledger: holds escrow funds away from the
    // free balance, so the creator should now show 400 (500 - 100).
    expect(Wallet::balanceFor($creator->fresh()))->toBe('400.000000');

    $match = app(TakeListingAction::class)->handle($taker, $listing->fresh());

    expect($match)->toBeInstanceOf(GameMatch::class)
        ->and($match->status)->toBe(MatchStatus::Pending)
        ->and(Wallet::balanceFor($taker->fresh()))->toBe('400.000000');

    // Backdate so the job's search-since window (match.created_at) sits
    // safely before the fixture's finished_at. Mirrors the production
    // race where the API write lags the match-create by a few seconds.
    $match->forceFill(['created_at' => CarbonImmutable::now()->subHour()])->save();
    $match = $match->fresh(['listing.user', 'taker', 'providerSnapshots']);

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response(
            faceitOpposingRosterFixture(creatorGuid: 'guid-a1', takerGuid: 'guid-b1'),
            200,
        ),
    ]);

    app(DispatchAutoFetchAction::class)->handle($match);

    $card = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->where('content', 'Verified FACEIT match record.')
        ->firstOrFail();

    expect($card->attachments_json)->toHaveCount(1);

    $payload = $card->attachments_json[0];
    expect($payload['provider'])->toBe('faceit')
        ->and($payload['source'])->toBe('auto_fetch')
        ->and($payload['verified'])->toBeTrue()
        ->and($payload['winner_faction'])->toBe('faction1')
        ->and($payload['winner_user_id'])->toBe($creator->id)
        ->and($payload['winner_username'])->toBe('alice-faceit')
        ->and($payload['ac_complete'])->toBeTrue();

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBe($creator->id);

    $attempt = MatchAutoFetchAttempt::query()
        ->where('match_id', $match->id)
        ->where('outcome', AutoFetchOutcome::Matched)
        ->firstOrFail();
    expect($attempt->provider)->toBe(LinkedAccountProvider::Faceit);

    // BCMath exact ledger:
    //   pot = 100 + 100 = 200
    //   fee = 200 * 0.10 = 20
    //   payout = 200 - 20 = 180
    //   creator (winner): 500 deposit - 100 hold + 180 payout = 580
    //   taker (loser):    500 deposit - 100 hold              = 400
    //   platform:                          0    + 20 fee      =  20
    $winnerPayout = SettleMatchAction::computeWinnerPayout('100');
    $fee = bcsub('200.000000', $winnerPayout, 6);

    expect($winnerPayout)->toBe('180.000000')
        ->and($fee)->toBe('20.000000')
        ->and(Wallet::balanceFor($creator->fresh()))->toBe('580.000000')
        ->and(Wallet::balanceFor($taker->fresh()))->toBe('400.000000')
        ->and(Wallet::balanceFor($platform->fresh()))->toBe('20.000000');
});

test('re-dispatching after settlement is a no-op (idempotent via not_pending skip)', function () {
    platformUser();

    $creator = User::factory()->active()->withFaceit('alice-faceit', 'guid-a1')->create();
    $taker = User::factory()->withFaceit('bob-faceit', 'guid-b1')->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()
        ->forGame(Game::Cs2)
        ->for($creator)
        ->state([
            'stake_amount' => '100',
            'platform' => LinkedAccountProvider::Faceit,
            'status' => ListingStatus::Open,
        ])
        ->create();

    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $match = app(TakeListingAction::class)->handle($taker, $listing->fresh());
    $match->forceFill(['created_at' => CarbonImmutable::now()->subHour()])->save();
    $match = $match->fresh(['listing.user', 'taker', 'providerSnapshots']);

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response(
            faceitOpposingRosterFixture(creatorGuid: 'guid-a1', takerGuid: 'guid-b1'),
            200,
        ),
    ]);

    app(DispatchAutoFetchAction::class)->handle($match);

    $balanceAfterFirst = Wallet::balanceFor($creator->fresh());
    $cardCountAfterFirst = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->whereJsonContains('attachments_json', [['source' => 'auto_fetch', 'provider' => 'faceit']])
        ->count();

    expect($balanceAfterFirst)->toBe('580.000000')
        ->and($cardCountAfterFirst)->toBe(1);

    // Second dispatch with the same match — should be a not_pending skip
    // (match is Settled now, the action records the skip and doesn't push a job).
    app(DispatchAutoFetchAction::class)->handle($match->fresh());

    expect(Wallet::balanceFor($creator->fresh()))->toBe($balanceAfterFirst)
        ->and(Message::query()
            ->where('match_id', $match->id)
            ->where('type', MessageType::System)
            ->whereJsonContains('attachments_json', [['source' => 'auto_fetch', 'provider' => 'faceit']])
            ->count())->toBe(1);

    $skipAttempt = MatchAutoFetchAttempt::query()
        ->where('match_id', $match->id)
        ->where('outcome', AutoFetchOutcome::Skipped)
        ->where('outcome_reason', 'not_pending')
        ->first();
    expect($skipAttempt)->not->toBeNull();
});

test('non-FACEIT-linked taker cannot take a CS2 FACEIT listing → not_linked sentinel, no money moves', function () {
    platformUser();

    $creator = User::factory()->active()->withFaceit('alice-faceit', 'guid-a1')->create();
    $taker = User::factory()->create(); // intentionally NO FACEIT link
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()
        ->forGame(Game::Cs2)
        ->for($creator)
        ->state([
            'stake_amount' => '100',
            'platform' => LinkedAccountProvider::Faceit,
            'status' => ListingStatus::Open,
        ])
        ->create();

    Wallet::hold(
        user: $creator,
        amount: '100',
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $result = app(TakeListingAction::class)->handle($taker, $listing->fresh());

    expect($result)->toBe('not_linked')
        ->and(Wallet::balanceFor($taker->fresh()))->toBe('500.000000')
        ->and($listing->fresh()->status)->toBe(ListingStatus::Open);
});
