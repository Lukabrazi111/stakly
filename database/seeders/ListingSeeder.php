<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\User;
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
        $users = User::factory()->count(20)->create();

        Listing::factory()
            ->count(40)
            ->open()
            ->recycle($users)
            ->create();

        Listing::factory()
            ->count(5)
            ->taken()
            ->recycle($users)
            ->create();

        Listing::factory()
            ->count(3)
            ->expired()
            ->recycle($users)
            ->create();

        Listing::factory()
            ->count(2)
            ->endingSoon()
            ->recycle($users)
            ->create();
    }
}
