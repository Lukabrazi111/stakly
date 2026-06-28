<?php

namespace Database\Seeders;

use App\Actions\GameMatch\SettleDrawMatchAction;
use App\Actions\GameMatch\SettleMatchAction;
use App\Actions\GameMatch\SettleTeamMatchAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Populates settled-match history across seeded users so every profile
 * shows realistic, non-uniform stats out of the box. Without this seeder
 * marketplace users would only appear as opponents in others' histories
 * and accumulate tiny biased samples (e.g. 1W-1D-0L → 100%) — visiting
 * a random profile would feel broken.
 *
 * Two populations:
 *   - `testuser` — 12 matches, win-heavy showcase profile (~67% win rate).
 *   - All other marketplace users — 4–6 matches each via a skill-tier
 *     cycle (index mod 4): strong / balanced / balanced / casual. Means
 *     every random profile has enough sample size for the win-rate +
 *     completion-rate signals to be meaningful.
 *
 * Each match goes through the real `SettleMatchAction` /
 * `SettleDrawMatchAction` so the wallet ledger stays in sync —
 * `users.usdt_balance == SUM(wallet_transactions.amount)` per the
 * invariant asserted in `WalletTest`. Slower than direct GameMatch
 * inserts but keeps the demo environment internally consistent.
 */
