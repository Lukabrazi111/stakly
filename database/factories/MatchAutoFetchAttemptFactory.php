<?php

namespace Database\Factories;

use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Models\GameMatch;
use App\Models\MatchAutoFetchAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchAutoFetchAttempt>
 */
class MatchAutoFetchAttemptFactory extends Factory
{
    /**
     * Default: a `no_match` row on the Lichess provider — the most common
     * outcome in healthy operation (most page-visits / cron ticks find
     * nothing because the game hasn't been played yet). State methods below
     * swap to other outcomes for targeted scenarios.
     */
    public function definition(): array
    {
        return [
            'match_id' => GameMatch::factory(),
            'provider' => LinkedAccountProvider::Lichess,
            'outcome' => AutoFetchOutcome::NoMatch,
            'outcome_reason' => null,
            'attempt_number' => 1,
            'winner_username' => null,
            'candidates_count' => 0,
            'error_message' => null,
            'latency_ms' => $this->faker->numberBetween(50, 800),
        ];
    }

    public function matched(string $winnerUsername = 'alice-lichess'): self
    {
        return $this->state([
            'outcome' => AutoFetchOutcome::Matched,
            'winner_username' => $winnerUsername,
            'candidates_count' => 1,
        ]);
    }

    public function ambiguous(int $count = 2): self
    {
        return $this->state([
            'outcome' => AutoFetchOutcome::Ambiguous,
            'candidates_count' => $count,
        ]);
    }

    public function error(string $message = 'Lichess returned status 503.'): self
    {
        return $this->state([
            'outcome' => AutoFetchOutcome::Error,
            'candidates_count' => null,
            'error_message' => $message,
        ]);
    }

    public function skipped(string $reason): self
    {
        return $this->state([
            'outcome' => AutoFetchOutcome::Skipped,
            'outcome_reason' => $reason,
            'candidates_count' => null,
            'latency_ms' => null,
        ]);
    }

    public function chessCom(): self
    {
        return $this->state(['provider' => LinkedAccountProvider::ChessCom]);
    }
}
