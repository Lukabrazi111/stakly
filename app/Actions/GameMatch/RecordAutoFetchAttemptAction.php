<?php

namespace App\Actions\GameMatch;

use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Models\MatchAutoFetchAttempt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single write-point for `match_auto_fetch_attempts` rows. Pairs the DB row with a log line
 * so operators tailing either surface see the same record. Audit-trail writes that fail must
 * never cascade into pipeline failure — DB errors are logged + swallowed.
 */
class RecordAutoFetchAttemptAction
{
    /**
     * Trim at the boundary so the Filament timeline stays readable. Full message stays in logs.
     */
    private const ERROR_MESSAGE_MAX_LENGTH = 2000;

    /**
     * @param  array{outcome_reason?: ?string, attempt_number?: int, winner_username?: ?string, candidates_count?: ?int, error_message?: ?string, latency_ms?: ?int}  $extras
     */
    public function handle(
        int $matchId,
        LinkedAccountProvider $provider,
        AutoFetchOutcome $outcome,
        array $extras = [],
    ): ?MatchAutoFetchAttempt {
        $row = $this->buildRow($matchId, $provider, $outcome, $extras);

        $attempt = $this->persist($row);

        $this->mirrorToLog($matchId, $provider, $outcome, $extras);

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private function buildRow(
        int $matchId,
        LinkedAccountProvider $provider,
        AutoFetchOutcome $outcome,
        array $extras,
    ): array {
        $errorMessage = $extras['error_message'] ?? null;

        if (is_string($errorMessage) && strlen($errorMessage) > self::ERROR_MESSAGE_MAX_LENGTH) {
            $errorMessage = substr($errorMessage, 0, self::ERROR_MESSAGE_MAX_LENGTH);
        }

        return [
            'match_id' => $matchId,
            'provider' => $provider,
            'outcome' => $outcome,
            'outcome_reason' => $extras['outcome_reason'] ?? null,
            'attempt_number' => $extras['attempt_number'] ?? 1,
            'winner_username' => $extras['winner_username'] ?? null,
            'candidates_count' => $extras['candidates_count'] ?? null,
            'error_message' => $errorMessage,
            'latency_ms' => $extras['latency_ms'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function persist(array $row): ?MatchAutoFetchAttempt
    {
        try {
            return MatchAutoFetchAttempt::create($row);
        } catch (Throwable $e) {
            // Audit-trail failures must never cascade into pipeline failure.
            Log::warning('Audit insert failed (match_auto_fetch_attempts)', [
                'match_id' => $row['match_id'] ?? null,
                'outcome' => $row['outcome'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function mirrorToLog(
        int $matchId,
        LinkedAccountProvider $provider,
        AutoFetchOutcome $outcome,
        array $extras,
    ): void {
        $level = $this->levelFor($outcome);

        Log::$level('auto_fetch.attempt', [
            'match_id' => $matchId,
            'provider' => $provider->value,
            'outcome' => $outcome->value,
            'outcome_reason' => $extras['outcome_reason'] ?? null,
            'attempt_number' => $extras['attempt_number'] ?? 1,
            'candidates_count' => $extras['candidates_count'] ?? null,
            'winner_username' => $extras['winner_username'] ?? null,
            'latency_ms' => $extras['latency_ms'] ?? null,
            // Full untruncated error to logs; the row may have trimmed it.
            'error_message' => $extras['error_message'] ?? null,
        ]);
    }

    /**
     * `error` is the only WARNING outcome so operators can filter level=warning
     * for "what went wrong" and see provider failures cleanly.
     */
    private function levelFor(AutoFetchOutcome $outcome): string
    {
        return $outcome === AutoFetchOutcome::Error ? 'warning' : 'info';
    }
}
