<?php

namespace Database\Seeders;

use App\Enums\Game;
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
     *
     * The 40 open listings are split across the three Active games (Chess
     * via chess.com/Lichess; CS2 via FACEIT; Dota 2 via Steam) so the
     * per-game tabs strip + filter gating on `/listings` is testable
     * end-to-end. CS2 + Dota 2 stand in as M15 placeholders — see
     * `App\Enums\Game` and `App\Enums\LinkedAccountProvider` notes.
     */
    public function run(): void
    {
        // `->active()` so the seeded marketplace dataset is publicly visible
        // out-of-the-box — column default is `false`, so without this every
        // seeded listing would be hidden by `scopeOnPublicMarketplace`.
        //
        // `->withLichess()->withChessCom()` so seeded users pass the M8
        // Phase 5 Slice B platform-specific take + create gates on BOTH
        // platforms. The seeded `ListingFactory` randomises `platform`
        // 50/50, so every seeded user must be able to participate on either
        // side to keep the dev marketplace fully takeable. Unique-slug
        // auto-generation in the `withLichess()` / `withChessCom()`
        // factory states handles per-row DB uniqueness on the
        // `users.{provider}_username` columns.
        $users = User::factory()
            ->count(20)
            ->active()
            ->withLichess()
            ->withChessCom()
            ->create();

        // Every marketplace user starts with $10,000 — comfortable headroom
        // so the open/taken listing holds below never trip
        // InsufficientBalanceException. Via the service to keep ledger +
        // `usdt_balance` in sync. Idempotent: re-running won't double-credit.
        foreach ($users as $user) {
            Wallet::deposit($user, '10000', reference: "seed:dev-deposit:{$user->id}");
        }

        $openChess = Listing::factory()
            ->count(25)
            ->open()
            ->forGame(Game::Chess)
            ->recycle($users)
            ->create();

        $openCs2 = Listing::factory()
            ->count(8)
            ->open()
            ->forGame(Game::Cs2)
            ->recycle($users)
            ->create();

        $openDota = Listing::factory()
            ->count(7)
            ->open()
            ->forGame(Game::Dota2)
            ->recycle($users)
            ->create();

        $open = $openChess->concat($openCs2)->concat($openDota);

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
