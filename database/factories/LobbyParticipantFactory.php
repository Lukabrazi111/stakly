<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LobbyParticipant>
 */
class LobbyParticipantFactory extends Factory
{
    /**
     * Default = a soft-joined participant on side A slot 0. Tests that want
     * a populated lobby pass a Listing in via `->for(...)` and override
     * `side` / `slot_index` per row. Default factory output is intentionally
     * minimal — the seeder / tests own the placement strategy.
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'user_id' => User::factory()->active(),
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => 0,
            'is_ready' => false,
            'stake_held_at' => null,
            'kicked_at' => null,
            'joined_at' => now(),
        ];
    }

    public function sideA(): static
    {
        return $this->state(fn () => ['side' => LobbyParticipant::SIDE_A]);
    }

    public function sideB(): static
    {
        return $this->state(fn () => ['side' => LobbyParticipant::SIDE_B]);
    }

    /**
     * Ready'd participant — stake held, `is_ready = true`. Real flow goes
     * through `Wallet::hold` for the actual escrow; the factory only sets
     * the row state for schema / display tests. Seeder mirrors a hold via
     * `Wallet::hold` separately when it needs the ledger to balance.
     */
    public function ready(): static
    {
        return $this->state(fn () => [
            'is_ready' => true,
            'stake_held_at' => now()->subMinutes(2),
        ]);
    }

    /**
     * Kicked participant — preserved as audit + cooldown anchor.
     * `slot_index` retained for forensics; the partial unique index ignores
     * non-null `kicked_at` rows so a fresh joiner can claim the same slot.
     */
    public function kicked(): static
    {
        return $this->state(fn () => [
            'kicked_at' => now()->subMinute(),
            'is_ready' => false,
            'stake_held_at' => null,
        ]);
    }
}
