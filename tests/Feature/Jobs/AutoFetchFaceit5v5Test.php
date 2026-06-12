<?php

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Enums\WalletTransactionType;
use App\Jobs\AutoFetchFaceitGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\MatchProviderSnapshot;
use App\Models\Message;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Provider\FaceitGameClient;
use App\Services\Provider\ProviderCircuitBreaker;
use App\Services\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
 * M34 Phase 4 — 5v5 team-play settlement via auto-fetch + SettleFromCard.
 *
 * Covers the team payout fan-out path: AutoFetchFaceitGameJob iterating
 * through slot-0/slot-1 seeds, the strict isOpposingTeamRosters check,
 * card payload with winning_team + winner_user_ids, the SettleFromCardAction
 * defensive roster check, and SettleTeamMatchAction's per-player wallet
 * payouts + fee + conservation invariant.
 */

beforeEach(function () {
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
    config(['stakly.platform_fee_rate' => '0.10']);
});

/**
 * Build a locked 5v5 CS2 match — 10 players (5 per team), each with a
 * FACEIT linked account snapshot + an EscrowHold on the listing. Mirrors
 * the post-`LobbyLockAction` DB state without driving the full lobby flow
 * (faster + lets tests parametrize stake / GUIDs directly).
 *
 * @param  list<string>  $teamAGuids  Slot-ordered FACEIT GUIDs for side 'a'.
 * @param  list<string>  $teamBGuids  Slot-ordered FACEIT GUIDs for side 'b'.
 */
function faceit5v5Match(
    array $teamAGuids = ['guid-a1', 'guid-a2', 'guid-a3', 'guid-a4', 'guid-a5'],
    array $teamBGuids = ['guid-b1', 'guid-b2', 'guid-b3', 'guid-b4', 'guid-b5'],
    string $stake = '100',
): GameMatch {
    platformUser();

    $teamA = [];
    $teamB = [];

    foreach ($teamAGuids as $i => $guid) {
        $user = User::factory()->active()->withFaceit("alice{$i}-faceit", $guid)->create();
        Wallet::deposit($user, '500', reference: "test:deposit:a:{$user->id}");
        $teamA[] = $user;
    }
    foreach ($teamBGuids as $i => $guid) {
        $user = User::factory()->active()->withFaceit("bob{$i}-faceit", $guid)->create();
        Wallet::deposit($user, '500', reference: "test:deposit:b:{$user->id}");
        $teamB[] = $user;
    }

    $creator = $teamA[0];

    $listing = Listing::factory()
        ->forGame(Game::Cs2)
        ->for($creator)
        ->state([
            'status' => ListingStatus::Taken,
            'stake_amount' => $stake,
            'team_size' => 5,
            'creator_side' => LobbyParticipant::SIDE_A,
            'lobby_state' => 'locked',
        ])
        ->create();

    // Hold each player's stake — mirrors the per-player ToggleReady hold.
    foreach ([...$teamA, ...$teamB] as $user) {
        Wallet::hold(
            user: $user,
            amount: $stake,
            listing: $listing,
            reference: "lobby-ready:{$listing->id}:player-{$user->id}",
        );
    }

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $teamB[0]->id,
        'status' => MatchStatus::Pending,
    ]);

    $match->forceFill(['created_at' => CarbonImmutable::now()->subHour()])->save();

    // Lobby participants — 10 live rows (5 per side, slot 0..4).
    foreach ($teamA as $slot => $user) {
        LobbyParticipant::create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => $slot,
            'is_ready' => true,
            'joined_at' => now(),
            'stake_held_at' => now(),
        ]);
    }
    foreach ($teamB as $slot => $user) {
        LobbyParticipant::create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'side' => LobbyParticipant::SIDE_B,
            'slot_index' => $slot,
            'is_ready' => true,
            'joined_at' => now(),
            'stake_held_at' => now(),
        ]);
    }

    // Snapshots — 10 rows, one per (player × FACEIT). Matches what
    // LobbyLockAction::populateSnapshots writes.
    foreach ($teamA as $slot => $user) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            'provider' => LinkedAccountProvider::Faceit,
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => $slot,
            'username' => "alice{$slot}-faceit",
            'provider_user_id' => $teamAGuids[$slot],
        ]);
    }
    foreach ($teamB as $slot => $user) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            'provider' => LinkedAccountProvider::Faceit,
            'side' => LobbyParticipant::SIDE_B,
            'slot_index' => $slot,
            'username' => "bob{$slot}-faceit",
            'provider_user_id' => $teamBGuids[$slot],
        ]);
    }

    return $match->fresh(['listing.lobbyParticipants.user', 'providerSnapshots']);
}