class MatchHistorySeeder extends Seeder
{
    public function __construct(
        private readonly SettleMatchAction $settleMatch,
        private readonly SettleDrawMatchAction $settleDraw,
        private readonly SettleTeamMatchAction $settleTeam,
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
        // platform + admin + testuser. Ordered by id so the index-based
        // skill tier cycle in `profileFor()` is deterministic across reruns.
        $marketplace = User::query()
            ->where('is_platform', false)
            ->where('is_active_mode', true)
            ->where('username', '!=', 'testuser')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'admin'))
            ->orderBy('id')
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

        // Every marketplace user gets a varied history based on a cycling
        // skill tier (index mod 4). Guarantees that visiting any random
        // profile shows realistic data — no accidental 100% from being an
        // opponent in 2 matches, no empty profiles.
        foreach ($marketplace->values() as $i => $user) {
            [$outcomes, $stakes] = $this->profileFor($i);

            // Opponents drawn from the rest of the marketplace, shuffled
            // for variety. Cap at `count($outcomes)` so each match has a
            // distinct opponent slot (the existing seedMatchesFor cycles
            // opponents anyway if the list is shorter, but distinct
            // opponents reads better in the seeded match-history list).
            $opponents = $marketplace
                ->where('id', '!=', $user->id)
                ->shuffle()
                ->take(count($outcomes));

            $this->seedMatchesFor(
                user: $user,
                opponents: $opponents,
                outcomes: $outcomes,
                stakes: $stakes,
            );
        }

        // M41 P7c — CS2 team-match history so the lobby slot cards' recent-form
        // (W/L) strip has data to show in the demo environment.
        $this->seedCs2TeamHistory();
    }

    /**
     * M41 P7c — settled CS2 2v2 history for every FACEIT-linked user so the
     * lobby slot cards' recent-form (W/L) strip has data in the demo
     * environment. Users are grouped into fours; each group plays four 2v2s
     * with rotating pairings so every player finishes a clean 2W-2L. Each match
     * runs through the real `SettleTeamMatchAction` (stake holds → per-winner
     * payouts via the ledger), keeping the wallet invariant intact.
     */
    private function seedCs2TeamHistory(): void
    {
        $faceitUsers = User::query()
            ->where('is_platform', false)
            ->where('is_active_mode', true)
            ->whereHas('linkedAccounts', fn ($q) => $q
                ->where('provider', LinkedAccountProvider::Faceit->value)
                ->whereNotNull('skill_rating'))
            ->orderBy('id')
            ->get();

        if ($faceitUsers->count() < 4) {
            $this->command->warn('Fewer than 4 FACEIT users — CS2 team history skipped.');

            return;
        }

        // Headroom for the stake holds across each user's four matches.
        foreach ($faceitUsers as $user) {
            Wallet::deposit($user, '5000', reference: "seed:cs2-history-topup:{$user->id}");
        }

        $stake = '50';
        $index = 0;

        foreach ($faceitUsers->chunk(4) as $group) {
            $g = $group->values();
            if ($g->count() < 4) {
                break; // skip a trailing partial group
            }

            [$a, $b, $c, $d] = [$g[0], $g[1], $g[2], $g[3]];

            // Side 'a' always wins; rotating who's on it gives every player a
            // varied 5-match record, so the lobby column shows 5 coloured chips.
            $rounds = [
                [[$a, $b], [$c, $d]],
                [[$c, $d], [$a, $b]],
                [[$a, $c], [$b, $d]],
                [[$b, $d], [$a, $c]],
                [[$a, $d], [$b, $c]],
            ];

            foreach ($rounds as [$winners, $losers]) {
                $this->settleCs2TeamMatch($winners, $losers, $stake, $index++);
            }
        }

        $this->command->info("Seeded CS2 team history for {$faceitUsers->count()} FACEIT users.");
    }

    /**
     * One settled CS2 2v2: `$winners` (side 'a') beat `$losers` (side 'b').
     * Mirrors the lobby flow — locked listing + live roster + per-player stake
     * holds — then runs the real team settlement so payouts land in the ledger.
     *
     * @param  list<User>  $winners
     * @param  list<User>  $losers
     */
    private function settleCs2TeamMatch(array $winners, array $losers, string $stake, int $index): void
    {
        $listing = Listing::factory()
            ->teamPlay(2, Game::Cs2)
            ->lobbyLocked()
            ->for($winners[0])
            ->state([
                'stake_amount' => $stake,
                'creator_side' => LobbyParticipant::SIDE_A,
            ])
            ->create();

        foreach ([[LobbyParticipant::SIDE_A, $winners], [LobbyParticipant::SIDE_B, $losers]] as [$side, $team]) {
            foreach ($team as $slot => $user) {
                LobbyParticipant::factory()->create([
                    'listing_id' => $listing->id,
                    'user_id' => $user->id,
                    'side' => $side,
                    'slot_index' => $slot,
                ]);
                Wallet::hold(
                    user: $user,
                    amount: $stake,
                    listing: $listing,
                    reference: "lobby-ready:{$listing->id}:{$user->id}",
                    description: 'Seed: CS2 team stake escrowed.',
                );
            }
        }

        $match = GameMatch::factory()
            ->for($listing)
            ->state([
                'status' => MatchStatus::Pending,
                'taker_user_id' => $losers[0]->id,
            ])
            ->create();

        $this->settleTeam->handle($match, $winners);

        // Back-date so the strip reads newest-first with a believable cadence.
        $match->update(['settled_at' => now()->subDays(($index + 1) * 2)]);
    }

    /**
     * Skill profile cycled across marketplace users so the seeded match
     * history spreads across a believable win-rate range. Index mod 4:
     *   - 0       → strong  (~80%, 6 matches)
     *   - 1, 2    → balanced (~50%, 5 matches)
     *   - 3       → casual  (~33%, 4 matches)
     *
     * Index-based selection keeps the seeder deterministic across reruns
     * given a fixed marketplace order — no faker randomness in tier choice.
     *
     * @return array{0: list<'win'|'loss'|'draw'>, 1: list<string>}
     */
    private function profileFor(int $index): array
    {
        return match ($index % 4) {
            0 => [
                ['win', 'win', 'win', 'loss', 'draw', 'win'],
                ['100', '200', '150', '300', '50', '250'],
            ],
            1, 2 => [
                ['win', 'loss', 'win', 'draw', 'loss'],
                ['100', '150', '50', '75', '200'],
            ],
            default => [
                ['loss', 'win', 'loss', 'draw'],
                ['75', '100', '50', '150'],
            ],
        };
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
