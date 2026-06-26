<?php

namespace Database\Factories;

use App\Enums\LinkedAccountProvider;
use App\Enums\TimeControl;
use App\Models\LinkedAccount;
use App\Models\LinkedAccountRating;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LinkedAccountRating>
 *
 * Note: `time_control` defaults to a random value. To create SEVERAL ratings
 * for ONE account, chain `forTimeControl()` per row (or a `Sequence`) — the
 * unique (linked_account_id, time_control) constraint rejects duplicates.
 * Standalone `->create()` is safe (each spins up its own LinkedAccount).
 */
class LinkedAccountRatingFactory extends Factory
{
    protected $model = LinkedAccountRating::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to a fresh Lichess link so `->create()` works standalone;
            // real callers chain `->for($linkedAccount)` (LinkedAccount has no
            // factory of its own — it's created via UserFactory states).
            'linked_account_id' => fn (): int => LinkedAccount::create([
                'user_id' => User::factory()->create()->id,
                'provider' => LinkedAccountProvider::Lichess->value,
                'username' => Str::slug(fake()->unique()->userName()),
                'verified_at' => now(),
            ])->id,
            'time_control' => $this->faker->randomElement(TimeControl::cases())->value,
            'rating' => $this->faker->numberBetween(800, 2400),
            'rd' => $this->faker->numberBetween(40, 90),
            'is_provisional' => false,
            'synced_at' => now(),
        ];
    }

    public function forTimeControl(TimeControl $timeControl): static
    {
        return $this->state(fn () => ['time_control' => $timeControl->value]);
    }

    /**
     * Low-confidence rating (few games) — rendered "Unrated".
     */
    public function provisional(): static
    {
        return $this->state(fn () => [
            'is_provisional' => true,
            'rd' => $this->faker->numberBetween(120, 350),
        ]);
    }
}
