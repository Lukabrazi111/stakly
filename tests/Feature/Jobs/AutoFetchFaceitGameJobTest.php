<?php

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\AutoFetchOutcome;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Enums\MessageType;
use App\Jobs\AutoFetchFaceitGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchAutoFetchAttempt;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\FaceitGameClient;
use App\Services\Provider\ProviderCircuitBreaker;
use App\Services\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
});

/**
 * Build a Pending CS2 match with FACEIT snapshots for both sides
 * (provider_user_id = the GUID FACEIT returns on `/players/{id}` lookup).
 * Mirrors the production state after `TakeListingAction` writes snapshots.
 */
function faceitAutoFetchMatch(string $creatorGuid = 'guid-a1', string $takerGuid = 'guid-b1'): GameMatch
{
    platformUser();

    $creator = User::factory()->active()->withFaceit('alice-faceit', $creatorGuid)->create();
    $taker = User::factory()->withFaceit('bob-faceit', $takerGuid)->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:c:{$creator->id}");
    Wallet::deposit($taker, '500', reference: "test:deposit:t:{$taker->id}");

    $listing = Listing::factory()->taken()->forGame(Game::Cs2)->for($creator)
        ->state(['stake_amount' => '100'])
        ->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
    Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    // Backdate created_at so the search-since window sits before "now" —
    // fixture finished_at values then fall inside the window.
    $match->forceFill(['created_at' => CarbonImmutable::now()->subHour()])->save();

    foreach ([
        ['side' => GameMatch::SIDE_CREATOR, 'username' => 'alice-faceit', 'provider_user_id' => $creatorGuid],
        ['side' => GameMatch::SIDE_TAKER, 'username' => 'bob-faceit', 'provider_user_id' => $takerGuid],
    ] as $row) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            'provider' => LinkedAccountProvider::Faceit,
            ...$row,
        ]);
    }

    return $match->fresh(['listing.user', 'taker', 'providerSnapshots']);
}

function runFaceitAutoFetch(GameMatch $match): void
{
    (new AutoFetchFaceitGameJob($match))
        ->handle(
            app(FaceitGameClient::class),
            app(PostSystemMessageAction::class),
            app(SettleFromCardAction::class),
            app(RecordAutoFetchAttemptAction::class),
            app(ProviderCircuitBreaker::class),
        );
}

// ─── Happy path ────────────────────────────────────────────────────────────

test('finished FACEIT match with both rosters AC-required posts a card AND settles to the winner', function () {
    $match = faceitAutoFetchMatch();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(faceitHistoryFixture(['1-real-match']), 200),
        'open.faceit.com/data/v4/matches/*' => Http::response(faceitOpposingRosterFixture(), 200),
    ]);

    runFaceitAutoFetch($match);

    $system = Message::query()
        ->where('match_id', $match->id)
        ->where('type', MessageType::System)
        ->where('content', 'Verified FACEIT match record.')
        ->first();

    expect($system)->not->toBeNull()
        ->and($system->attachments_json)->toHaveCount(1);

    $card = $system->attachments_json[0];
    expect($card['provider'])->toBe('faceit')
        ->and($card['source'])->toBe('auto_fetch')
        ->and($card['verified'])->toBeTrue()
        ->and($card['match_id'])->toBe('1-real-match')
        ->and($card['winner_faction'])->toBe('faction1')
        ->and($card['winner_user_id'])->toBe($match->listing->user_id)
        ->and($card['winner_username'])->toBe('alice-faceit')
        ->and($card['ac_complete'])->toBeTrue();

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled)
        ->and($match->winner_user_id)->toBe($match->listing->user_id);
});

test('faction2 win settles to taker', function () {
    $match = faceitAutoFetchMatch();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(faceitHistoryFixture(['1-real-match']), 200),
        'open.faceit.com/data/v4/matches/*' => Http::response(
            faceitOpposingRosterFixture(winnerFaction: 'faction2'),
            200,
        ),
    ]);

    runFaceitAutoFetch($match);

    expect($match->fresh()->winner_user_id)->toBe($match->taker_user_id);
});

// ─── Anti-cheat gate ───────────────────────────────────────────────────────

test('AC-incomplete match records AcIncomplete, posts no card, match stays Pending', function () {
    $match = faceitAutoFetchMatch();

    $fixture = faceitOpposingRosterFixture();
    // Flip one of the unknown roster slots — Stakly users keep AC=true so
    // the test exercises "found the match but AC didn't cover everyone".
    $fixture['teams']['faction1']['roster'][2]['anticheat_required'] = false;

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(faceitHistoryFixture(['1-real-match']), 200),
        'open.faceit.com/data/v4/matches/*' => Http::response($fixture, 200),
    ]);

    runFaceitAutoFetch($match);

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())->toBe(0)
        ->and($match->fresh()->status)->toBe(MatchStatus::Pending);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->firstOrFail();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::AcIncomplete)
        ->and($attempt->outcome_reason)->toBe('ac_incomplete')
        ->and($attempt->candidates_count)->toBe(1);
});

// ─── No-match retry chain ──────────────────────────────────────────────────

