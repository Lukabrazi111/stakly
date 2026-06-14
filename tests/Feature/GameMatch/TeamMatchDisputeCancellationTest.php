<?php

use App\Actions\GameMatch\AcceptCancellationAction;
use App\Actions\GameMatch\OpenDisputeAction;
use App\Actions\GameMatch\RejectCancellationAction;
use App\Actions\GameMatch\RequestCancellationAction;
use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Broadcasting\MatchChannel;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\CancellationAcceptedNotification;
use App\Notifications\CancellationRejectedNotification;
use App\Notifications\CancellationRequestedNotification;
use App\Notifications\DisputeOpenedNotification;
use App\Services\Wallet;
use Illuminate\Support\Facades\Notification;

/*
 * M34 P6 — Team-match dispute + cancellation gates.
 *
 * Three concerns under one roof so the team-play setup helper isn't
 * duplicated across files:
 *   1. `GameMatchPolicy` — `view` / `openDispute` / `requestCancellation` /
 *      `acceptCancellation` / `rejectCancellation` recognise the live
 *      lobby roster, plus block same-team accept-on-team's-behalf.
 *   2. `MatchChannel` — chat subscription mirrors the policy.
 *   3. `AcceptCancellationAction` — refund fan-out across the full team
 *      roster (10 stakes returned on a 5v5 cancel), with per-user
 *      idempotency refs and balance restoration to the pre-Ready state.
 */

/**
 * Drive the real action chain to land a CS2 5v5 in `Pending` status with
 * all 10 stakes escrowed and `match_provider_snapshots` populated. Returns
 * `[$listing, $match, $teamA, $teamB]`.
 *
 * Side A = creator + 4 joiners; side B = 5 joiners. Every participant has
 * a FACEIT account linked + $500 deposited (well above the $100 stake).
 *
 * Cheaper alternative would be to hand-stamp the rows, but the real-action
 * path is the seeder convention (M34 P0 notes) and guarantees the wallet
 * ledger, snapshots, and lobby state stay consistent with production.
 */
function p6LockedTeamMatch(): array
{
    platformUser();

    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '500', reference: "test:p6:creator:{$creator->id}");

    $listing = app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'skill_min' => null,
        'skill_max' => null,
        'time_control' => [],
        'region' => null,
        'language' => null,
        'duration_hours' => 24,
        'team_size' => 5,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);

    $teamA = [$creator];
    for ($i = 0; $i < 4; $i++) {
        $user = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($user, '500', reference: "test:p6:a{$i}:{$user->id}");
        app(JoinLobbyAction::class)->handle($user, $listing, LobbyParticipant::SIDE_A);
        $teamA[] = $user;
    }

    $teamB = [];
    for ($i = 0; $i < 5; $i++) {
        $user = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($user, '500', reference: "test:p6:b{$i}:{$user->id}");
        app(JoinLobbyAction::class)->handle($user, $listing, LobbyParticipant::SIDE_B);
        $teamB[] = $user;
    }

    // Last Ready click triggers `LobbyLockAction` → match flips to Pending.
    foreach ([...$teamA, ...$teamB] as $user) {
        app(ToggleReadyAction::class)->handle($user, $listing);
    }

    $listing->refresh();
    $match = $listing->gameMatch->fresh(['listing']);

    return [$listing, $match, $teamA, $teamB];
}

// ═══════════════════════════════════════════════════════════════════════════
// Policy — view / openDispute / requestCancellation
// ═══════════════════════════════════════════════════════════════════════════

