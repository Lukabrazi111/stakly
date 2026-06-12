<?php

namespace Database\Seeders;

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
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
        // 25 marketplace users with chess + FACEIT providers, $10k each.
        // Bumped from 20 → 25 so the three team-play lobbies (4 + 10 + 10
        // participants) can pull disjoint user slices without re-using
        // anyone across lobbies — `JoinLobbyAction` rejects users already
        // in another active lobby.
        $users = User::factory()
            ->count(25)
            ->active()
            ->withLichess()
            ->withChessCom()
            ->withFaceit()
            ->create();

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
    private function seedTeamPlayLobby(string $state, Collection $participants, int $readyCount): void
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
            'time_control' => [],
            'duration_hours' => 24,
            'team_size' => 5,
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
