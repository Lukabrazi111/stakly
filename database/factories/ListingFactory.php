<?php

namespace Database\Factories;

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\TimeControl;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            'time_control' => $this->faker->randomElement(TimeControl::cases())->value,
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
            // Default to the 1v1 shape — chess listings stay here and skip
            // the M34 lobby pipeline. Team-play tests override via
            // ->teamPlay() which sets team_size, creator_side, lobby_state.
            'team_size' => 1,
            'is_public' => true,
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
     * Team-play listing (M34). Defaults to a CS2 5v5 in `recruiting`. Override
     * via chained states (`lobbyReadyChecking()`, `lobbyLocked()`, `private()`).
     */
    public function teamPlay(int $teamSize = 5, Game $game = Game::Cs2): static
    {
        return $this->forGame($game)->state(fn () => [
            'team_size' => $teamSize,
            'creator_side' => $this->faker->randomElement([
                LobbyParticipant::SIDE_A,
                LobbyParticipant::SIDE_B,
            ]),
            'lobby_state' => 'recruiting',
        ]);
    }

    public function lobbyReadyChecking(): static
    {
        return $this->state(fn () => [
            'lobby_state' => 'ready_checking',
            // 5-minute window is the production default
            // (`LobbyReadyCheckAction`). Tests and seeders need a non-null
            // deadline so the grid card's countdown banner renders.
            'lobby_ready_check_deadline' => now()->addMinutes(5),
        ]);
    }

    /**
     * Locked = all participants Ready, match has transitioned from
     * LobbyFilling → Pending, listing.status is now Taken. Seeder is
     * responsible for creating the matching GameMatch + snapshots.
     */
    public function lobbyLocked(): static
    {
        return $this->state(fn () => [
            'lobby_state' => 'locked',
            'status' => ListingStatus::Taken,
        ]);
    }

    public function private(): static
    {
        return $this->state(fn () => [
            'is_public' => false,
            'invite_token' => Str::random(32),
        ]);
    }

    /**
     * Adjusts platform + time_control to match the target game. CS2 routes
     * to FACEIT, Dota 2 routes to Steam (per the planned M15 catalog).
     * Non-chess games set `time_control` to null since the concept doesn't
     * apply — `gameSupports()` hides the filter UI for them.
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
                ? $this->faker->randomElement(TimeControl::cases())->value
                : null,
        ]);
    }
}
