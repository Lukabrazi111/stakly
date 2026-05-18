<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Database\Seeder;

class ListingSeeder extends Seeder
{
    /**
     * Seed 50 listings spread across 20 creators (so power users own multiple
     * listings — closer to a real marketplace where the same names recur).
     *
     * Distribution: 40 open / 5 taken / 3 expired / 2 ending-soon. Status
     * variety lets us verify `scopeOpen` filters correctly and "ending soon"
     * sort surfaces the right rows.
     */
    public function run(): void
    {
        // `->active()` so the seeded marketplace dataset is publicly visible
        // out-of-the-box — column default is `false`, so without this every
        // seeded listing would be hidden by `scopeOnPublicMarketplace`.
        $users = User::factory()->count(20)->active()->create();

        // Every marketplace user starts with $10,000 — comfortable headroom
        // so the open/taken listing holds below never trip
        // InsufficientBalanceException. Via the service to keep ledger +
        // `usdt_balance` in sync. Idempotent: re-running won't double-credit.
        foreach ($users as $user) {
            Wallet::deposit($user, '10000', reference: "seed:dev-deposit:{$user->id}");
        }

        $open = Listing::factory()
            ->count(40)
            ->open()
            ->recycle($users)
            ->create();

        $taken = Listing::factory()
            ->count(5)
            ->taken()
            ->recycle($users)
            ->create();

        // Listings with status that should have escrow currently held:
        //   - open: stake locked while waiting for a taker
        //   - taken: stake locked while match is in progress (will release
        //            via M6 payout/dispute path)
        // Expired + cancelled listings ran through their hold/release cycle
        // historically; we don't reconstruct those entries in the seed since
        // they're net-zero and the UI never lets you act on them.
        $open->concat($taken)->each(function (Listing $listing) {
            Wallet::hold(
                user: $listing->user,
                amount: (string) $listing->stake_amount,
                listing: $listing,
                reference: "listing-create:{$listing->id}",
                description: 'Seed: stake escrowed on listing creation.',
            );
        });

        Listing::factory()
            ->count(3)
            ->expired()
            ->recycle($users)
            ->create();

        $endingSoon = Listing::factory()
            ->count(2)
            ->endingSoon()
            ->recycle($users)
            ->create();

        // ending-soon listings are still Open (just with short expiry) — also
        // need the escrow held.
        $endingSoon->each(function (Listing $listing) {
            Wallet::hold(
                user: $listing->user,
                amount: (string) $listing->stake_amount,
                listing: $listing,
                reference: "listing-create:{$listing->id}",
                description: 'Seed: stake escrowed on listing creation.',
            );
        });
    }
}
