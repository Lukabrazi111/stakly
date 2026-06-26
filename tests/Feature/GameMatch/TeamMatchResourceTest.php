<?php

use App\Actions\GameMatch\SettleTeamMatchAction;
use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\Notification;

/*
 * M34 P6 Slice D — `GameMatchResource` team rosters.
 *
 * Pins the JSON payload contract that the team-aware `pages/match/show.tsx`
 * (Slice E) will consume:
 *   - `team_a` / `team_b` arrays appear only when `listing.team_size > 1`
 *     AND the controller eager-loaded `lobbyParticipants`
 *   - kicked participants are filtered out
 *   - rosters ordered by slot_index
 *   - `winning_team` resolves to 'a' | 'b' on a Settled team match,
 *     null otherwise
 *   - 1v1 chess matches omit team_a/team_b/winning_team entirely
 *     (legacy shape preserved)
 */

function p6LockedTeamMatchForResource(int $teamSize = 5): array
{
    platformUser();

    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '500', reference: "test:p6d:creator:{$creator->id}");

    $listing = app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'skill_min' => null,
        'skill_max' => null,
        'time_control' => null,
        'region' => null,
        'language' => null,
        'duration_hours' => 24,
        'team_size' => $teamSize,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);

    $teamA = [$creator];
    for ($i = 1; $i < $teamSize; $i++) {
        $u = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($u, '500', reference: "test:p6d:a{$i}:{$u->id}");
        app(JoinLobbyAction::class)->handle($u, $listing, LobbyParticipant::SIDE_A);
        $teamA[] = $u;
    }

    $teamB = [];
    for ($i = 0; $i < $teamSize; $i++) {
        $u = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($u, '500', reference: "test:p6d:b{$i}:{$u->id}");
        app(JoinLobbyAction::class)->handle($u, $listing, LobbyParticipant::SIDE_B);
        $teamB[] = $u;
    }

    foreach ([...$teamA, ...$teamB] as $u) {
        app(ToggleReadyAction::class)->handle($u, $listing);
    }

    $listing->refresh();
    $match = $listing->gameMatch->fresh(['listing']);

    return [$listing, $match, $teamA, $teamB];
}

// ═══════════════════════════════════════════════════════════════════════════
// Team-play resource shape via the show controller
// ═══════════════════════════════════════════════════════════════════════════

describe('GET /matches/{match} for a 5v5 team match', function () {
    it('ships team_a + team_b with the live roster ordered by slot_index', function () {
        Notification::fake();

        [, $match, $teamA, $teamB] = p6LockedTeamMatchForResource();

        $response = $this->actingAs($teamA[0])->get(route('matches.show', $match));
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $payload = $props['match'];

        expect($payload['listing']['team_size'])->toBe(5);
        expect($payload['team_a'])->toHaveCount(5);
        expect($payload['team_b'])->toHaveCount(5);

        // Each entry has the canonical shape (M34 P8 Slice A extends the
        // shape with skill_rating + platform_stats for the rich roster
        // cards on the match page).
        foreach ($payload['team_a'] as $entry) {
            expect($entry)->toHaveKeys([
                'user_id', 'username', 'name', 'avatar_thumb_url', 'slot_index',
                'skill_rating', 'platform_stats',
            ]);
        }

        // platform_stats is attached by the controller (SellerTrust +
        // ParticipantStats batches). Non-null shape on a Pending team
        // match page visit.
        expect($payload['team_a'][0]['platform_stats'])->toHaveKeys([
            'total_matches', 'win_rate', 'completion_rate_30d',
        ]);

        // Order by slot_index ascending (slot 0, 1, 2, 3, 4).
        $aSlots = array_column($payload['team_a'], 'slot_index');
        $bSlots = array_column($payload['team_b'], 'slot_index');
        expect($aSlots)->toBe([0, 1, 2, 3, 4]);
        expect($bSlots)->toBe([0, 1, 2, 3, 4]);

        // Roster identity matches the seeded user IDs.
        $aIds = array_column($payload['team_a'], 'user_id');
        $bIds = array_column($payload['team_b'], 'user_id');
        sort($aIds);
        sort($bIds);
        $expectedA = collect($teamA)->pluck('id')->sort()->values()->all();
        $expectedB = collect($teamB)->pluck('id')->sort()->values()->all();
        expect($aIds)->toBe($expectedA);
        expect($bIds)->toBe($expectedB);

        // winning_team null because match is Pending, not Settled.
        expect($payload['winning_team'])->toBeNull();
    });

    it('filters kicked participants out of the rosters', function () {
        Notification::fake();

        [$listing, $match, , $teamB] = p6LockedTeamMatchForResource();

        // Force-kick a side-B player (bypassing the post-lock kick guard;
        // we're testing resource-level filtering, not the kick action).
        $kicked = $teamB[2];
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $kicked->id)
            ->update(['kicked_at' => now()]);

        $response = $this->actingAs($teamB[0])->get(route('matches.show', $match));
        $payload = $response->viewData('page')['props']['match'];

        $bIds = array_column($payload['team_b'], 'user_id');
        expect($bIds)->not->toContain($kicked->id);
        expect($payload['team_b'])->toHaveCount(4);
    });

    it("resolves winning_team to 'a' once the match settles to a side-A winner", function () {
        Notification::fake();

        [, $match, $teamA] = p6LockedTeamMatchForResource();

        // Drive a real settlement: side A wins, slot 0 (creator) is the
        // representative winner. SettleTeamMatchAction sets winner_user_id
        // = first user in the array.
        app(SettleTeamMatchAction::class)->handle($match, $teamA);

        $response = $this->actingAs($teamA[0])->get(route('matches.show', $match->fresh()));
        $payload = $response->viewData('page')['props']['match'];

        expect($payload['winning_team'])->toBe('a');
        expect($payload['team_a'])->toHaveCount(5);
        expect($payload['team_b'])->toHaveCount(5);
    });
});

