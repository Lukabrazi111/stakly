<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Wallet;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Base32 TOTP secret for the seeded dev user. Deliberately fixed and in
     * source: withdrawals require a live 2FA code by default (M9 Phase 0d), and
     * a random per-seed secret would mean re-enrolling an authenticator after
     * every `migrate:fresh --seed`.
     */
    public const DEV_TOTP_SECRET = 'STAKLYDEVTOTP234';

    public function run(): void
    {
        // Platform user must exist before any Wallet::fee(...) call. Single
        // row, `is_platform = true`. Holds the accumulated platform rake.
        // Username is pinned so M5's profile route can predictably 404 on it.
        User::factory()->create([
            'name' => 'Stakly Platform',
            'username' => 'stakly-platform',
            'email' => 'platform@stakly.internal',
            'is_platform' => true,
        ]);

        // Test User gets a known-stable username so feature tests can hit
        // `/users/testuser` without depending on faker's random output.
        // `->active()` so manual UI testing sees the user as discoverable
        // (column default is `false`; production new users opt in).
        // All three providers linked so testuser can participate across the
        // full marketplace + team-play lobbies out-of-the-box without
        // needing to hand-link accounts before testing.
        $test = User::factory()
            ->active()
            ->withLichess('testuser-lichess')
            ->withChessCom('testuser-chesscom')
            ->withFaceit('testuser-faceit', skillRating: 1500)
            ->create([
                'name' => 'Test User',
                'username' => 'testuser',
                'email' => 'test@example.com',
                'bio' => "Hi! I'm the seeded test profile.\nNew here — let's play.",
                // Fixed, publicly-known TOTP secret (M9 Phase 0d). Withdrawals
                // require a fresh code by default, so a random secret would
                // make local cash-out untestable without enrolling by hand.
                // Add DEV_TOTP_SECRET to any authenticator app, or print the
                // current code with:
                //   sail artisan stakly:dev-totp
                // Dev-only — this seeder never runs against production data.
                'two_factor_secret' => encrypt(self::DEV_TOTP_SECRET),
                'two_factor_recovery_codes' => encrypt(json_encode(['dev-recovery-code'])),
                'two_factor_confirmed_at' => now(),
            ]);

        // Seed test user balance through the service (never set
        // `usdt_balance` directly — keeps ledger + balance in sync).
        // $10k matches the per-user headroom in ListingSeeder so manual UI
        // testing has comfortable room to create multiple listings.
        Wallet::deposit($test, '10000', reference: "seed:dev-deposit:{$test->id}");

        $this->call(AdminUserSeeder::class);
        $this->call(GameSeeder::class);
        $this->call(PageSeeder::class);
        $this->call(ListingSeeder::class);
        $this->call(MatchHistorySeeder::class);
        // Last: needs the test user funded and at least one listing to exist.
        $this->call(WithdrawalSeeder::class);
    }
}
