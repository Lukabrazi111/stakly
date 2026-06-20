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

/*
 * M34 P3.1 Slice A.2 — `participant_previews` field powers the grid card's
 * roster avatar preview. Capped at 3, ordered by `joined_at`, kicked
 * participants excluded, empty for chess.
 */
describe('ListingResource.participant_previews', function () {
    it('is empty for chess listings', function () {
        $chess = Listing::factory()->open()->create();

        $this->get(route('listings.index', ['locale' => 'en']))
            ->assertInertia(fn ($page) => $page
                ->where('listings.data', fn ($listings) => collect($listings)
                    ->contains(fn ($l) => $l['id'] === $chess->id
                        && $l['participant_previews'] === []),
                ),
            );
    });

    it('caps team-play preview at 3 and orders by joined_at ascending', function () {
        $teamPlay = Listing::factory()->teamPlay()->create([
            'status' => ListingStatus::Open,
            'is_public' => true,
        ]);

        // Four named users joined in known order — only the first three should
        // appear in the preview, and in the same order.
        $users = User::factory()
            ->count(4)
            ->state(new Sequence(
                ['username' => 'first_joiner', 'name' => 'First Joiner'],
                ['username' => 'second_joiner', 'name' => 'Second Joiner'],
                ['username' => 'third_joiner', 'name' => 'Third Joiner'],
                ['username' => 'fourth_joiner', 'name' => 'Fourth Joiner'],
            ))
            ->active()
            ->create();

        foreach ($users as $i => $user) {
            LobbyParticipant::factory()->create([
                'listing_id' => $teamPlay->id,
                'user_id' => $user->id,
                'side' => $i < 5 ? 'a' : 'b',
                'slot_index' => $i % 5,
                'joined_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $this->get(route('listings.index', ['locale' => 'en']).'?filter[game]=cs2')
            ->assertInertia(fn ($page) => $page
                ->where('listings.data', fn ($listings) => collect($listings)
                    ->contains(function ($l) use ($teamPlay) {
                        if ($l['id'] !== $teamPlay->id) {
                            return false;
                        }

                        $previews = $l['participant_previews'];

                        return count($previews) === 3
                            && $previews[0]['username'] === 'first_joiner'
                            && $previews[1]['username'] === 'second_joiner'
                            && $previews[2]['username'] === 'third_joiner';
                    }),
                ),
            );
    });

    it('excludes kicked participants from previews', function () {
        $teamPlay = Listing::factory()->teamPlay()->create([
            'status' => ListingStatus::Open,
            'is_public' => true,
        ]);

        $kept = User::factory()->active()->create(['username' => 'kept_user']);
        $kicked = User::factory()->active()->create(['username' => 'kicked_user']);

        LobbyParticipant::factory()->create([
            'listing_id' => $teamPlay->id,
            'user_id' => $kicked->id,
            'side' => 'a',
            'slot_index' => 0,
            'kicked_at' => now()->subMinute(),
            'joined_at' => now()->subMinutes(5),
        ]);
        LobbyParticipant::factory()->create([
            'listing_id' => $teamPlay->id,
            'user_id' => $kept->id,
            'side' => 'a',
            'slot_index' => 0,
            'joined_at' => now()->subMinutes(3),
        ]);

        $this->get(route('listings.index', ['locale' => 'en']).'?filter[game]=cs2')
            ->assertInertia(fn ($page) => $page
                ->where('listings.data', fn ($listings) => collect($listings)
                    ->contains(function ($l) use ($teamPlay) {
                        if ($l['id'] !== $teamPlay->id) {
                            return false;
                        }

                        $usernames = collect($l['participant_previews'])
                            ->pluck('username')
                            ->all();

                        return $usernames === ['kept_user'];
                    }),
                ),
            );
    });

    it('exposes lobby_ready_check_deadline ISO-8601 only when ready_checking', function () {
        $recruiting = Listing::factory()->teamPlay()->create([
            'status' => ListingStatus::Open,
            'is_public' => true,
        ]);
        $readyChecking = Listing::factory()
            ->teamPlay()
            ->lobbyReadyChecking()
            ->create([
                'status' => ListingStatus::Open,
                'is_public' => true,
            ]);

        $this->get(route('listings.index', ['locale' => 'en']).'?filter[game]=cs2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('listings.data', function ($listings) use ($recruiting, $readyChecking) {
                    $recruitingRow = collect($listings)->firstWhere('id', $recruiting->id);
                    $readyCheckingRow = collect($listings)->firstWhere('id', $readyChecking->id);

                    return $recruitingRow !== null
                        && $readyCheckingRow !== null
                        && $recruitingRow['lobby_ready_check_deadline'] === null
                        && is_string($readyCheckingRow['lobby_ready_check_deadline'])
                        && str_contains($readyCheckingRow['lobby_ready_check_deadline'], 'T');
                }),
            );
    });

    it('exposes username, name, and avatar_thumb_url shape', function () {
        $teamPlay = Listing::factory()->teamPlay()->create([
            'status' => ListingStatus::Open,
            'is_public' => true,
        ]);
        $joiner = User::factory()->active()->create([
            'username' => 'preview_user',
            'name' => 'Preview User',
        ]);
        LobbyParticipant::factory()->create([
            'listing_id' => $teamPlay->id,
            'user_id' => $joiner->id,
        ]);

        $this->get(route('listings.index', ['locale' => 'en']).'?filter[game]=cs2')
            ->assertInertia(fn ($page) => $page
                ->where('listings.data', fn ($listings) => collect($listings)
                    ->contains(function ($l) use ($teamPlay) {
                        if ($l['id'] !== $teamPlay->id) {
                            return false;
                        }

                        $preview = $l['participant_previews'][0] ?? null;

                        return $preview !== null
                            && array_keys($preview) === ['username', 'name', 'avatar_thumb_url']
                            && $preview['username'] === 'preview_user'
                            && $preview['name'] === 'Preview User';
                    }),
                ),
            );
    });
});
