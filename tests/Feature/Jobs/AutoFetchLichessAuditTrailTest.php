<?php

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchAutoFetchAttempt;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\Provider\LichessGameClient;
use App\Services\Wallet;
use Illuminate\Support\Facades\Http;

/**
 * M14 Phase 1 — Lichess job audit trail. The pipeline + happy-path
 * settlement behaviour lives in `AutoFetchLichessGameJobTest`; this file
 * focuses specifically on whether each return path writes the correct
 * `match_auto_fetch_attempts` row.
 *
 * Pattern mirrors `autoFetchMatch()` in the sibling test but is duplicated
 * locally to keep this file independent of helper migrations.
 */
function lichessAuditMatch(?array $snapshots = null): GameMatch
{
    platformUser();

    $creator = User::factory()->active()->withLichess('alice-lichess')->create();
    $taker = User::factory()->withLichess('bob-lichess')->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:c:{$creator->id}");
    Wallet::deposit($taker, '500', reference: "test:deposit:t:{$taker->id}");

    $listing = Listing::factory()->taken()->forLichess()->for($creator)
        ->state(['stake_amount' => '100'])->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
    Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    $snapshots ??= [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'alice-lichess'],
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'bob-lichess'],
    ];

    foreach ($snapshots as $row) {
        MatchProviderSnapshot::create(['match_id' => $match->id, ...$row]);
    }

    return $match->fresh(['listing.user', 'taker', 'providerSnapshots']);
}

function runLichessAudit(GameMatch $match): void
{
    (new AutoFetchLichessGameJob($match))
        ->handle(
            app(LichessGameClient::class),
            app(PostSystemMessageAction::class),
            app(SettleFromCardAction::class),
            app(RecordAutoFetchAttemptAction::class),
        );
}

test('matched: writes a row with winner_username + candidates_count + latency', function () {
    $match = lichessAuditMatch();
    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(json_encode(lichessGameFixture(['id' => 'abcdefgh'])), 200),
    ]);

    runLichessAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Matched)
        ->and($attempt->provider)->toBe(LinkedAccountProvider::Lichess)
        ->and($attempt->winner_username)->toBe('alice-lichess')
        ->and($attempt->candidates_count)->toBe(1)
        ->and($attempt->latency_ms)->toBeGreaterThanOrEqual(0);
});

test('no_match: writes a row with candidates_count = 0', function () {
    $match = lichessAuditMatch();
    Http::fake(['lichess.org/api/games/user/*' => Http::response('', 200)]);

    runLichessAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::NoMatch)
        ->and($attempt->candidates_count)->toBe(0)
        ->and($attempt->winner_username)->toBeNull();
});

test('ambiguous: writes a row with candidates_count = N', function () {
    $match = lichessAuditMatch();
    $g1 = json_encode(lichessGameFixture(['id' => 'game0001']));
    $g2 = json_encode(lichessGameFixture(['id' => 'game0002', 'winner' => 'black']));
    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($g1."\n".$g2, 200),
    ]);

    runLichessAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Ambiguous)
        ->and($attempt->candidates_count)->toBe(2);
});

test('error: writes a row with error_message + latency when provider 5xx', function () {
    $match = lichessAuditMatch();
    Http::fake(['lichess.org/api/games/user/*' => Http::response('boom', 503)]);

    runLichessAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->error_message)->toContain('503')
        ->and($attempt->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and($attempt->candidates_count)->toBeNull();
});

test('already_posted: writes a skipped row with reason already_posted, no provider call', function () {
    $match = lichessAuditMatch();
    Message::factory()->create([
        'match_id' => $match->id,
        'type' => MessageType::System,
        'attachments_json' => [['source' => 'auto_fetch', 'provider' => 'lichess']],
    ]);
    Http::preventStrayRequests();

    runLichessAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Skipped)
        ->and($attempt->outcome_reason)->toBe('already_posted')
        ->and($attempt->latency_ms)->toBeNull();
});

test('snapshot_missing: writes a skipped row with reason snapshot_missing (defensive)', function () {
    // No Lichess snapshot for taker side — the dispatcher should normally
    // catch this, but the job's defensive guard kicks in if called directly.
    $match = lichessAuditMatch(snapshots: [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::Lichess, 'username' => 'alice-lichess'],
    ]);
    Http::preventStrayRequests();

    runLichessAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Skipped)
        ->and($attempt->outcome_reason)->toBe('snapshot_missing');
});
