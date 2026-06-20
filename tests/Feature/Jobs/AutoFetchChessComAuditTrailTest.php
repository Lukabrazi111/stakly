<?php

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Jobs\AutoFetchChessComGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchAutoFetchAttempt;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\ProviderCircuitBreaker;
use App\Services\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * M14 Phase 1 — chess.com job audit trail. Mirrors the Lichess audit suite
 * but exercises the chess.com-specific concerns: `attempt_number` populated
 * from the queue retry counter, and the `retry_exhausted` reason on the
 * terminal NoMatch row.
 *
 * Direct-handle invocation means `$this->attempts()` returns 1 outside a
 * queue worker (the existing chess.com test suite documents this
 * limitation — see `'empty archive does not post...'`). The
 * `retry_exhausted` reason path is asserted via a tiny subclass that
 * overrides `attempts()` rather than dispatching through a real worker.
 */
function chessComAuditMatch(?array $snapshots = null): GameMatch
{
    platformUser();

    $creator = User::factory()->active()->withChessCom('alice-chesscom')->create();
    $taker = User::factory()->withChessCom('bob-chesscom')->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:c:{$creator->id}");
    Wallet::deposit($taker, '500', reference: "test:deposit:t:{$taker->id}");

    // M14 Slice 3d — TC catch-all so happy-path tests pass deterministically.
    $listing = Listing::factory()->taken()->forChessCom()->for($creator)
        ->state(['stake_amount' => '100', 'time_control' => ['blitz', 'rapid', 'classical']])
        ->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
    Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    // Backdate created_at so fixture end_times of `now() - 5min` fall
    // inside the searchGamesBetween window. See the sibling test helper
    // `chessComAutoFetchMatch()` for the original rationale.
    $match->forceFill(['created_at' => CarbonImmutable::now()->subHour()])->save();

    $snapshots ??= [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::ChessCom, 'username' => 'alice-chesscom'],
        ['side' => GameMatch::SIDE_TAKER, 'provider' => LinkedAccountProvider::ChessCom, 'username' => 'bob-chesscom'],
    ];

    foreach ($snapshots as $row) {
        MatchProviderSnapshot::create(['match_id' => $match->id, ...$row]);
    }

    return $match->fresh(['listing.user', 'taker', 'providerSnapshots']);
}

function runChessComAudit(GameMatch $match): void
{
    (new AutoFetchChessComGameJob($match))
        ->handle(
            app(ChessComGameClient::class),
            app(PostSystemMessageAction::class),
            app(SettleFromCardAction::class),
            app(RecordAutoFetchAttemptAction::class),
            app(ProviderCircuitBreaker::class),
        );
}

test('matched: writes a row with provider=chess_com and attempt_number=1', function () {
    $match = chessComAuditMatch();
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/55555555555',
                    'end_time' => CarbonImmutable::now()->subMinutes(5)->timestamp,
                ]),
            ]),
            200,
        ),
    ]);

    runChessComAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->latest('id')->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Matched)
        ->and($attempt->provider)->toBe(LinkedAccountProvider::ChessCom)
        ->and($attempt->winner_username)->toBe('alice-chesscom')
        ->and($attempt->candidates_count)->toBe(1)
        ->and($attempt->attempt_number)->toBe(1);
});

test('no_match (non-final attempt): writes outcome_reason null', function () {
    $match = chessComAuditMatch();
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(chessComArchiveFixture([]), 200),
    ]);

    runChessComAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::NoMatch)
        ->and($attempt->candidates_count)->toBe(0)
        ->and($attempt->outcome_reason)->toBeNull()
        ->and($attempt->attempt_number)->toBe(1);
});

test('no_match on final attempt: writes outcome_reason = retry_exhausted', function () {
    $match = chessComAuditMatch();
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(chessComArchiveFixture([]), 200),
    ]);

    // Stand-in for a queue worker that has already burned through the
    // retry budget — overrides `attempts()` to return the terminal value.
    $job = new class($match) extends AutoFetchChessComGameJob
    {
        public function attempts(): int
        {
            return 4;
        }
    };

    $job->handle(
        app(ChessComGameClient::class),
        app(PostSystemMessageAction::class),
        app(SettleFromCardAction::class),
        app(RecordAutoFetchAttemptAction::class),
        app(ProviderCircuitBreaker::class),
    );

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::NoMatch)
        ->and($attempt->outcome_reason)->toBe('retry_exhausted')
        ->and($attempt->attempt_number)->toBe(4);
});

test('error (5xx): writes a row + re-throws TransientProviderError for retry', function () {
    $match = chessComAuditMatch();
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('boom', 503),
    ]);

    expect(fn () => runChessComAudit($match))->toThrow(TransientProviderError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->provider)->toBe(LinkedAccountProvider::ChessCom)
        ->and($attempt->error_message)->toContain('503')
        ->and($attempt->outcome_reason)->toBeNull();
});

test('error (4xx other than 429): writes outcome_reason=permanent + does not re-throw (job fails internally)', function () {
    $match = chessComAuditMatch();
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 403),
    ]);

    expect(fn () => runChessComAudit($match))->not->toThrow(PermanentProviderError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->outcome_reason)->toBe('permanent')
        ->and($attempt->error_message)->toContain('403');
});

test('rate limited (429): writes a row + re-throws RateLimitedError for retry', function () {
    $match = chessComAuditMatch();
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 429),
    ]);

    expect(fn () => runChessComAudit($match))->toThrow(RateLimitedError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->error_message)->toContain('429');
});

