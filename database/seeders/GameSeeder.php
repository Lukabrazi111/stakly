<?php

namespace Database\Seeders;

use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Database\Seeder;

/**
 * Mirrors the (formerly hardcoded) GameSelector tile catalog (M24 Phase 1).
 *
 * Order matches the current homepage layout: chess (Active) leads, then the
 * four supportable-in-M15 games (cs2, dota2, valorant, lol), then the four
 * parked tiles (pubg, apex, rocket-league, overwatch). Fortnite was dropped
 * from the row earlier (no API, no FACEIT/Riot path).
 *
 * `poster_path` values reference files in `public/images/games/` — these
 * pre-exist from the M24 prototype phase. Admin uploads via Filament land
 * in `storage/app/public/games/` and use that disk path instead.
 *
 * Positions step by 10 so manual drag-reorders later have gaps without
 * needing to rewrite every row.
 */
class GameSeeder extends Seeder
{
    public function run(): void
    {
        $tiles = [
            ['chess', 'Chess', '/images/games/chess.png', GameStatus::Active],
            // CS2 carries real FACEIT linking + create-flow gating from M15
            // Phase 2/3. Dota 2 stays ComingSoon until its FACEIT adapter lands
            // in a later M15 slice — flipping it Active now would dead-end the
            // create-form picker on "Link FACEIT to post".
            ['cs2', 'CS2', '/images/games/cs2.jpg', GameStatus::Active],
            ['dota2', 'Dota 2', '/images/games/dota2.jpg', GameStatus::ComingSoon],
            ['valorant', 'Valorant', '/images/games/valorant.jpg', GameStatus::ComingSoon],
            ['lol', 'League of Legends', '/images/games/league-of-legends.jpeg', GameStatus::ComingSoon],
            ['pubg', 'PUBG', '/images/games/pubg.jpg', GameStatus::ComingSoon],
            ['apex', 'Apex Legends', '/images/games/apex-legends.jpg', GameStatus::ComingSoon],
            ['rocket-league', 'Rocket League', '/images/games/rocket-league.jpg', GameStatus::ComingSoon],
            ['overwatch', 'Overwatch', '/images/games/overwatch.jpg', GameStatus::ComingSoon],
        ];

        foreach ($tiles as $index => [$slug, $name, $posterPath, $status]) {
            Game::updateOrCreate(
                ['slug' => $slug],
                [
                    'display_name' => $name,
                    'poster_path' => $posterPath,
                    'position' => ($index + 1) * 10,
                    'status' => $status,
                ]
            );
        }
    }
}
