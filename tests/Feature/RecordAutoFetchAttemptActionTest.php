<?php

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Models\MatchAutoFetchAttempt;
use Illuminate\Support\Facades\Log;

test('persists a row with all supplied fields', function () {
    [, , , $match] = pendingMatch();

    app(RecordAutoFetchAttemptAction::class)->handle(
        matchId: $match->id,
        provider: LinkedAccountProvider::Lichess,
        outcome: AutoFetchOutcome::Matched,
        extras: [
            'attempt_number' => 2,
            'winner_username' => 'alice-lichess',
            'candidates_count' => 1,
            'latency_ms' => 245,
        ],
    );

    $row = MatchAutoFetchAttempt::first();
    expect($row->match_id)->toBe($match->id)
        ->and($row->provider)->toBe(LinkedAccountProvider::Lichess)
        ->and($row->outcome)->toBe(AutoFetchOutcome::Matched)
        ->and($row->attempt_number)->toBe(2)
        ->and($row->winner_username)->toBe('alice-lichess')
        ->and($row->candidates_count)->toBe(1)
        ->and($row->latency_ms)->toBe(245);
});

test('defaults attempt_number to 1 when not supplied', function () {
    [, , , $match] = pendingMatch();

    app(RecordAutoFetchAttemptAction::class)->handle(
        matchId: $match->id,
        provider: LinkedAccountProvider::Lichess,
        outcome: AutoFetchOutcome::NoMatch,
    );

    expect(MatchAutoFetchAttempt::first()->attempt_number)->toBe(1);
});

test('truncates oversized error_message to fit the column-friendly bound', function () {
    [, , , $match] = pendingMatch();

    $huge = str_repeat('A', 5000);

    app(RecordAutoFetchAttemptAction::class)->handle(
        matchId: $match->id,
        provider: LinkedAccountProvider::Lichess,
        outcome: AutoFetchOutcome::Error,
        extras: ['error_message' => $huge],
    );

    $row = MatchAutoFetchAttempt::first();
    // Trimmed to the action's 2000-char cap.
    expect(strlen($row->error_message))->toBe(2000);
});

test('preserves error_message under the truncation threshold', function () {
    [, , , $match] = pendingMatch();

    app(RecordAutoFetchAttemptAction::class)->handle(
        matchId: $match->id,
        provider: LinkedAccountProvider::Lichess,
        outcome: AutoFetchOutcome::Error,
        extras: ['error_message' => 'Lichess returned 503.'],
    );

    expect(MatchAutoFetchAttempt::first()->error_message)->toBe('Lichess returned 503.');
});

test('logs at warning level for Error outcomes and info for everything else', function () {
    [, , , $match] = pendingMatch();

    Log::spy();

    app(RecordAutoFetchAttemptAction::class)->handle(
        matchId: $match->id,
        provider: LinkedAccountProvider::Lichess,
        outcome: AutoFetchOutcome::Error,
        extras: ['error_message' => 'transient'],
    );

    app(RecordAutoFetchAttemptAction::class)->handle(
        matchId: $match->id,
        provider: LinkedAccountProvider::Lichess,
        outcome: AutoFetchOutcome::Matched,
        extras: ['winner_username' => 'alice'],
    );

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('auto_fetch.attempt', Mockery::on(fn ($ctx) => ($ctx['outcome'] ?? null) === 'error'));
    Log::shouldHaveReceived('info')
        ->once()
        ->with('auto_fetch.attempt', Mockery::on(fn ($ctx) => ($ctx['outcome'] ?? null) === 'matched'));
});

test('returns the persisted model on success', function () {
    [, , , $match] = pendingMatch();

    $attempt = app(RecordAutoFetchAttemptAction::class)->handle(
        matchId: $match->id,
        provider: LinkedAccountProvider::Lichess,
        outcome: AutoFetchOutcome::NoMatch,
    );

    expect($attempt)->toBeInstanceOf(MatchAutoFetchAttempt::class)
        ->and($attempt->exists)->toBeTrue();
});
