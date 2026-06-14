<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/*
 * M34 P3.1 Slice B.2 — `aggregates` block on the lobby payload powers the
 * FACEIT-grade center column (money breakdown / skill matchup / trust
 * signals). Resource math is presentation-only; Wallet stays BCMath-exact
 * for any real money write.
 */

function aggLobby(int $teamSize = 5, string $stake = '100'): Listing
{
    platformUser();

    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '10000', reference: "test:agg-create:{$creator->id}");

    return app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => $stake,
        'time_control' => [],
        'duration_hours' => 24,
        'team_size' => $teamSize,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);
}

function aggGetLobby(Listing $listing): array
{
    $response = test()->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]));
    $response->assertOk();
    $props = $response->original->getData()['page']['props'];

    return $props['lobby'];
}

describe('LobbyResource.aggregates — money', function () {
    it('computes pot = team_size × 2 × stake_amount', function () {
        $listing = aggLobby(teamSize: 5, stake: '150');

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['pot'])->toBe(1500.0);
    });

    it('computes fee = pot × fee_rate', function () {
        config(['stakly.platform_fee_rate' => '0.10']);
        $listing = aggLobby(teamSize: 5, stake: '150');

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['fee'])->toBe(150.0);
    });

    it('computes winner_take_per_player = (pot − fee) / team_size', function () {
        config(['stakly.platform_fee_rate' => '0.10']);
        $listing = aggLobby(teamSize: 5, stake: '150');

        $lobby = aggGetLobby($listing);

        // pot=1500, fee=150, winners share 1350 / 5 = 270 each
        expect($lobby['aggregates']['winner_take_per_player'])->toBe(270.0);
    });

    it('reports loser_loss_per_player = stake_amount (the unrefunded escrow)', function () {
        $listing = aggLobby(teamSize: 5, stake: '150');

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['loser_loss_per_player'])->toBe(150.0);
    });

    it('handles 2v2 with a different team_size', function () {
        config(['stakly.platform_fee_rate' => '0.10']);
        $listing = aggLobby(teamSize: 2, stake: '50');

        $lobby = aggGetLobby($listing);

        // pot=200, fee=20, winner_take=180/2=90
        expect($lobby['aggregates']['pot'])->toBe(200.0);
        expect($lobby['aggregates']['fee'])->toBe(20.0);
        expect($lobby['aggregates']['winner_take_per_player'])->toBe(90.0);
        expect($lobby['aggregates']['loser_loss_per_player'])->toBe(50.0);
    });
});

describe('LobbyResource.aggregates — skill', function () {
    it('reports null avg/min/max + count 0 when only the creator joined with no skill_rating', function () {
        $listing = aggLobby();

        // Creator's FACEIT link has a default skill_rating from the factory.
        // Strip it so we test the "no ratings" path.
        $listing->user->linkedAccounts()->update(['skill_rating' => null]);

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['skill']['a']['avg'])->toBeNull();
        expect($lobby['aggregates']['skill']['a']['min'])->toBeNull();
        expect($lobby['aggregates']['skill']['a']['max'])->toBeNull();
        expect($lobby['aggregates']['skill']['a']['count'])->toBe(0);
    });

    it('computes avg/min/max across players with known ratings', function () {
        $listing = aggLobby();

        // Set the creator's FACEIT skill rating to a known value.
        $listing->user->linkedAccounts()->update(['skill_rating' => 1500]);

        // Add two more side-A joiners with distinct ratings.
        $a2 = User::factory()->active()->withFaceit(skillRating: 2000)->create();
        $a3 = User::factory()->active()->withFaceit(skillRating: 1000)->create();
        app(JoinLobbyAction::class)->handle($a2, $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)->handle($a3, $listing, LobbyParticipant::SIDE_A);

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['skill']['a']['avg'])->toBe(1500);
        expect($lobby['aggregates']['skill']['a']['min'])->toBe(1000);
        expect($lobby['aggregates']['skill']['a']['max'])->toBe(2000);
        expect($lobby['aggregates']['skill']['a']['count'])->toBe(3);
    });

    it('classifies delta tone — "even" at ≤50', function () {
        $listing = aggLobby();
        $listing->user->linkedAccounts()->update(['skill_rating' => 1500]);

        $b = User::factory()->active()->withFaceit(skillRating: 1530)->create();
        app(JoinLobbyAction::class)->handle($b, $listing, LobbyParticipant::SIDE_B);

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['skill']['delta'])->toBe(30);
        expect($lobby['aggregates']['skill']['delta_tone'])->toBe('even');
    });

    it('classifies delta tone — "mismatched" at >50 and ≤150', function () {
        $listing = aggLobby();
        $listing->user->linkedAccounts()->update(['skill_rating' => 1500]);

        $b = User::factory()->active()->withFaceit(skillRating: 1600)->create();
        app(JoinLobbyAction::class)->handle($b, $listing, LobbyParticipant::SIDE_B);

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['skill']['delta'])->toBe(100);
        expect($lobby['aggregates']['skill']['delta_tone'])->toBe('mismatched');
    });

    it('classifies delta tone — "stacked" at >150', function () {
        $listing = aggLobby();
        $listing->user->linkedAccounts()->update(['skill_rating' => 1500]);

        $b = User::factory()->active()->withFaceit(skillRating: 1800)->create();
        app(JoinLobbyAction::class)->handle($b, $listing, LobbyParticipant::SIDE_B);

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['skill']['delta'])->toBe(300);
        expect($lobby['aggregates']['skill']['delta_tone'])->toBe('stacked');
    });

    it('returns null delta + tone when one side has no rated players', function () {
        $listing = aggLobby();
        $listing->user->linkedAccounts()->update(['skill_rating' => 1500]);

        $lobby = aggGetLobby($listing);

        // Side B has no players at all.
        expect($lobby['aggregates']['skill']['b']['avg'])->toBeNull();
        expect($lobby['aggregates']['skill']['delta'])->toBeNull();
        expect($lobby['aggregates']['skill']['delta_tone'])->toBeNull();
    });
});

describe('LobbyResource.aggregates — trust', function () {
    it('reports player_count of live participants per side', function () {
        $listing = aggLobby();

        $b1 = User::factory()->active()->withFaceit()->create();
        $b2 = User::factory()->active()->withFaceit()->create();
        app(JoinLobbyAction::class)->handle($b1, $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)->handle($b2, $listing, LobbyParticipant::SIDE_B);

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['trust']['a']['player_count'])->toBe(1); // creator only
        expect($lobby['aggregates']['trust']['b']['player_count'])->toBe(2);
    });

    it('reports null avg_completion_rate when no players have a track record', function () {
        $listing = aggLobby();

        $lobby = aggGetLobby($listing);

        expect($lobby['aggregates']['trust']['a']['avg_completion_rate'])->toBeNull();
        expect($lobby['aggregates']['trust']['a']['settled_lifetime_sum'])->toBe(0);
    });
});

it('does NOT expose the aggregates block on chess (non-team-play) listings', function () {
    $chess = Listing::factory()->open()->create();

    $response = test()->get(route('listings.show', ['locale' => 'en', 'listing' => $chess]));
    $response->assertOk();
    $props = $response->original->getData()['page']['props'];

    expect($props)->not->toHaveKey('lobby');
});