test('rate limited with Retry-After: release() called with provider-supplied delay (overrides backoff)', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');
    $match = chessComAuditMatch();
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 429, ['Retry-After' => '120']),
    ]);

    $job = new class($match) extends AutoFetchChessComGameJob
    {
        public ?int $releasedDelay = null;

        public function release($delay = 0): mixed
        {
            $this->releasedDelay = $delay;

            return null;
        }
    };

    expect(fn () => $job->handle(
        app(ChessComGameClient::class),
        app(PostSystemMessageAction::class),
        app(SettleFromCardAction::class),
        app(RecordAutoFetchAttemptAction::class),
        app(ProviderCircuitBreaker::class),
    ))->not->toThrow(RateLimitedError::class);

    expect($job->releasedDelay)->toBe(120);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->error_message)->toContain('429');
});

test('rate limited on final attempt: throws instead of release (budget exhausted)', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');
    $match = chessComAuditMatch();
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 429, ['Retry-After' => '60']),
    ]);

    $job = new class($match) extends AutoFetchChessComGameJob
    {
        public function attempts(): int
        {
            return 15; // matches $tries (M35 P1 raised it to absorb RateLimited middleware releases)
        }
    };

    expect(fn () => $job->handle(
        app(ChessComGameClient::class),
        app(PostSystemMessageAction::class),
        app(SettleFromCardAction::class),
        app(RecordAutoFetchAttemptAction::class),
        app(ProviderCircuitBreaker::class),
    ))->toThrow(RateLimitedError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome_reason)->toBe('retry_exhausted');
});

test('error on final attempt: writes outcome_reason=retry_exhausted', function () {
    $match = chessComAuditMatch();
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 503),
    ]);

    // Stand-in for a queue worker on the final attempt — overrides
    // `attempts()` so `errorRetriesExhausted()` evaluates true at the
    // shared $tries = 15 budget.
    $job = new class($match) extends AutoFetchChessComGameJob
    {
        public function attempts(): int
        {
            return 15;
        }
    };

    expect(fn () => $job->handle(
        app(ChessComGameClient::class),
        app(PostSystemMessageAction::class),
        app(SettleFromCardAction::class),
        app(RecordAutoFetchAttemptAction::class),
        app(ProviderCircuitBreaker::class),
    ))->toThrow(TransientProviderError::class);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Error)
        ->and($attempt->outcome_reason)->toBe('retry_exhausted');
});

test('ambiguous: writes a row with candidates_count + outcome_reason=time_control_mismatch (M14 Slice 3c)', function () {
    $match = chessComAuditMatch();
    // Force TC mismatch so the picker rejects both candidates.
    $match->listing->update(['time_control' => ['classical']]);

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture(['url' => 'https://www.chess.com/game/live/1', 'end_time' => CarbonImmutable::now()->timestamp]),
                chessComGameFixture(['url' => 'https://www.chess.com/game/live/2', 'end_time' => CarbonImmutable::now()->timestamp]),
            ]),
            200,
        ),
    ]);

    runChessComAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Ambiguous)
        ->and($attempt->candidates_count)->toBe(2)
        ->and($attempt->outcome_reason)->toBe('time_control_mismatch');
});

test('multiple candidates with TC match: picker picks closest → outcome=matched, candidates_count=N (M14 Slice 3c)', function () {
    $match = chessComAuditMatch();
    $match->listing->update(['time_control' => ['blitz']]);

    // chessComAuditMatch backdates created_at to 1h ago. early = -50min, late = -10min from now.
    // |early - created| = 10min; |late - created| = 50min → picker picks early.
    $earlyTs = CarbonImmutable::now()->subMinutes(50)->timestamp;
    $lateTs = CarbonImmutable::now()->subMinutes(10)->timestamp;

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/early1',
                    'end_time' => $earlyTs,
                ]),
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/late01',
                    'end_time' => $lateTs,
                    'white' => ['username' => 'alice-chesscom', 'result' => 'win'],
                    'black' => ['username' => 'bob-chesscom', 'result' => 'checkmated'],
                ]),
            ]),
            200,
        ),
    ]);

    runChessComAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Matched)
        ->and($attempt->candidates_count)->toBe(2);
});

test('already_posted: writes skipped/already_posted row, no provider call', function () {
    $match = chessComAuditMatch();
    Message::factory()->create([
        'match_id' => $match->id,
        'type' => MessageType::System,
        'attachments_json' => [['source' => 'auto_fetch', 'provider' => 'chess_com']],
    ]);
    Http::preventStrayRequests();

    runChessComAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Skipped)
        ->and($attempt->outcome_reason)->toBe('already_posted');
});

test('retry policy: 15 tries (M35 P1 widened for throttle releases), [5, 15, 30] backoff, retryUntil at match.created_at + M16 timeout', function () {
    $match = chessComAuditMatch();
    $job = new AutoFetchChessComGameJob($match);

    expect($job->tries)->toBe(15);
    expect($job->backoff())->toBe([5, 15, 30]);
    expect($job->retryUntil()->getTimestamp())->toBe(
        $match->created_at
            ->copy()
            ->addHours((int) config('stakly.match_confirmation_timeout_hours'))
            ->getTimestamp(),
    );
});

test('snapshot_missing (defensive): writes skipped/snapshot_missing row', function () {
    $match = chessComAuditMatch(snapshots: [
        ['side' => GameMatch::SIDE_CREATOR, 'provider' => LinkedAccountProvider::ChessCom, 'username' => 'alice-chesscom'],
    ]);
    Http::preventStrayRequests();

    runChessComAudit($match);

    $attempt = MatchAutoFetchAttempt::query()->where('match_id', $match->id)->first();
    expect($attempt->outcome)->toBe(AutoFetchOutcome::Skipped)
        ->and($attempt->outcome_reason)->toBe('snapshot_missing');
});