describe('GameMatchPolicy on a Pending team match', function () {
    it('lets every live team-A participant openDispute, request, view', function () {
        [, $match, $teamA] = p6LockedTeamMatch();
        expect($match->status)->toBe(MatchStatus::Pending);

        foreach ($teamA as $player) {
            expect($player->can('view', $match))->toBeTrue();
            expect($player->can('openDispute', $match))->toBeTrue();
            expect($player->can('requestCancellation', $match))->toBeTrue();
        }
    });

    it('lets every live team-B participant openDispute, request, view', function () {
        [, $match, , $teamB] = p6LockedTeamMatch();

        foreach ($teamB as $player) {
            expect($player->can('view', $match))->toBeTrue();
            expect($player->can('openDispute', $match))->toBeTrue();
            expect($player->can('requestCancellation', $match))->toBeTrue();
        }
    });

    it('blocks a kicked participant from view / openDispute / requestCancellation', function () {
        [$listing, $match, , $teamB] = p6LockedTeamMatch();

        $kickedUser = $teamB[2];
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $kickedUser->id)
            ->update(['kicked_at' => now()]);

        expect($kickedUser->can('view', $match))->toBeFalse();
        expect($kickedUser->can('openDispute', $match))->toBeFalse();
        expect($kickedUser->can('requestCancellation', $match))->toBeFalse();
    });

    it('blocks a non-roster stranger from view / openDispute / requestCancellation', function () {
        [, $match] = p6LockedTeamMatch();
        $stranger = User::factory()->active()->create();

        expect($stranger->can('view', $match))->toBeFalse();
        expect($stranger->can('openDispute', $match))->toBeFalse();
        expect($stranger->can('requestCancellation', $match))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Policy — same-team-accept block (the load-bearing M34 P6 gate)
// ═══════════════════════════════════════════════════════════════════════════

describe('GameMatchPolicy::acceptCancellation on a Pending team match', function () {
    it('blocks the requester from accepting their own request', function () {
        [, $match, $teamA] = p6LockedTeamMatch();
        $requester = $teamA[0];

        app(RequestCancellationAction::class)->handle($requester, $match);
        $match->refresh();

        expect($requester->can('acceptCancellation', $match))->toBeFalse();
        expect($requester->can('rejectCancellation', $match))->toBeFalse();
    });

    it("blocks the requester's team-mates from accepting on the team's behalf", function () {
        [, $match, $teamA] = p6LockedTeamMatch();
        $requester = $teamA[0];

        app(RequestCancellationAction::class)->handle($requester, $match);
        $match->refresh();

        // Other 4 side-A players are team-mates of the requester — they
        // must NOT be able to accept (would defeat mutual cancellation).
        foreach ([$teamA[1], $teamA[2], $teamA[3], $teamA[4]] as $teammate) {
            expect($teammate->can('acceptCancellation', $match))->toBeFalse();
            expect($teammate->can('rejectCancellation', $match))->toBeFalse();
        }
    });

    it('lets any opposing-team member accept the request', function () {
        [, $match, $teamA, $teamB] = p6LockedTeamMatch();
        $requester = $teamA[0];

        app(RequestCancellationAction::class)->handle($requester, $match);
        $match->refresh();

        foreach ($teamB as $opponent) {
            expect($opponent->can('acceptCancellation', $match))->toBeTrue();
            expect($opponent->can('rejectCancellation', $match))->toBeTrue();
        }
    });

    it('lets any opposing-team member accept when team-B requests', function () {
        [, $match, $teamA, $teamB] = p6LockedTeamMatch();
        $requester = $teamB[2];

        app(RequestCancellationAction::class)->handle($requester, $match);
        $match->refresh();

        foreach ($teamA as $opponent) {
            expect($opponent->can('acceptCancellation', $match))->toBeTrue();
        }
        foreach ([$teamB[0], $teamB[1], $teamB[3], $teamB[4]] as $teammate) {
            expect($teammate->can('acceptCancellation', $match))->toBeFalse();
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// MatchChannel — chat subscription mirrors the policy
// ═══════════════════════════════════════════════════════════════════════════

describe('MatchChannel on a Pending team match', function () {
    it('lets every live participant subscribe', function () {
        [, $match, $teamA, $teamB] = p6LockedTeamMatch();
        $channel = app(MatchChannel::class);

        foreach ([...$teamA, ...$teamB] as $player) {
            expect($channel->join($player, $match->id))->toBeTrue();
        }
    });

    it('blocks a kicked participant from subscribing', function () {
        [$listing, $match, , $teamB] = p6LockedTeamMatch();
        $kicked = $teamB[1];
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $kicked->id)
            ->update(['kicked_at' => now()]);

        expect(app(MatchChannel::class)->join($kicked, $match->id))->toBeFalse();
    });

    it('blocks a non-roster stranger from subscribing', function () {
        [, $match] = p6LockedTeamMatch();
        $stranger = User::factory()->active()->create();

        expect(app(MatchChannel::class)->join($stranger, $match->id))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// AcceptCancellationAction — team refund fan-out
// ═══════════════════════════════════════════════════════════════════════════

describe('AcceptCancellationAction on a Pending team match', function () {
    it('refunds every live participant when an opposing-team member accepts', function () {
        Notification::fake();

        [$listing, $match, $teamA, $teamB] = p6LockedTeamMatch();

        // Snapshot post-Ready balances ($500 deposit − $100 hold = $400).
        $balancesBefore = collect([...$teamA, ...$teamB])
            ->mapWithKeys(fn ($u) => [$u->id => (string) $u->fresh()->usdt_balance])
            ->all();

        foreach ($balancesBefore as $userId => $balance) {
            expect($balance)->toBe('400.000000', "before accept, user {$userId} should be 400");
        }

        app(RequestCancellationAction::class)->handle($teamA[0], $match);

        $result = app(AcceptCancellationAction::class)->handle($teamB[0], $match);

        expect($result)->toBe('cancelled');

        // Every live participant is back to the full $500.
        foreach ([...$teamA, ...$teamB] as $player) {
            expect((string) $player->fresh()->usdt_balance)
                ->toBe('500.000000', "user {$player->id} should be refunded back to 500");
        }

        // Listing + match flipped.
        $match->refresh();
        $listing->refresh();
        expect($match->status)->toBe(MatchStatus::Cancelled);
        expect($listing->status->value)->toBe('cancelled');
    });

    it('does not refund a kicked participant', function () {
        Notification::fake();

        [$listing, $match, $teamA, $teamB] = p6LockedTeamMatch();

        // Manually kick teamB[2] post-lock + clear their stake_held_at so
        // the refund query excludes them. (In practice kicking post-lock
        // is blocked by KickParticipantAction, but the policy guard for
        // `stake_held_at IS NOT NULL` is what we're actually pinning.)
        $kicked = $teamB[2];
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $kicked->id)
            ->update(['kicked_at' => now(), 'stake_held_at' => null]);

        // Simulate the kick-release manually so balances reflect reality:
        // their stake is already back from the kick path.
        Wallet::release(
            user: $kicked,
            amount: '100',
            listing: $listing,
            reference: "kick-refund:{$listing->id}:{$kicked->id}",
            description: 'Test setup — simulating kick refund.',
        );

        $balanceAfterKick = (string) $kicked->fresh()->usdt_balance;
        expect($balanceAfterKick)->toBe('500.000000');

        app(RequestCancellationAction::class)->handle($teamA[0], $match);
        $result = app(AcceptCancellationAction::class)->handle($teamB[0], $match);

        expect($result)->toBe('cancelled');

        // Kicked player's balance stays at 500 — NOT refunded a second time.
        expect((string) $kicked->fresh()->usdt_balance)->toBe('500.000000');
    });

    it('is idempotent — re-running on a Cancelled match no-ops', function () {
        Notification::fake();

        [, $match, $teamA, $teamB] = p6LockedTeamMatch();

        app(RequestCancellationAction::class)->handle($teamA[0], $match);
        app(AcceptCancellationAction::class)->handle($teamB[0], $match);

        // Second call — match is already Cancelled.
        $result = app(AcceptCancellationAction::class)->handle($teamB[1], $match);

        expect($result)->toBe('already_cancelled');

        // No double refund — everyone still at $500.
        foreach ([...$teamA, ...$teamB] as $player) {
            expect((string) $player->fresh()->usdt_balance)->toBe('500.000000');
        }
    });

    it('writes per-user wallet release rows tagged cancel-refund:{match}:{user}', function () {
        Notification::fake();

        [, $match, $teamA, $teamB] = p6LockedTeamMatch();

        app(RequestCancellationAction::class)->handle($teamA[0], $match);
        app(AcceptCancellationAction::class)->handle($teamB[0], $match);

        foreach ([...$teamA, ...$teamB] as $player) {
            $reference = "cancel-refund:{$match->id}:{$player->id}";
            $row = WalletTransaction::query()
                ->where('reference_id', $reference)
                ->first();

            expect($row)->not->toBeNull("expected refund row {$reference}");
            expect((string) $row->amount)->toBe('100.000000');
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// HTTP — controller wiring sanity (team-aware path end-to-end)
// ═══════════════════════════════════════════════════════════════════════════

describe('HTTP: POST /matches/{match}/cancellation/accept (team-play)', function () {
    it('lets an opposing-team member accept and flips the match', function () {
        Notification::fake();

        [, $match, $teamA, $teamB] = p6LockedTeamMatch();
        app(RequestCancellationAction::class)->handle($teamA[0], $match);

        $this->actingAs($teamB[0])
            ->post(route('matches.cancellation.accept', $match))
            ->assertRedirect();

        expect($match->fresh()->status)->toBe(MatchStatus::Cancelled);
    });

    it("403s when the requester's team-mate POSTs to accept", function () {
        Notification::fake();

        [, $match, $teamA] = p6LockedTeamMatch();
        app(RequestCancellationAction::class)->handle($teamA[0], $match);

        $this->actingAs($teamA[1])
            ->post(route('matches.cancellation.accept', $match))
            ->assertForbidden();

        expect($match->fresh()->status)->toBe(MatchStatus::Pending);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// 1v1 regression — existing chess flow still works
// ═══════════════════════════════════════════════════════════════════════════

describe('1v1 regression — chess cancellation still works', function () {
    it('refunds creator + taker via the original 1v1 path', function () {
        Notification::fake();
        platformUser();

        $creator = User::factory()->create();
        Wallet::deposit($creator, '500', reference: "test:p6-1v1:creator:{$creator->id}");

        $taker = User::factory()->create();
        Wallet::deposit($taker, '500', reference: "test:p6-1v1:taker:{$taker->id}");

        $listing = Listing::factory()->taken()->for($creator)->state([
            'stake_amount' => '100',
        ])->create();

        Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
        Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

        $match = GameMatch::factory()->create([
            'listing_id' => $listing->id,
            'taker_user_id' => $taker->id,
        ]);

        app(RequestCancellationAction::class)->handle($creator, $match);
        $result = app(AcceptCancellationAction::class)->handle($taker, $match);

        expect($result)->toBe('cancelled');
        expect((string) $creator->fresh()->usdt_balance)->toBe('500.000000');
        expect((string) $taker->fresh()->usdt_balance)->toBe('500.000000');

        // 1v1 keeps the legacy refs (creator/taker, not the team per-user pattern).
        $creatorRefund = WalletTransaction::query()
            ->where('reference_id', "cancel-refund-creator:{$match->id}")
            ->first();
        $takerRefund = WalletTransaction::query()
            ->where('reference_id', "cancel-refund-taker:{$match->id}")
            ->first();

        expect($creatorRefund)->not->toBeNull();
        expect($takerRefund)->not->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Slice C — Notification fan-out (M34 P6)
// ═══════════════════════════════════════════════════════════════════════════

describe('OpenDisputeAction notification fan-out (team)', function () {
    it('notifies every participant except the opener', function () {
        Notification::fake();

        [, $match, $teamA, $teamB] = p6LockedTeamMatch();
        $opener = $teamA[0];

        app(OpenDisputeAction::class)->handle($opener, $match);

        // Opener should NOT receive their own dispute notification.
        Notification::assertNotSentTo($opener, DisputeOpenedNotification::class);

        // The other 4 team-A players AND all 5 team-B players (9 total) receive it.
        foreach ([$teamA[1], $teamA[2], $teamA[3], $teamA[4], ...$teamB] as $recipient) {
            Notification::assertSentTo($recipient, DisputeOpenedNotification::class);
        }

        Notification::assertSentTimes(DisputeOpenedNotification::class, 9);
    });
});

describe('RequestCancellationAction notification fan-out (team)', function () {
    it('notifies only the opposing team — never the requester or their team-mates', function () {
        Notification::fake();

        [, $match, $teamA, $teamB] = p6LockedTeamMatch();
        $requester = $teamA[0];

        app(RequestCancellationAction::class)->handle($requester, $match);

        // Requester + 4 team-mates → no notification.
        foreach ($teamA as $sameTeam) {
            Notification::assertNotSentTo($sameTeam, CancellationRequestedNotification::class);
        }

        // All 5 opposing-team players → notified.
        foreach ($teamB as $opponent) {
            Notification::assertSentTo($opponent, CancellationRequestedNotification::class);
        }

        Notification::assertSentTimes(CancellationRequestedNotification::class, 5);
    });
});

describe('AcceptCancellationAction notification fan-out (team)', function () {
    it('notifies every participant except the accepter — refund signal goes to all', function () {
        Notification::fake();

        [, $match, $teamA, $teamB] = p6LockedTeamMatch();
        $requester = $teamA[0];
        $accepter = $teamB[0];

        app(RequestCancellationAction::class)->handle($requester, $match);

        // Discard the request-phase notifications — only Accept phase under
        // test here. (Notification::fake collects across the whole test;
        // re-fake to reset between Action runs.)
        Notification::fake();

        app(AcceptCancellationAction::class)->handle($accepter, $match);

        // Accepter should NOT receive their own "accepted" notification.
        Notification::assertNotSentTo($accepter, CancellationAcceptedNotification::class);

        // The other 9 (requester + 4 team-A-mates + 4 team-B-mates) receive it —
        // refund hit their wallet, they need the signal.
        foreach ([...$teamA, $teamB[1], $teamB[2], $teamB[3], $teamB[4]] as $recipient) {
            Notification::assertSentTo($recipient, CancellationAcceptedNotification::class);
        }

        Notification::assertSentTimes(CancellationAcceptedNotification::class, 9);
    });
});

describe('RejectCancellationAction notification (team) — unchanged from 1v1', function () {
    it('notifies only the original requester', function () {
        Notification::fake();

        [, $match, $teamA, $teamB] = p6LockedTeamMatch();
        $requester = $teamA[0];
        $rejecter = $teamB[0];

        app(RequestCancellationAction::class)->handle($requester, $match);
        Notification::fake(); // reset request-phase noise

        app(RejectCancellationAction::class)->handle($rejecter, $match);

        // Only the requester gets the reject notification (1v1 semantics
        // preserved — design Q#5 calls for requester-only on reject).
        Notification::assertSentTo($requester, CancellationRejectedNotification::class);

        foreach ([...$teamB, $teamA[1], $teamA[2], $teamA[3], $teamA[4]] as $other) {
            Notification::assertNotSentTo($other, CancellationRejectedNotification::class);
        }

        Notification::assertSentTimes(CancellationRejectedNotification::class, 1);
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Slice C — 1v1 notification regressions
// ═══════════════════════════════════════════════════════════════════════════

describe('1v1 notification regressions — fan-out helpers degrade to the original recipient set', function () {
    /**
     * Mirror of `cancellableMatch()` from `GameMatchCancellationTest.php`
     * so this file stays self-contained. Returns [creator, taker, match].
     */
    $build1v1 = function (): array {
        platformUser();

        $creator = User::factory()->create();
        Wallet::deposit($creator, '500', reference: "test:p6-1v1-notify:c:{$creator->id}");

        $taker = User::factory()->create();
        Wallet::deposit($taker, '500', reference: "test:p6-1v1-notify:t:{$taker->id}");

        $listing = Listing::factory()->taken()->for($creator)->state(['stake_amount' => '100'])->create();
        Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");
        Wallet::hold(user: $taker, amount: '100', listing: $listing, reference: "match-take:{$listing->id}");

        $match = GameMatch::factory()->create([
            'listing_id' => $listing->id,
            'taker_user_id' => $taker->id,
        ]);

        return [$creator, $taker, $match];
    };

    it('OpenDispute: notifies the single opponent, not the opener', function () use ($build1v1) {
        Notification::fake();
        [$creator, $taker, $match] = $build1v1();

        app(OpenDisputeAction::class)->handle($creator, $match);

        Notification::assertSentTo($taker, DisputeOpenedNotification::class);
        Notification::assertNotSentTo($creator, DisputeOpenedNotification::class);
        Notification::assertSentTimes(DisputeOpenedNotification::class, 1);
    });

    it('RequestCancellation: notifies the single opponent', function () use ($build1v1) {
        Notification::fake();
        [$creator, $taker, $match] = $build1v1();

        app(RequestCancellationAction::class)->handle($creator, $match);

        Notification::assertSentTo($taker, CancellationRequestedNotification::class);
        Notification::assertNotSentTo($creator, CancellationRequestedNotification::class);
        Notification::assertSentTimes(CancellationRequestedNotification::class, 1);
    });

    it('AcceptCancellation: notifies the original requester only', function () use ($build1v1) {
        Notification::fake();
        [$creator, $taker, $match] = $build1v1();

        app(RequestCancellationAction::class)->handle($creator, $match);
        Notification::fake();

        app(AcceptCancellationAction::class)->handle($taker, $match);

        Notification::assertSentTo($creator, CancellationAcceptedNotification::class);
        Notification::assertNotSentTo($taker, CancellationAcceptedNotification::class);
        Notification::assertSentTimes(CancellationAcceptedNotification::class, 1);
    });
});
