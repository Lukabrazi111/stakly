<?php

namespace Database\Seeders;

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\TimeControl;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class ListingSeeder extends Seeder
{
    /**
     * Seed 50+ marketplace listings + three team-play lobbies (recruiting /
     * ready_checking / locked) so every surface on `/listings` and the
     * team-play `/listings/{id}` page has data to render against.
     *
     * Marketplace users get ALL three linked providers (chess.com, Lichess,
     * FACEIT) so the same pool can host chess + CS2 listings + populate
     * team-play lobbies. Trust signals on the lobby page flow from
     * `MatchHistorySeeder` (runs after this seeder, queried fresh at
     * page-view time).
     */
    public function run(): void
    {
        // 29 marketplace users with chess + FACEIT providers, $10k each.
        // 4 + 10 + 10 fill the three 5v5 lobbies; +4 host the 2v2 Wingman
        // lobby. Disjoint slices because `JoinLobbyAction` rejects users
        // already in another active lobby.
        $users = User::factory()
            ->count(29)
            ->active()
            ->withLichess()
            ->withChessCom()
            ->withFaceit()
            ->create();

        foreach ($users as $user) {
            Wallet::deposit($user, '10000', reference: "seed:dev-deposit:{$user->id}");
        }

        // M41 P4 — verified chess ratings so the marketplace shows real numbers
        // (with "Unrated" / provisional variety), and the accounts read fresh so
        // refresh-on-view doesn't fire real provider calls for fake usernames.
        $this->seedChessRatings($users);

        $openChess = Listing::factory()
            ->count(25)
            ->open()
            ->forGame(Game::Chess)
            ->recycle($users)
            ->create();

        // CS2 listings are team-play only (2v2 Wingman + 5v5 competitive) —
        // `Game::Cs2->allowedTeamSizes()` rejects 1v1 and the FACEIT product
        // surface doesn't have a 1v1 mode. Those CS2 lobbies land via
        // `seedTeamPlayLobby` below; the chess pool here is the only 1v1
        // marketplace content.

        $openDota = Listing::factory()
            ->count(7)
            ->open()
            ->forGame(Game::Dota2)
            ->recycle($users)
            ->create();

        $open = $openChess->concat($openDota);

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

        $endingSoon->each(function (Listing $listing) {
            Wallet::hold(
                user: $listing->user,
                amount: (string) $listing->stake_amount,
                listing: $listing,
                reference: "listing-create:{$listing->id}",
                description: 'Seed: stake escrowed on listing creation.',
            );
        });

        // M34 P3.1 — three CS2 5v5 lobbies, one per visible state, so the
        // team-play `/listings/{id}` page is fully testable. Participants
        // pulled in disjoint slices so no user is in two lobbies at once.
        $this->seedTeamPlayLobby(state: 'recruiting', participants: $users->slice(0, 4)->values(), readyCount: 2);
        $this->seedTeamPlayLobby(state: 'ready_checking', participants: $users->slice(4, 10)->values(), readyCount: 6);
        $this->seedTeamPlayLobby(state: 'locked', participants: $users->slice(14, 10)->values(), readyCount: 10);

        // M34 P5 — one 2v2 Wingman lobby in recruiting (4 slots, 1 Ready'd)
        // so the marketplace + lobby surfaces have non-5v5 team-play content
        // to render against.
        $this->seedTeamPlayLobby(state: 'recruiting', participants: $users->slice(24, 4)->values(), readyCount: 1, teamSize: 2);

        // Extra thin recruiting lobbies for marketplace volume — locked
        // lobbies hide from the public board (status=Taken) so without
        // these the CS2 tab feels sparse. Each uses a fresh creator (no
        // joiners) so the `JoinLobbyAction`'s already-in-lobby guard
        // doesn't reject anyone from the slices above.
        $extraCreators = User::factory()
            ->count(3)
            ->active()
            ->withLichess()
            ->withChessCom()
            ->withFaceit()
            ->create();
        foreach ($extraCreators as $creator) {
            $this->seedTeamPlayLobby(
                state: 'recruiting',
                participants: collect([$creator]),
                readyCount: 0,
            );
        }
    }

    /**
     * Seed per-time-control chess ratings for the pool's chess.com + Lichess
     * accounts, with realistic spread + "Unrated" / provisional variety, and
     * stamp the account fresh so refresh-on-view doesn't fire real provider
     * calls for the fake seed usernames (M41 P4).
     *
     * @param  Collection<int, User>  $users
     */
    private function seedChessRatings(Collection $users): void
    {
        $chessProviders = [LinkedAccountProvider::ChessCom, LinkedAccountProvider::Lichess];

        foreach ($users as $user) {
            $chessAccounts = $user->linkedAccounts->whereIn('provider', $chessProviders);

            foreach ($chessAccounts as $account) {
                foreach (TimeControl::cases() as $timeControl) {
                    // ~25% of (account, time control) pairs stay unrated.
                    if (fake()->boolean(25)) {
                        continue;
                    }

                    $provisional = fake()->boolean(12);

                    $account->ratings()->create([
                        'time_control' => $timeControl->value,
                        'rating' => fake()->numberBetween(800, 2400),
                        'rd' => $provisional
                            ? fake()->numberBetween(120, 300)
                            : fake()->numberBetween(40, 90),
                        'is_provisional' => $provisional,
                        'synced_at' => now(),
                    ]);
                }

                $account->update(['skill_rating_synced_at' => now()]);
            }
        }
    }

    /**
     * Seed a single team-play lobby in the given state via the production
     * actions (Create → Join → ToggleReady). Going through the actions
     * means the paired GameMatch, MatchProviderSnapshots, Wallet holds,
     * and lobby state transitions all wire up correctly without ad-hoc
     * factory plumbing.
     *
     * `state` only documents intent — the resulting lobby_state is whatever
     * the actions produce given the number of joins / Ready toggles.
     *
     * @param  Collection<int, User>  $participants
     */
    private function seedTeamPlayLobby(string $state, Collection $participants, int $readyCount, int $teamSize = 5): void
    {
        if ($participants->isEmpty()) {
            return;
        }

        $creator = $participants->first();

        // Top up so Ready holds can't trip InsufficientBalanceException.
        // Idempotent reference per-user keeps reruns safe.
        foreach ($participants as $user) {
            Wallet::deposit($user, '5000', reference: "seed:team-play-topup:{$user->id}");
        }

        $listing = app(CreateTeamPlayListingAction::class)->handle($creator, [
            'game' => Game::Cs2->value,
            'platform' => LinkedAccountProvider::Faceit->value,
            'stake_amount' => '20',
            'time_control' => null,
            'duration_hours' => 24,
            'team_size' => $teamSize,
            'creator_side' => LobbyParticipant::SIDE_A,
            'is_public' => true,
        ]);

        // The creator is auto-soft-joined; the rest of the pool joins
        // alternating sides for balance (Joiner 0 → side B, joiner 1 →
        // side A, etc.) so both teams populate evenly.
        $joiners = $participants->slice(1)->values();
        foreach ($joiners as $index => $user) {
            $side = $index % 2 === 0
                ? LobbyParticipant::SIDE_B
                : LobbyParticipant::SIDE_A;

            app(JoinLobbyAction::class)->handle($user, $listing, $side);
        }

        // Ready up the requested headcount. Once all slots Ready up the
        // ToggleReadyAction's downstream LobbyReadyCheckAction flips the
        // state to 'locked' + transitions the paired match to Pending.
        foreach ($participants->take($readyCount) as $user) {
            app(ToggleReadyAction::class)->handle($user, $listing);
        }
    }
}