test('empty history from both players records NoMatch and stays Pending', function () {
    $match = faceitAutoFetchMatch();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(faceitHistoryFixture([]), 200),
    ]);

    runFaceitAutoFetch($match);

    expect($match->fresh()->status)->toBe(MatchStatus::Pending);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->firstOrFail();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::NoMatch)
        ->and($attempt->candidates_count)->toBe(0);
});

test('candidates exist but neither player is on the opposing roster → NoMatch', function () {
    $match = faceitAutoFetchMatch(creatorGuid: 'guid-a1', takerGuid: 'guid-b1');

    // Both Stakly GUIDs land on faction1 (same team — friends queueing
    // together). Not a valid pairing for THIS Stakly match. Faction2 roster
    // is overridden to ensure neither Stakly GUID appears on it.
    $fixture = faceitMatchFixture();
    $fixture['match_id'] = '1-same-team';
    $fixture['teams']['faction1']['roster'][0]['player_id'] = 'guid-a1';
    $fixture['teams']['faction1']['roster'][1]['player_id'] = 'guid-b1';
    foreach ($fixture['teams']['faction2']['roster'] as $i => $_) {
        $fixture['teams']['faction2']['roster'][$i]['player_id'] = "guid-stranger-{$i}";
    }

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(faceitHistoryFixture(['1-same-team']), 200),
        'open.faceit.com/data/v4/matches/*' => Http::response($fixture, 200),
    ]);

    runFaceitAutoFetch($match);

    expect($match->fresh()->status)->toBe(MatchStatus::Pending);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->firstOrFail();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::NoMatch);
});

// ─── Pre-flight skips ──────────────────────────────────────────────────────

test('snapshot missing provider_user_id → Skipped, no API call', function () {
    $match = faceitAutoFetchMatch();
    // Wipe the creator's snapshot GUID — simulates a legacy row.
    MatchProviderSnapshot::query()
        ->where('match_id', $match->id)
        ->where('side', GameMatch::SIDE_CREATOR)
        ->update(['provider_user_id' => null]);

    Http::fake();

    runFaceitAutoFetch($match->fresh(['providerSnapshots']));

    Http::assertNothingSent();

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->firstOrFail();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Skipped)
        ->and($attempt->outcome_reason)->toBe('snapshot_missing');
});

test('already-posted card → Skipped, no duplicate', function () {
    $match = faceitAutoFetchMatch();

    // Pre-existing FACEIT card from a prior job run.
    Message::create([
        'match_id' => $match->id,
        'user_id' => null,
        'type' => MessageType::System,
        'content' => 'Verified FACEIT match record.',
        'attachments_json' => [[
            'type' => 'game_card',
            'provider' => 'faceit',
            'source' => 'auto_fetch',
            'match_id' => '1-prior',
        ]],
    ]);

    Http::fake();

    runFaceitAutoFetch($match);

    Http::assertNothingSent();

    expect(Message::query()->where('match_id', $match->id)->where('type', MessageType::System)->count())->toBe(1);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->firstOrFail();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Skipped)
        ->and($attempt->outcome_reason)->toBe('already_posted');
});

// ─── Fall-back from creator's history to taker's ───────────────────────────

test('creator history empty, taker history finds the match → settles correctly', function () {
    $match = faceitAutoFetchMatch();

    $creatorGuid = $match->snapshotProviderUserId(GameMatch::SIDE_CREATOR, LinkedAccountProvider::Faceit);
    $takerGuid = $match->snapshotProviderUserId(GameMatch::SIDE_TAKER, LinkedAccountProvider::Faceit);

    Http::fake([
        // Creator's history returns nothing.
        "open.faceit.com/data/v4/players/{$creatorGuid}/history*" => Http::response(faceitHistoryFixture([]), 200),
        // Taker's history returns the match.
        "open.faceit.com/data/v4/players/{$takerGuid}/history*" => Http::response(faceitHistoryFixture(['1-real-match']), 200),
        'open.faceit.com/data/v4/matches/*' => Http::response(faceitOpposingRosterFixture(), 200),
    ]);

    runFaceitAutoFetch($match);

    expect($match->fresh()->status)->toBe(MatchStatus::Settled)
        ->and($match->fresh()->winner_user_id)->toBe($match->listing->user_id);
});

// ─── Provider errors ───────────────────────────────────────────────────────

test('permanent provider error fails the job and records Error', function () {
    $match = faceitAutoFetchMatch();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response('', 403),
    ]);

    try {
        runFaceitAutoFetch($match);
    } catch (Throwable $e) {
        // ProviderError propagates from `$this->fail($e)` in the queue context;
        // in a direct unit test the throw surfaces here.
    }

    expect($match->fresh()->status)->toBe(MatchStatus::Pending);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->firstOrFail();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->outcome_reason)->toBe('permanent');
});

test('transient provider error records Error and re-throws for Laravel retry', function () {
    $match = faceitAutoFetchMatch();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response('', 503),
    ]);

    expect(fn () => runFaceitAutoFetch($match))
        ->toThrow(TransientProviderError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->firstOrFail();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error);
});