function runFaceitAutoFetch5v5(GameMatch $match): void
{
    (new AutoFetchFaceitGameJob($match))
        ->handle(
            app(FaceitGameClient::class),
            app(PostSystemMessageAction::class),
            app(SettleFromCardAction::class),
            app(RecordAutoFetchAttemptAction::class),
            app(ProviderCircuitBreaker::class),
        );
}

// ─── Happy path ────────────────────────────────────────────────────────────

it('settles a 5v5 match with 5 per-player payouts + 1 fee, conservation OK', function () {
    $match = faceit5v5Match();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response(
            faceitOpposingRosterFixture(),
            200,
        ),
    ]);

    runFaceitAutoFetch5v5($match);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled);

    // 5 payouts on the winning side (Team A faction1 wins) — each player
    // gets their share. stake=100, pot=1000, fee=100, winnings=900,
    // perPlayer=180 (exact; no remainder).
    $payouts = WalletTransaction::query()
        ->where('related_listing_id', $match->listing_id)
        ->where('type', WalletTransactionType::Payout)
        ->get();

    expect($payouts)->toHaveCount(5);
    foreach ($payouts as $payout) {
        expect($payout->amount)->toBe('180.000000');
    }

    // 1 platform fee
    $fees = WalletTransaction::query()
        ->where('related_listing_id', $match->listing_id)
        ->where('type', WalletTransactionType::Fee)
        ->get();
    expect($fees)->toHaveCount(1)
        ->and($fees->first()->amount)->toBe('100.000000');

    // Conservation: sum(payouts) + fee == pot (10 × stake)
    $totalPayouts = $payouts->reduce(
        fn (string $sum, $p) => bcadd($sum, $p->amount, 6),
        '0',
    );
    $sum = bcadd($totalPayouts, $fees->first()->amount, 6);
    $pot = bcmul('100', '10', 6);
    expect(bccomp($sum, $pot, 6))->toBe(0);
});

it('credits the correct 5 user IDs (winning team A)', function () {
    $match = faceit5v5Match();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response(
            faceitOpposingRosterFixture(),
            200,
        ),
    ]);

    runFaceitAutoFetch5v5($match);

    $teamAUserIds = $match->listing->lobbyParticipants
        ->where('side', LobbyParticipant::SIDE_A)
        ->pluck('user_id')
        ->map(fn ($id) => (int) $id)
        ->sort()
        ->values()
        ->all();

    $payoutUserIds = WalletTransaction::query()
        ->where('related_listing_id', $match->listing_id)
        ->where('type', WalletTransactionType::Payout)
        ->pluck('user_id')
        ->map(fn ($id) => (int) $id)
        ->sort()
        ->values()
        ->all();

    expect($payoutUserIds)->toBe($teamAUserIds);
});

// ─── Edge cases ────────────────────────────────────────────────────────────

it('refuses to settle when the FACEIT roster only has 4 of 5 Stakly players (strict)', function () {
    $match = faceit5v5Match();

    // FACEIT match where Team A slot 4 is REPLACED by a smurf —
    // not the same Stakly match. Strict check rejects.
    $fixture = faceitOpposingRosterFixture();
    $fixture['teams']['faction1']['roster'][4]['player_id'] = 'guid-smurf-x';
    $fixture['teams']['faction1']['roster'][4]['nickname'] = 'smurf-x';

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response($fixture, 200),
    ]);

    runFaceitAutoFetch5v5($match);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Pending);
    expect(WalletTransaction::query()
        ->where('related_listing_id', $match->listing_id)
        ->where('type', WalletTransactionType::Payout)
        ->count())->toBe(0);
});

it('refuses to settle when all 10 Stakly players are on the same FACEIT faction', function () {
    $match = faceit5v5Match();

    // All 10 GUIDs squeezed onto faction1 (impossible but defensive).
    $fixture = faceitOpposingRosterFixture();
    $fixture['teams']['faction1']['roster'] = [
        ['player_id' => 'guid-a1', 'nickname' => 'a1', 'anticheat_required' => true],
        ['player_id' => 'guid-a2', 'nickname' => 'a2', 'anticheat_required' => true],
        ['player_id' => 'guid-a3', 'nickname' => 'a3', 'anticheat_required' => true],
        ['player_id' => 'guid-a4', 'nickname' => 'a4', 'anticheat_required' => true],
        ['player_id' => 'guid-a5', 'nickname' => 'a5', 'anticheat_required' => true],
    ];
    $fixture['teams']['faction2']['roster'] = [
        ['player_id' => 'guid-b1', 'nickname' => 'b1', 'anticheat_required' => true],
        ['player_id' => 'guid-b2', 'nickname' => 'b2', 'anticheat_required' => true],
        ['player_id' => 'guid-b3', 'nickname' => 'b3', 'anticheat_required' => true],
        ['player_id' => 'guid-b4', 'nickname' => 'b4', 'anticheat_required' => true],
        ['player_id' => 'guid-stranger', 'nickname' => 'stranger', 'anticheat_required' => true],
    ];

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response($fixture, 200),
    ]);

    runFaceitAutoFetch5v5($match);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Pending);
});

