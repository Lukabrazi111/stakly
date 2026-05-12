<?php

namespace Database\Factories;

use App\Enums\Game;
use App\Enums\ListingStatus;
use App\Enums\TimeControl;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * Realistic stake amounts skew low — most matches will be $10-$100,
     * with occasional whales staking $250+.
     */
    public function definition(): array
    {
        $stake = $this->faker->randomElement([
            10, 10, 10, 20, 25, 25, 50, 50, 50, 75,
            100, 100, 150, 200, 250, 500,
        ]);

        // 70% of listings specify a skill range; 30% are "any skill".
        $hasSkillRange = $this->faker->boolean(70);
        $skillMin = $hasSkillRange ? $this->faker->numberBetween(800, 2000) : null;
        $skillMax = $hasSkillRange ? $skillMin + $this->faker->numberBetween(200, 600) : null;

        $timeControlValues = array_map(fn (TimeControl $tc) => $tc->value, TimeControl::cases());

        return [
            'user_id' => User::factory(),
            'game' => Game::Chess,
            'stake_amount' => $stake,
            'skill_min' => $skillMin,
            'skill_max' => $skillMax,
            'time_control' => $this->faker->randomElements(
                $timeControlValues,
                $this->faker->numberBetween(1, 3),
            ),
            'region' => $this->faker->randomElement([
                'Global', 'EU', 'NA', 'Asia', 'CIS', 'LATAM',
            ]),
            'language' => $this->faker->boolean(70)
                ? $this->faker->randomElements(
                    ['English', 'Russian', 'Spanish', 'German', 'Portuguese'],
                    $this->faker->numberBetween(1, 2),
                )
                : null,
            'expires_at' => $this->faker->dateTimeBetween('+1 hour', '+72 hours'),
            'status' => ListingStatus::Open,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Open,
            'expires_at' => $this->faker->dateTimeBetween('+1 hour', '+72 hours'),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Expired,
            'expires_at' => $this->faker->dateTimeBetween('-7 days', '-1 hour'),
        ]);
    }

    public function taken(): static
    {
        return $this->state(fn () => ['status' => ListingStatus::Taken]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => ListingStatus::Cancelled]);
    }

    public function endingSoon(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Open,
            'expires_at' => $this->faker->dateTimeBetween('+5 minutes', '+2 hours'),
        ]);
    }

    public function highStake(): static
    {
        return $this->state(fn () => [
            'stake_amount' => $this->faker->randomElement([250, 500, 750, 1000]),
        ]);
    }

    public function lowStake(): static
    {
        return $this->state(fn () => [
            'stake_amount' => $this->faker->randomElement([5, 10, 15, 20]),
        ]);
    }
}
