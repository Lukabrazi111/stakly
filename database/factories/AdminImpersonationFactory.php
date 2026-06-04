<?php

namespace Database\Factories;

use App\Models\AdminImpersonation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminImpersonation>
 */
class AdminImpersonationFactory extends Factory
{
    protected $model = AdminImpersonation::class;

    public function definition(): array
    {
        return [
            'admin_user_id' => User::factory()->admin(),
            'target_user_id' => User::factory(),
            'reason' => fake()->sentence(),
            'started_at' => now(),
            'ended_at' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => substr(fake()->userAgent(), 0, 255),
        ];
    }

    /** Closed row — used to test the active() scope ignores it. */
    public function ended(): static
    {
        return $this->state(fn () => [
            'started_at' => now()->subMinutes(20),
            'ended_at' => now(),
        ]);
    }
}
