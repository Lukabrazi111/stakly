<?php

namespace Database\Factories;

use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'match_id' => GameMatch::factory(),
            'user_id' => User::factory(),
            'type' => MessageType::Text,
            'content' => $this->faker->sentence(),
            'attachments_json' => null,
        ];
    }

    /**
     * System message — produced by internal Actions (M8 Phase 5 dispute
     * prompt), never the HTTP path. `user_id` null marks it as not
     * impersonatable.
     */
    public function system(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'type' => MessageType::System,
        ]);
    }
}
