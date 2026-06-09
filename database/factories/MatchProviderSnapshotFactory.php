<?php

namespace Database\Factories;

use App\Enums\LinkedAccountProvider;
use App\Models\GameMatch;
use App\Models\MatchProviderSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MatchProviderSnapshot>
 */
class MatchProviderSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            // No default match — callers always tie a snapshot to a
            // specific match via `->for(...)` or by passing match_id.
            'match_id' => GameMatch::factory(),
            'side' => fake()->randomElement([GameMatch::SIDE_CREATOR, GameMatch::SIDE_TAKER]),
            'provider' => LinkedAccountProvider::Lichess,
            'username' => Str::slug(fake()->unique()->userName()),
            'provider_user_id' => null,
            'skill_rating_snapshot' => null,
        ];
    }

    public function creator(): static
    {
        return $this->state(fn () => ['side' => GameMatch::SIDE_CREATOR]);
    }

    public function taker(): static
    {
        return $this->state(fn () => ['side' => GameMatch::SIDE_TAKER]);
    }

    public function lichess(?string $username = null): static
    {
        return $this->state(fn () => [
            'provider' => LinkedAccountProvider::Lichess,
            ...$username ? ['username' => $username] : [],
        ]);
    }

    public function chessCom(?string $username = null): static
    {
        return $this->state(fn () => [
            'provider' => LinkedAccountProvider::ChessCom,
            ...$username ? ['username' => $username] : [],
        ]);
    }

    public function faceit(?string $username = null, ?string $providerUserId = null, ?int $skillRating = null): static
    {
        return $this->state(fn () => [
            'provider' => LinkedAccountProvider::Faceit,
            ...$username ? ['username' => $username] : [],
            'provider_user_id' => $providerUserId ?? (string) Str::uuid(),
            'skill_rating_snapshot' => $skillRating ?? fake()->numberBetween(800, 2200),
        ]);
    }
}