it('routes to ManualReview-style no-action when AC is incomplete on 5v5', function () {
    $match = faceit5v5Match();

    $fixture = faceitOpposingRosterFixture();
    // Flip one player's anticheat_required off — short-circuits AC complete.
    $fixture['teams']['faction1']['roster'][2]['anticheat_required'] = false;

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response($fixture, 200),
    ]);

    runFaceitAutoFetch5v5($match);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Pending);
    expect(WalletTransaction::query()
        ->where('related_listing_id', $match->listing_id)
        ->where('type', WalletTransactionType::Payout)
        ->count())->toBe(0);
});

// ─── Idempotency ───────────────────────────────────────────────────────────

it('is idempotent — running the job twice does not double-pay', function () {
    $match = faceit5v5Match();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response(
            faceitOpposingRosterFixture(),
            200,
        ),
    ]);

    runFaceitAutoFetch5v5($match);

    // Second run — `alreadyPosted()` short-circuit AND the status guard
    // both apply. Either path prevents double-pay.
    $match->refresh();
    runFaceitAutoFetch5v5($match);

    $payouts = WalletTransaction::query()
        ->where('related_listing_id', $match->listing_id)
        ->where('type', WalletTransactionType::Payout)
        ->get();

    expect($payouts)->toHaveCount(5);
});

it('settles team B winning correctly', function () {
    $match = faceit5v5Match();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response(
            faceitOpposingRosterFixture(winnerFaction: 'faction2'),
            200,
        ),
    ]);

    runFaceitAutoFetch5v5($match);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled);

    $teamBUserIds = $match->listing->lobbyParticipants
        ->where('side', LobbyParticipant::SIDE_B)
        ->pluck('user_id')
        ->map(fn ($id) => (int) $id)
        ->sort()
        ->values()
        ->all();

    $payoutUserIds = WalletTransaction::query()
        ->where('related_listing_id', $match->listing_id)
        ->where('type', WalletTransactionType::Payout)
        ->pluck('user_id')
        ->map(fn ($id) => (int) $id)
        ->sort()
        ->values()
        ->all();

    expect($payoutUserIds)->toBe($teamBUserIds);
});

// ─── Card payload shape ────────────────────────────────────────────────────

it('posts a card carrying winning_team + winner_user_ids alongside legacy 1v1 fields', function () {
    $match = faceit5v5Match();

    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-real-match']),
            200,
        ),
        'open.faceit.com/data/v4/matches/*' => Http::response(
            faceitOpposingRosterFixture(),
            200,
        ),
    ]);

    runFaceitAutoFetch5v5($match);

    $card = Message::query()
        ->where('match_id', $match->id)
        ->where('content', 'Verified FACEIT match record.')
        ->first()
        ->attachments_json[0];

    expect($card['winning_team'])->toBe('a')
        ->and($card['winner_user_ids'])->toBeArray()
        ->and(count($card['winner_user_ids']))->toBe(5)
        // Legacy fields still present for chess back-compat.
        ->and($card['winner_user_id'])->toBe($card['winner_user_ids'][0])
        ->and($card['winner_username'])->toBe('alice0-faceit');
});

// ─── Defensive check ──────────────────────────────────────────────────────

it('rejects a malformed card whose winner_user_ids cross team boundaries', function () {
    $match = faceit5v5Match();

    // Manually inject a card with winning_team='a' but winner_user_ids
    // listing Team B players. SettleFromCardAction's defensive check
    // should reject + no-op.
    $teamBUserIds = $match->listing->lobbyParticipants
        ->where('side', LobbyParticipant::SIDE_B)
        ->pluck('user_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    $card = [
        'type' => 'game_card',
        'provider' => 'faceit',
        'source' => 'auto_fetch',
        'match_id' => 'forged-match',
        'verified' => true,
        'winning_team' => 'a',
        'winner_user_ids' => $teamBUserIds,
        'winner_user_id' => $teamBUserIds[0],
        'winner_username' => 'mismatched',
        'winner_faction' => 'faction1',
    ];

    app(SettleFromCardAction::class)->handle($match, $card);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Pending);
    expect(WalletTransaction::query()
        ->where('related_listing_id', $match->listing_id)
        ->where('type', WalletTransactionType::Payout)
        ->count())->toBe(0);
});
