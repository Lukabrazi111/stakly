<?php

namespace Database\Factories;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'display_name' => Str::title($name),
            'poster_path' => null,
            // No `position` default — the model's creating hook auto-appends
            // (MAX+10). Tests that care about order pass `position` explicitly.
            'status' => GameStatus::ComingSoon,
        ];
    }

    public function active(): self
    {
        return $this->state(['status' => GameStatus::Active]);
    }

    public function comingSoon(): self
    {
        return $this->state(['status' => GameStatus::ComingSoon]);
    }

    public function disabled(): self
    {
        return $this->state(['status' => GameStatus::Disabled]);
    }
}
