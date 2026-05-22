<?php

namespace Database\Factories;

use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameMatch>
 */
class GameMatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Default: a fresh Taken listing (Open state would violate the
            // listing-state precondition for a real match).
            'listing_id' => Listing::factory()->taken(),
            'taker_user_id' => User::factory(),
            'status' => MatchStatus::Pending,
            'creator_confirmed_outcome' => null,
            'taker_confirmed_outcome' => null,
            'winner_user_id' => null,
            'dispute_opened_at' => null,
            'dispute_opened_by' => null,
            'settled_at' => null,
        ];
    }

    public function creatorConfirmed(MatchOutcome $outcome): static
    {
        return $this->state(fn () => [
            'creator_confirmed_outcome' => $outcome,
        ]);
    }

    public function takerConfirmed(MatchOutcome $outcome): static
    {
        return $this->state(fn () => [
            'taker_confirmed_outcome' => $outcome,
        ]);
    }

    public function disputed(?User $opener = null): static
    {
        return $this->state(fn () => [
            'status' => MatchStatus::Disputed,
            'dispute_opened_at' => now(),
            'dispute_opened_by' => $opener?->id ?? User::factory(),
        ]);
    }

    public function settled(?User $winner = null): static
    {
        return $this->state(fn () => [
            'status' => MatchStatus::Settled,
            'winner_user_id' => $winner?->id ?? User::factory(),
            'settled_at' => now(),
        ]);
    }

    public function manualReview(): static
    {
        return $this->state(fn () => [
            'status' => MatchStatus::ManualReview,
            'dispute_opened_at' => now(),
        ]);
    }

    /**
     * Terminal Cancelled state — both stakes refunded out-of-band by
     * `AcceptCancellationAction` in real flow. Factory sets the columns
     * mechanically; tests that need a real ledger should drive the Action
     * instead.
     */
    public function cancelled(?User $requester = null): static
    {
        return $this->state(fn () => [
            'status' => MatchStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_requested_by' => $requester?->id ?? User::factory(),
            'cancellation_requested_at' => now(),
        ]);
    }

    /**
     * Pending match with an open cancellation request from `$requester`.
     * Use for testing the accept/reject paths without driving the full
     * request Action.
     */
    public function withCancellationRequest(User $requester, ?string $reason = null): static
    {
        return $this->state(fn () => [
            'cancellation_requested_by' => $requester->id,
            'cancellation_requested_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /**
     * Pending match where `$requester` previously requested cancellation
     * and was rejected `$ago` minutes ago. Use for cooldown tests.
     */
    public function withRejectedCancellation(User $requester, int $minutesAgo = 5): static
    {
        return $this->state(fn () => [
            'cancellation_requested_by' => $requester->id,
            'cancellation_requested_at' => null,
            'cancellation_rejected_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
