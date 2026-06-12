<?php

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;

/*
 * M34 P3.1 Slice A — listing payload extensions for the marketplace +
 * my-listings cards. Verifies `team_size`, `lobby_state`, and the
 * `withCount`-driven `live_participant_count` field appear correctly on
 * every consuming endpoint.
 */

function placeParticipants(Listing $listing, int $count): void
{
    // Slot fan-out: fills side A slot 0..N first, overflow to side B. The
    // partial unique index `(listing_id, side, slot_index) WHERE kicked_at
    // IS NULL` rejects duplicate (side, slot) pairs.
    $assignments = [];
    for ($i = 0; $i < $count; $i++) {
        $side = $i < $listing->team_size ? 'a' : 'b';
        $slot = $i % $listing->team_size;
        $assignments[] = ['side' => $side, 'slot_index' => $slot];
    }

    LobbyParticipant::factory()
        ->count($count)
        ->state(new Sequence(...$assignments))
        ->create(['listing_id' => $listing->id]);
}

describe('ListingResource exposes team-play fields', function () {
    it('renders team_size, lobby_state, live_participant_count on the marketplace', function () {
        $teamPlay = Listing::factory()->teamPlay()->create([
            'status' => ListingStatus::Open,
            'is_public' => true,
        ]);
        placeParticipants($teamPlay, 3);
        // Kicked row must NOT contribute to live_participant_count.
        LobbyParticipant::factory()->create([
            'listing_id' => $teamPlay->id,
            'side' => 'b',
            'slot_index' => 4,
            'kicked_at' => now()->subMinute(),
        ]);

        $this->get(route('listings.index', ['locale' => 'en']).'?filter[game]=cs2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('listings.data', fn ($listings) => collect($listings)
                    ->contains(fn ($l) => $l['id'] === $teamPlay->id
                        && $l['team_size'] === 5
                        && $l['lobby_state'] === 'recruiting'
                        && $l['live_participant_count'] === 3),
                ),
            );
    });

    it('renders sane defaults for chess listings', function () {
        $chess = Listing::factory()->open()->create();

        $this->get(route('listings.index', ['locale' => 'en']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('listings.data', fn ($listings) => collect($listings)
                    ->contains(fn ($l) => $l['id'] === $chess->id
                        && $l['team_size'] === 1
                        && $l['lobby_state'] === null
                        && $l['live_participant_count'] === 0),
                ),
            );
    });

    it('renders fields on /listings/{id} for team-play listings', function () {
        $teamPlay = Listing::factory()->teamPlay()->create([
            'status' => ListingStatus::Open,
        ]);
        placeParticipants($teamPlay, 2);

        $this->get(route('listings.show', ['locale' => 'en', 'listing' => $teamPlay]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('listing.team_size', 5)
                ->where('listing.lobby_state', 'recruiting')
                ->where('listing.live_participant_count', 2),
            );
    });

    it('renders fields on /listings/mine', function () {
        $user = User::factory()->active()->create();
        $teamPlay = Listing::factory()->teamPlay()->for($user)->create([
            'status' => ListingStatus::Open,
        ]);
        LobbyParticipant::factory()->create([
            'listing_id' => $teamPlay->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('listings.mine', ['locale' => 'en']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('listings.data', fn ($listings) => collect($listings)
                    ->contains(fn ($l) => $l['id'] === $teamPlay->id
                        && $l['team_size'] === 5
                        && $l['live_participant_count'] === 1),
                ),
            );
    });

    it('renders fields on the public user profile', function () {
        $owner = User::factory()->active()->withFaceit()->create();
        $teamPlay = Listing::factory()->teamPlay()->for($owner)->create([
            'status' => ListingStatus::Open,
            'is_public' => true,
        ]);
        placeParticipants($teamPlay, 2);

        $this->get(route('users.show', ['locale' => 'en', 'user' => $owner->username]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('openListings.data', fn ($listings) => collect($listings)
                    ->contains(fn ($l) => $l['id'] === $teamPlay->id
                        && $l['team_size'] === 5
                        && $l['live_participant_count'] === 2),
                ),
            );
    });
});
