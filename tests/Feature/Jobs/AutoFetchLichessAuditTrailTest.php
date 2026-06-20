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
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\LichessGameClient;
use App\Services\Provider\ProviderCircuitBreaker;
use App\Services\Wallet;
use Carbon\CarbonImmutable;
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

    // M14 Slice 3d — TC catch-all so happy-path tests pass deterministically.
    $listing = Listing::factory()->taken()->forLichess()->for($creator)
        ->state(['stake_amount' => '100', 'time_control' => ['blitz', 'rapid', 'classical']])
        ->create();
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
            app(ProviderCircuitBreaker::class),
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

test('ambiguous: writes a row with candidates_count + outcome_reason=time_control_mismatch (M14 Slice 3c)', function () {
    $match = lichessAuditMatch();
    // Force TC mismatch so the picker rejects both candidates.
    $match->listing->update(['time_control' => ['classical']]);

    $g1 = json_encode(lichessGameFixture(['id' => 'game0001']));
    $g2 = json_encode(lichessGameFixture(['id' => 'game0002', 'winner' => 'black']));
    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($g1."\n".$g2, 200),
    ]);

    runLichessAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Ambiguous)
        ->and($attempt->candidates_count)->toBe(2)
        ->and($attempt->outcome_reason)->toBe('time_control_mismatch');
});

test('multiple candidates with TC match: picker picks closest → outcome=matched, candidates_count=N (M14 Slice 3c)', function () {
    $match = lichessAuditMatch();
    $match->listing->update(['time_control' => ['blitz']]);

    $earlyTs = $match->created_at->copy()->addMinutes(2)->getTimestampMs();
    $lateTs = $match->created_at->copy()->addMinutes(30)->getTimestampMs();

    $early = json_encode(lichessGameFixture(['id' => 'earlygame', 'createdAt' => $earlyTs, 'lastMoveAt' => $earlyTs]));
    $late = json_encode(lichessGameFixture(['id' => 'lategame0', 'createdAt' => $lateTs, 'lastMoveAt' => $lateTs]));

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($early."\n".$late, 200),
    ]);

    runLichessAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Matched)
        ->and($attempt->candidates_count)->toBe(2)
        ->and($attempt->winner_username)->toBe('alice-lichess');
});

test('error (5xx): writes a row + re-throws TransientProviderError for retry', function () {
    $match = lichessAuditMatch();
    Http::fake(['lichess.org/api/games/user/*' => Http::response('boom', 503)]);

    expect(fn () => runLichessAudit($match))->toThrow(TransientProviderError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->error_message)->toContain('503')
        ->and($attempt->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and($attempt->candidates_count)->toBeNull()
        ->and($attempt->outcome_reason)->toBeNull();
});

test('error (4xx other than 429): writes outcome_reason=permanent + does not re-throw (job fails internally)', function () {
    $match = lichessAuditMatch();
    Http::fake(['lichess.org/api/games/user/*' => Http::response('', 401)]);

    // Direct handle() invocation: `$this->fail($e)` is a no-op when there's
    // no queue context, so the call returns normally instead of propagating.
    expect(fn () => runLichessAudit($match))->not->toThrow(PermanentProviderError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->outcome_reason)->toBe('permanent')
        ->and($attempt->error_message)->toContain('401');
});

test('rate limited (429): writes a row + re-throws RateLimitedError for retry', function () {
    $match = lichessAuditMatch();
    Http::fake(['lichess.org/api/games/user/*' => Http::response('', 429)]);

    expect(fn () => runLichessAudit($match))->toThrow(RateLimitedError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->error_message)->toContain('429');
});

test('rate limited with Retry-After: release() called with provider-supplied delay (overrides backoff)', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');
    $match = lichessAuditMatch();
    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 429, ['Retry-After' => '90']),
    ]);

    // Subclass captures the release() delay arg so we can assert it.
    $job = new class($match) extends AutoFetchLichessGameJob
    {
        public ?int $releasedDelay = null;

        public function release($delay = 0): mixed
        {
            $this->releasedDelay = $delay;

            return null;
        }
    };

    // No throw — the RateLimitedError catch released instead of re-throwing.
    expect(fn () => $job->handle(
        app(LichessGameClient::class),
        app(PostSystemMessageAction::class),
        app(SettleFromCardAction::class),
        app(RecordAutoFetchAttemptAction::class),
        app(ProviderCircuitBreaker::class),
    ))->not->toThrow(RateLimitedError::class);

    expect($job->releasedDelay)->toBe(90);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->error_message)->toContain('429');
});

test('rate limited on final attempt: throws instead of release (budget exhausted)', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');
    $match = lichessAuditMatch();
    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 429, ['Retry-After' => '60']),
    ]);

    $job = new class($match) extends AutoFetchLichessGameJob
    {
        public function attempts(): int
        {
            return 12; // matches $tries (M35 P2 raised it to absorb RateLimited middleware releases)
        }
    };

    expect(fn () => $job->handle(
        app(LichessGameClient::class),
        app(PostSystemMessageAction::class),
        app(SettleFromCardAction::class),
        app(RecordAutoFetchAttemptAction::class),
        app(ProviderCircuitBreaker::class),
    ))->toThrow(RateLimitedError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome_reason)->toBe('retry_exhausted');
});

test('error on final attempt: writes outcome_reason=retry_exhausted', function () {
    $match = lichessAuditMatch();
    Http::fake(['lichess.org/api/games/user/*' => Http::response('', 503)]);

    // Stand-in for a queue worker on the final attempt — overrides
    // `attempts()` so `errorRetriesExhausted()` evaluates true.
    $job = new class($match) extends AutoFetchLichessGameJob
    {
        public function attempts(): int
        {
            return 12;
        }
    };

    expect(fn () => $job->handle(
        app(LichessGameClient::class),
        app(PostSystemMessageAction::class),
        app(SettleFromCardAction::class),
        app(RecordAutoFetchAttemptAction::class),
        app(ProviderCircuitBreaker::class),
    ))->toThrow(TransientProviderError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->outcome_reason)->toBe('retry_exhausted');
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

test('retry policy: 12 tries (M35 P2 widened for throttle releases), [5, 15, 30] backoff, retryUntil at match.created_at + M16 timeout', function () {
    $match = lichessAuditMatch();
    $job = new AutoFetchLichessGameJob($match);

    expect($job->tries)->toBe(12);
    expect($job->backoff())->toBe([5, 15, 30]);
    expect($job->retryUntil()->getTimestamp())->toBe(
        $match->created_at
            ->copy()
            ->addHours((int) config('stakly.match_confirmation_timeout_hours'))
            ->getTimestamp(),
    );
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
