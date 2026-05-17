<?php

namespace App\Services\GameApi;

use App\Enums\GameApiConfidence;
use App\Models\GameMatch;

/**
 * Mock `GameApi` driver — deterministic, in-memory, no network calls.
 *
 * Default behaviour: the winner is picked by `match.id` parity
 * (even → creator, odd → taker). This keeps test outcomes reproducible
 * without requiring per-test setup, while still exercising both winner
 * branches across a varied seed dataset.
 *
 * Test helpers (`forceWinner`, `forceUnknown`, `reset`) override the
 * default for targeted scenarios — particularly the ManualReview branch,
 * which the deterministic default never produces. Helpers persist across
 * calls within a request, which is why this class MUST be singleton-bound
 * (see `AppServiceProvider::register`).
 *
 * Production swap: `MockGameApi` is the only `game_api_driver` value in
 * v1. When real chess.com / Lichess adapters land in M8, they implement
 * `GameApi` and the binding in `AppServiceProvider` switches based on
 * `config('stakly.game_api_driver')`.
 */
final class MockGameApi implements GameApi
{
    private ?int $forcedWinnerId = null;

    private ?GameApiConfidence $forcedConfidence = null;

    /**
     * Force the next (and subsequent) calls to return $userId as the
     * winner with Confirmed confidence. Useful for asserting that
     * settlement uses the API winner, not whatever the players claimed.
     */
    public function forceWinner(int $userId): void
    {
        $this->forcedWinnerId = $userId;
        $this->forcedConfidence = GameApiConfidence::Confirmed;
    }

    /**
     * Force the next (and subsequent) calls to return Unknown confidence,
     * exercising the `Disputed → ManualReview` branch.
     */
    public function forceUnknown(): void
    {
        $this->forcedWinnerId = null;
        $this->forcedConfidence = GameApiConfidence::Unknown;
    }

    /**
     * Force the next (and subsequent) calls to return Drawn confidence,
     * exercising the `Disputed → settleDraw` branch (both refunded, no fee).
     */
    public function forceDraw(): void
    {
        $this->forcedWinnerId = null;
        $this->forcedConfidence = GameApiConfidence::Drawn;
    }

    /**
     * Clear any forced state — subsequent calls fall back to the
     * deterministic default. Tests that share a singleton between
     * scenarios should call this in setup.
     */
    public function reset(): void
    {
        $this->forcedWinnerId = null;
        $this->forcedConfidence = null;
    }

    public function getMatchResult(GameMatch $match): GameApiResult
    {
        if ($this->forcedConfidence !== null) {
            return new GameApiResult(
                // Only `Confirmed` carries a winner; `Drawn` and `Unknown`
                // both null it. Phrased as "use the id only when Confirmed"
                // so adding future cases (e.g. partial-confidence) doesn't
                // accidentally inherit a non-null winner via the negative.
                winner_user_id: $this->forcedConfidence === GameApiConfidence::Confirmed
                    ? $this->forcedWinnerId
                    : null,
                confidence: $this->forcedConfidence,
                raw_response: [
                    'driver' => 'mock',
                    'mode' => 'forced',
                    'match_id' => $match->id,
                ],
            );
        }

        $match->loadMissing('listing');
        $creatorId = $match->listing->user_id;
        $takerId = $match->taker_user_id;
        $winnerId = ($match->id % 2 === 0) ? $creatorId : $takerId;

        return new GameApiResult(
            winner_user_id: $winnerId,
            confidence: GameApiConfidence::Confirmed,
            raw_response: [
                'driver' => 'mock',
                'mode' => 'deterministic',
                'algorithm' => 'match.id parity (even=creator, odd=taker)',
                'match_id' => $match->id,
            ],
        );
    }
}
