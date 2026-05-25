<?php

namespace Database\Seeders;

use App\Actions\GameMatch\SettleDrawMatchAction;
use App\Actions\GameMatch\SettleMatchAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Populates settled-match history for a handful of seeded users so the
 * profile stats hero (M18 Phase 2) and the match-history section render
 * with realistic numbers out of the box. Without this seeder every
 * profile would show "No matches yet" and you'd have to play matches
 * manually to see anything populated.
 *
 * What gets populated:
 *   - `testuser` — 12 matches, win-heavy (~67% win rate, mix of W/L/D).
 *   - 3 marketplace users — 5–7 matches each, varied win rates.
 *   - Remaining ~17 marketplace users stay empty so the empty-state UI
 *     is also testable.
 *
 * Each match goes through the real `SettleMatchAction` / `SettleDrawMatchAction`
 * so the wallet ledger stays in sync — `users.usdt_balance` continues to
 * equal `SUM(wallet_transactions.amount)` per the invariant asserted in
 * `WalletTest`. Slower than direct GameMatch inserts but keeps the demo
 * environment internally consistent (you can also check the wallet page
 * for the seeded users and see real ledger activity).
 */
class MatchHistorySeeder extends Seeder
{
    public function __construct(
        private readonly SettleMatchAction $settleMatch,
        private readonly SettleDrawMatchAction $settleDraw,
    ) {}

