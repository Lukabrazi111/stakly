<?php

namespace Database\Factories;

use App\Enums\MatchAdminResolutionAction;
use App\Models\GameMatch;
use App\Models\MatchAdminResolution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchAdminResolution>
 */
class MatchAdminResolutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'match_id' => GameMatch::factory(),
            'admin_user_id' => User::factory()->admin(),
            'action' => MatchAdminResolutionAction::SettleDraw,
            'winner_user_id' => null,
            'reason' => fake()->sentence(),
        ];
    }
}