describe('GET /matches/{match} for a Wingman 2v2', function () {
    it('ships 2-player rosters per side with team_size = 2', function () {
        Notification::fake();

        [, $match, $teamA, $teamB] = p6LockedTeamMatchForResource(teamSize: 2);

        $response = $this->actingAs($teamA[0])->get(route('matches.show', $match));
        $payload = $response->viewData('page')['props']['match'];

        expect($payload['listing']['team_size'])->toBe(2);
        expect($payload['team_a'])->toHaveCount(2);
        expect($payload['team_b'])->toHaveCount(2);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 1v1 chess — team_* fields stay omitted (legacy shape preserved)
// ═══════════════════════════════════════════════════════════════════════════

describe('GET /matches/{match} for a 1v1 chess match', function () {
    it('omits team_a / team_b / winning_team — legacy shape preserved', function () {
        platformUser();

        $creator = User::factory()->create();
        Wallet::deposit($creator, '500', reference: "test:p6d-1v1:c:{$creator->id}");
        $taker = User::factory()->create();
        Wallet::deposit($taker, '500', reference: "test:p6d-1v1:t:{$taker->id}");

        $listing = Listing::factory()->taken()->for($creator)->state([
            'stake_amount' => '100',
        ])->create();
        Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
        Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

        $match = GameMatch::factory()->create([
            'listing_id' => $listing->id,
            'taker_user_id' => $taker->id,
        ]);

        $response = $this->actingAs($creator)->get(route('matches.show', $match));
        $response->assertOk();

        $payload = $response->viewData('page')['props']['match'];

        expect($payload['listing']['team_size'])->toBe(1);
        expect($payload)->not->toHaveKey('team_a');
        expect($payload)->not->toHaveKey('team_b');
        expect($payload)->not->toHaveKey('winning_team');

        // Creator + taker still present (legacy fields untouched).
        expect($payload['creator']['id'])->toBe($creator->id);
        expect($payload['taker']['id'])->toBe($taker->id);
    });

    it('omits team_* even when status is Settled', function () {
        platformUser();

        $creator = User::factory()->create();
        Wallet::deposit($creator, '500', reference: "test:p6d-1v1s:c:{$creator->id}");
        $taker = User::factory()->create();
        Wallet::deposit($taker, '500', reference: "test:p6d-1v1s:t:{$taker->id}");

        $listing = Listing::factory()->taken()->for($creator)->state([
            'stake_amount' => '100',
        ])->create();
        Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
        Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

        $match = GameMatch::factory()->create([
            'listing_id' => $listing->id,
            'taker_user_id' => $taker->id,
            'status' => MatchStatus::Settled,
            'winner_user_id' => $creator->id,
            'settled_at' => now(),
        ]);

        $response = $this->actingAs($creator)->get(route('matches.show', $match));
        $payload = $response->viewData('page')['props']['match'];

        expect($payload)->not->toHaveKey('winning_team');
        expect($payload['winner']['id'])->toBe($creator->id);  // legacy winner field still there
    });
});