    public function run(): void
    {
        $testUser = User::query()->where('username', 'testuser')->first();
        if (! $testUser) {
            $this->command->warn('testuser not found — MatchHistorySeeder skipped.');

            return;
        }

        // Pool of marketplace users who already have $10k + linked
        // accounts + active mode (set up by ListingSeeder). Exclude
        // platform + admin + testuser.
        $marketplace = User::query()
            ->where('is_platform', false)
            ->where('is_active_mode', true)
            ->where('username', '!=', 'testuser')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'admin'))
            ->limit(20)
            ->get();

        if ($marketplace->count() < 4) {
            $this->command->warn('Not enough marketplace users — MatchHistorySeeder skipped.');

            return;
        }

        // testuser-centric history: 12 matches, ~67% win rate (8W-3L-1D),
        // varied stakes + platforms + creator/taker sides + settled-at dates.
        $this->seedMatchesFor(
            user: $testUser,
            opponents: $marketplace->take(8),
            outcomes: ['win', 'win', 'win', 'loss', 'win', 'draw', 'win', 'win', 'loss', 'win', 'win', 'loss'],
            stakes: ['50', '100', '100', '150', '200', '50', '250', '75', '500', '100', '300', '125'],
        );

        // Three other "active" demo users with varied win rates so visiting
        // multiple profiles shows different stories.
        //   - alice: 6 matches, mostly wins (5W-1L → 83%)
        //   - bob: 7 matches, balanced (3W-3L-1D → 50% with a draw)
        //   - carol: 5 matches, mostly losses (1W-3L-1D → 25%)
        $alice = $marketplace->skip(0)->first();
        $bob = $marketplace->skip(1)->first();
        $carol = $marketplace->skip(2)->first();

        $aliceOpponents = $marketplace->skip(3)->take(4);
        $bobOpponents = $marketplace->skip(7)->take(4);
        $carolOpponents = $marketplace->skip(11)->take(4);

        $this->seedMatchesFor(
            user: $alice,
            opponents: $aliceOpponents,
            outcomes: ['win', 'win', 'win', 'win', 'loss', 'win'],
            stakes: ['100', '50', '200', '150', '100', '300'],
        );

        $this->seedMatchesFor(
            user: $bob,
            opponents: $bobOpponents,
            outcomes: ['win', 'loss', 'draw', 'win', 'loss', 'win', 'loss'],
            stakes: ['200', '100', '50', '250', '150', '100', '500'],
        );

        $this->seedMatchesFor(
            user: $carol,
            opponents: $carolOpponents,
            outcomes: ['loss', 'win', 'loss', 'draw', 'loss'],
            stakes: ['75', '100', '50', '150', '200'],
        );
    }

    /**
     * @param  Collection<int, User>  $opponents
     * @param  list<'win'|'loss'|'draw'>  $outcomes
     * @param  list<string>  $stakes  Same length as `$outcomes`. BCMath strings.
     */
    private function seedMatchesFor(User $user, $opponents, array $outcomes, array $stakes): void
    {
        $count = count($outcomes);
        if (count($stakes) !== $count) {
            throw new \InvalidArgumentException('outcomes and stakes must be same length');
        }

        // Top-up the focused user so they can afford the stake holds across
        // all their matches. Sum of stakes is rarely > $2k so $5k headroom
        // is comfortable. Idempotent reference suffix per-user keeps reruns
        // safe.
        Wallet::deposit($user, '5000', reference: "seed:match-history-topup:{$user->id}");

        for ($i = 0; $i < $count; $i++) {
            $opponent = $opponents->values()[$i % $opponents->count()];
            $stake = $stakes[$i];
            $outcome = $outcomes[$i];
            // Alternate the creator/taker role so match history shows
            // both sides of participation.
            $isCreator = $i % 2 === 0;
            $creator = $isCreator ? $user : $opponent;
            $taker = $isCreator ? $opponent : $user;

            // Alternate platforms so both Lichess + chess.com surfaces are
            // exercised in the seeded data.
            $platform = $i % 2 === 0 ? LinkedAccountProvider::Lichess : LinkedAccountProvider::ChessCom;

            $listing = $this->createTakenListing($creator, $stake, $platform);

            Wallet::hold(
                user: $creator,
                amount: $stake,
                listing: $listing,
                reference: "listing-create:{$listing->id}",
                description: 'Seed: creator stake escrowed.',
            );
            Wallet::hold(
                user: $taker,
                amount: $stake,
                listing: $listing,
                reference: "match-take:{$listing->id}",
                description: 'Seed: taker stake escrowed.',
            );

            $match = GameMatch::factory()
                ->for($listing)
                ->for($taker, 'taker')
                ->state(['status' => MatchStatus::Pending])
                ->create();

            // Match-provider snapshot pair (mirrors M8 Phase 4 — username
            // snapshot at match creation so a future unlink can't break
            // dispute resolution). One row per (side, provider) — for the
            // listing's platform only.
            $this->snapshotUsername($match, GameMatch::SIDE_CREATOR, $platform, $creator);
            $this->snapshotUsername($match, GameMatch::SIDE_TAKER, $platform, $taker);

            // Drive the real settle actions so the wallet ledger stays in
            // sync. Each match's outcome maps to the corresponding action.
            $winner = match ($outcome) {
                'win' => $user,
                'loss' => $opponent,
                'draw' => null,
            };

            if ($winner !== null) {
                $this->settleMatch->handle($match, $winner);
            } else {
                $this->settleDraw->handle($match);
            }

            // Back-date `settled_at` so the match history list shows realistic
            // chronology — newest first means the smallest `subDays` is most
            // recent. Spread across the last 90 days.
            $daysAgo = $count - $i; // most-recent settled match was $count days ago, oldest was 1 day ago
            $match->update(['settled_at' => now()->subDays($daysAgo * 7)]);
        }
    }

    private function createTakenListing(User $creator, string $stake, LinkedAccountProvider $platform): Listing
    {
        $stateMethod = $platform === LinkedAccountProvider::Lichess ? 'forLichess' : 'forChessCom';

        return Listing::factory()
            ->taken()
            ->{$stateMethod}()
            ->for($creator)
            ->state(['stake_amount' => $stake])
            ->create();
    }

    private function snapshotUsername(GameMatch $match, string $side, LinkedAccountProvider $provider, User $user): void
    {
        $username = $provider === LinkedAccountProvider::Lichess
            ? $user->lichess_username
            : $user->chess_com_username;

        // Both seeded participants have both providers verified, so the
        // snapshot username is always present — but guard defensively
        // anyway in case a future seeder change skips a link.
        if ($username === null) {
            return;
        }

        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            'side' => $side,
            'provider' => $provider->value,
            'username' => $username,
        ]);
    }
}
