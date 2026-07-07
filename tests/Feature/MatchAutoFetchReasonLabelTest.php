<?php

use App\Models\MatchAutoFetchAttempt;

/**
 * M46 P5 — the admin-facing reason-label map. Display-only; the raw code is
 * still what's written to `outcome_reason`, so the job/audit tests are unaffected.
 */
test('reasonLabel maps known codes to human labels', function (string $code, string $label) {
    expect(MatchAutoFetchAttempt::reasonLabel($code))->toBe($label);
})->with([
    ['retry_exhausted', 'No game found after all retries'],
    ['stale_game_rejected', 'Found a game, but it started before the stake'],
    ['time_control_mismatch', 'Wrong time control'],
    ['not_pending', 'Match was no longer pending'],
    ['snapshot_missing', "Player's provider account not snapshotted"],
    ['circuit_open', 'Provider temporarily unavailable'],
    ['ac_incomplete', 'FACEIT anti-cheat not enforced on every player'],
]);

test('reasonLabel humanises an unknown code rather than leaking raw snake_case', function () {
    expect(MatchAutoFetchAttempt::reasonLabel('some_future_reason'))->toBe('Some future reason');
});

test('reasonLabel returns null for null or empty input', function () {
    expect(MatchAutoFetchAttempt::reasonLabel(null))->toBeNull()
        ->and(MatchAutoFetchAttempt::reasonLabel(''))->toBeNull();
});
