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
}
