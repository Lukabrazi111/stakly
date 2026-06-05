<?php

namespace Database\Factories;

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
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
            // Auto-created users are active by default — an "open listing"
            // implies a reachable owner, so the factory's default produces a
            // marketplace-visible listing. Tests exercising the inactive-owner
            // branch override via `->for(User::factory()->inactive()->create())`.
            'user_id' => User::factory()->active(),
            'game' => Game::Chess,
            // 50/50 platform split so the dev marketplace shows both. Tests
            // wanting a specific platform chain `->forLichess()` /
            // `->forChessCom()` below.
            'platform' => $this->faker->randomElement([
                LinkedAccountProvider::ChessCom,
                LinkedAccountProvider::Lichess,
            ]),
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

    public function forLichess(): static
    {
        return $this->state(fn () => ['platform' => LinkedAccountProvider::Lichess]);
    }

    public function forChessCom(): static
    {
        return $this->state(fn () => ['platform' => LinkedAccountProvider::ChessCom]);
    }

    /**
     * Adjusts platform + time_control to match the target game. CS2 routes
     * to FACEIT, Dota 2 routes to Steam (per the planned M15 catalog).
     * Non-chess games clear `time_control` since the concept doesn't apply
     * — `gameSupports()` hides the filter UI for them, and an empty
     * jsonb array is valid storage.
     */
    public function forGame(Game $game): static
    {
        $platform = match ($game) {
            Game::Chess => $this->faker->randomElement([
                LinkedAccountProvider::ChessCom,
                LinkedAccountProvider::Lichess,
            ]),
            Game::Cs2 => LinkedAccountProvider::Faceit,
            Game::Dota2 => LinkedAccountProvider::Steam,
        };

        return $this->state(fn () => [
            'game' => $game,
            'platform' => $platform,
            'time_control' => $game === Game::Chess
                ? $this->faker->randomElements(
                    array_map(fn (TimeControl $tc) => $tc->value, TimeControl::cases()),
                    $this->faker->numberBetween(1, 3),
                )
                : [],
        ]);
    }
}
