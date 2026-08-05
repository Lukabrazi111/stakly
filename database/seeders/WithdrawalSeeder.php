<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Wallet;
use App\Services\Withdrawals;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Queue;

/**
 * Dev fixtures for the M9 Phase 0b withdrawal + clearing UI (M9 Phase 0b).
 *
 * Everything routes through `Wallet` / `Withdrawals` — never a direct balance
 * write — so the seeded database satisfies the same
 * `usdt_balance == SUM(wallet_transactions.amount)` invariant the tests assert.
 *
 * Produces, for the seeded test user: winnings that are still clearing,
 * winnings that already cleared, and one withdrawal in each interesting state.
 * Plus a separate frozen user so the freeze path is visible in admin.
 */
class WithdrawalSeeder extends Seeder
{
    private const TRC20_SAMPLE = 'TQ5NMqJjW8sBSHfgWLKGdFhhWBnrjrxfnE';

    /** A second, never-used address so one seeded row keeps a LIVE Phase 0e hold. */
    private const TRC20_NEW_ADDRESS = 'TW9zL4rKpVxCn8dHqYbMfEjGa2sUtNvXhP';

    public function run(): void
    {
        $test = User::query()->where('username', 'testuser')->first();

        if ($test === null) {
            return;
        }

        // The payout jobs would otherwise fire against a real queue during
        // seeding; `send()` is invoked explicitly below where it's wanted.
        Queue::fake();

        $this->seedClearingWinnings($test);
        $this->seedWithdrawals($test);
        $this->seedFrozenUser();
    }

    /**
     * One payout still inside its insurance window and one already past it, so
     * the wallet UI shows a real total-vs-available split.
     */
    private function seedClearingWinnings(User $user): void
    {
        $listing = Listing::query()->where('user_id', '!=', $user->id)->first()
            ?? Listing::factory()->create();

        Wallet::payout(
            winner: $user,
            amount: '450',
            listing: $listing,
            reference: "seed:clearing-payout:{$user->id}",
            description: 'Match payout to winner.',
            clearsAt: now()->addHours(30),
        );

        Wallet::payout(
            winner: $user,
            amount: '180',
            listing: $listing,
            reference: "seed:cleared-payout:{$user->id}",
            description: 'Match payout to winner.',
            clearsAt: now()->subHours(6),
        );
    }

    /**
     * One withdrawal per interesting status. `sending` is left mid-flight on
     * purpose — it's the state a real provider parks in while the payout is
     * on-chain but unconfirmed, and the UI needs to render it.
     *
     * Every row here targets an address the seeded user has never used, so the
     * Phase 0e cooldown stamps a hold on each. The three settled ones get their
     * hold backdated: a Completed withdrawal carrying a future `hold_until`
     * would be nonsense fixture data, since in reality the send only happens
     * once the hold elapses. The Pending one keeps a live hold — that's the
     * state the wallet + admin hold banners exist to render.
     */
    private function seedWithdrawals(User $user): void
    {
        $admin = User::query()->whereNot('is_platform', true)
            ->whereKeyNot($user->id)
            ->first();

        $completed = Withdrawals::request($user->fresh(), '120', self::TRC20_SAMPLE);
        $this->elapseHold($completed);
        Withdrawals::send($completed->fresh());

        $rejected = Withdrawals::request($user->fresh(), '75', self::TRC20_SAMPLE);
        $this->elapseHold($rejected);
        Withdrawals::reject($rejected->fresh(), 'Destination address failed our screening check.', $admin ?? $user);

        $failed = Withdrawals::request($user->fresh(), '40', self::TRC20_SAMPLE);
        $this->elapseHold($failed);
        Withdrawals::markFailed($failed->fresh(), 'Provider reported insufficient hot-wallet liquidity.');

        // Left Pending with a LIVE hold, to a fresh address — this is the row
        // that renders the "new address, sending in X" banner. Its
        // ProcessWithdrawal job is faked, so it stays queued.
        Withdrawals::request($user->fresh(), '60', self::TRC20_NEW_ADDRESS);
    }

    /**
     * Backdate a seeded hold so the row can legitimately reach a settled state.
     */
    private function elapseHold(Withdrawal $withdrawal): void
    {
        if ($withdrawal->hold_until !== null) {
            $withdrawal->forceFill(['hold_until' => now()->subHour()])->save();
        }
    }

    /**
     * A frozen account with a balance, so the admin freeze toggle and the
     * blocked-withdrawal path both have something to act on.
     */
    private function seedFrozenUser(): void
    {
        $frozen = User::factory()->active()->create([
            'name' => 'Frozen Player',
            'username' => 'frozen-player',
            'email' => 'frozen@stakly.internal',
        ]);

        Wallet::deposit($frozen, '250', reference: "seed:dev-deposit:{$frozen->id}");

        $frozen->forceFill([
            'frozen_at' => now()->subDay(),
            'frozen_reason' => 'Opponent reported suspected engine use; awaiting chess.com review.',
        ])->save();
    }
}
