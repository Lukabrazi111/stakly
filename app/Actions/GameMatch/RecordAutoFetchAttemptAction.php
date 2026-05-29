<?php

namespace App\Actions\GameMatch;

use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Models\MatchAutoFetchAttempt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * M14 Phase 1 — single write-point for `match_auto_fetch_attempts` rows.
 * Mirrors the Wallet/ledger pattern: every audit insert flows through here
 * so the row + the log line are always paired.
 *
 * Why a dedicated Action rather than direct `MatchAutoFetchAttempt::create`:
 *
 *   1. Row + log entry must never drift. Some operators tail logs; some
 *      query the table; both surfaces should agree.
 *   2. Defensive error swallowing — observability code MUST NOT take down
 *      the pipeline. If the DB insert fails (constraint violation, dropped
 *      connection mid-job), we log the failure and continue. A broken
 *      audit-trail write should never cancel a match settlement.
 *   3. Bounded error_message — provider exceptions can carry long stack
 *      detail (e.g. nested DNS error chains). The column is `text` so it
 *      fits anything, but we still trim at the boundary to keep the
 *      Filament admin view readable.
 *
 * Called from:
 *   - `DispatchAutoFetchAction` for pre-flight skip cases.
 *   - `AutoFetchLichessGameJob` / `AutoFetchChessComGameJob` for every
 *     attempt that reaches the provider.
 */
class RecordAutoFetchAttemptAction
{
    /**
     * Generous cap — long enough to carry a meaningful exception chain,
     * short enough that the Filament timeline doesn't render a wall of
     * stack text. Full message stays in the log entry.
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
            // Log the failed write itself so we can investigate, but return
            // null and let the caller carry on settling the match.
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
     * Outcome → log level mapping. `error` is the only WARNING outcome;
     * everything else is informational. Operators tailing for "what went
     * wrong" filter on level=warning and see provider failures cleanly.
     */
    private function levelFor(AutoFetchOutcome $outcome): string
    {
        return $outcome === AutoFetchOutcome::Error ? 'warning' : 'info';
    }
}
