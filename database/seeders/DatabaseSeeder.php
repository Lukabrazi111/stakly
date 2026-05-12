<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Wallet;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Platform user must exist before any Wallet::fee(...) call. Single
        // row, `is_platform = true`. Holds the accumulated platform rake.
        User::factory()->create([
            'name' => 'Stakly Platform',
            'email' => 'platform@stakly.internal',
            'is_platform' => true,
        ]);

        $test = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Seed test user balance through the service (never set
        // `usdt_balance` directly — keeps ledger + balance in sync).
        // $10k matches the per-user headroom in ListingSeeder so manual UI
        // testing has comfortable room to create multiple listings.
        Wallet::deposit($test, '10000', reference: "seed:dev-deposit:{$test->id}");

        $this->call(ListingSeeder::class);
    }
}
