<?php

use App\Actions\GameMatch\AcceptCancellationAction;
use App\Actions\GameMatch\Admin\AdminSettleDrawAction;
use App\Actions\GameMatch\Admin\AdminSettleToWinnerAction;
use App\Actions\GameMatch\OpenDisputeAction;
use App\Actions\GameMatch\RejectCancellationAction;
use App\Actions\GameMatch\RequestCancellationAction;
use App\Actions\GameMatch\ResolveDisputeAction;
use App\Actions\GameMatch\ResolveMatchTimeoutAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\GameMatch\TakeListingAction;
use App\Actions\Listing\ExpireListingAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchAdminResolutionAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use App\Notifications\CancellationAcceptedNotification;
use App\Notifications\CancellationRejectedNotification;
use App\Notifications\CancellationRequestedNotification;
use App\Notifications\DisputeOpenedNotification;
use App\Notifications\DisputeResolvedNotification;
use App\Notifications\ListingExpiredNotification;
use App\Notifications\ListingTakenNotification;
use App\Notifications\MatchManualReviewNotification;
use App\Notifications\MatchSettledNotification;
use App\Services\Wallet;
use Illuminate\Support\Facades\Notification;

/**
 * Build a Pending match with both providers' Lichess snapshots populated so
 * SettleFromCardAction can resolve the card's winner. Mirrors the local
 * helper in SettleFromCardActionTest.
 */
function notificationMatchWithLichessSnapshots(string $stake = '100'): array
{
    [$creator, $taker, $listing, $match] = pendingMatch($stake);

    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_CREATOR,
        'provider' => LinkedAccountProvider::Lichess,
        'username' => 'alice-lichess',
    ]);
    MatchProviderSnapshot::create([
        'match_id' => $match->id,
        'side' => GameMatch::SIDE_TAKER,
        'provider' => LinkedAccountProvider::Lichess,
        'username' => 'bob-lichess',
    ]);

    return [$creator, $taker, $listing, $match->fresh(['listing.user', 'taker', 'providerSnapshots'])];
}

function notificationLichessCard(array $overrides = []): array
{
    return array_merge([
        'type' => 'game_card',
        'provider' => 'lichess',
        'source' => 'auto_fetch',
        'game_id' => 'abcdefgh',
        'url' => 'https://lichess.org/abcdefgh',
        'verified' => true,
        'white_username' => 'alice-lichess',
        'black_username' => 'bob-lichess',
        'winner_color' => 'white',
        'winner_username' => 'alice-lichess',
        'status' => 'mate',
        'speed' => 'blitz',
        'variant' => 'standard',
        'rated' => true,
        'played_at' => '2026-05-22T12:00:00+00:00',
    ], $overrides);
}

// ─── ListingTaken ───────────────────────────────────────────────────────────

test('TakeListingAction notifies the listing creator with ListingTakenNotification', function () {
    Notification::fake();

    $creator = User::factory()->active()->withLichess()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $listing = Listing::factory()->open()->forLichess()->for($creator)->state([
        'stake_amount' => '100',
    ])->create();
    Wallet::hold($creator, '100', listing: $listing, reference: "listing-create:{$listing->id}");

    $taker = User::factory()->withLichess()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    app(TakeListingAction::class)->handle($taker, $listing);

    Notification::assertSentTo($creator, ListingTakenNotification::class);
    Notification::assertNotSentTo($taker, ListingTakenNotification::class);
});

// ─── MatchSettled (3 dispatch sites) ────────────────────────────────────────

test('SettleFromCardAction notifies both players with MatchSettledNotification on winner card', function () {
    Notification::fake();
    [$creator, $taker, , $match] = notificationMatchWithLichessSnapshots();

    app(SettleFromCardAction::class)->handle($match, notificationLichessCard());

    Notification::assertSentTo($creator, MatchSettledNotification::class);
    Notification::assertSentTo($taker, MatchSettledNotification::class);
});

test('SettleFromCardAction notifies both players with MatchSettledNotification on draw card', function () {
    Notification::fake();
    [$creator, $taker, , $match] = notificationMatchWithLichessSnapshots();

    app(SettleFromCardAction::class)->handle($match, notificationLichessCard([
        'winner_color' => null,
        'winner_username' => null,
    ]));

    Notification::assertSentTo($creator, MatchSettledNotification::class);
    Notification::assertSentTo($taker, MatchSettledNotification::class);
});

test('AdminSettleToWinnerAction notifies both players with MatchSettledNotification', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::ManualReview]);
    $admin = User::factory()->create();

    app(AdminSettleToWinnerAction::class)->handle(
        $match,
        $creator,
        $admin,
        MatchAdminResolutionAction::SettleToCreator,
        'test resolution',
    );

    Notification::assertSentTo($creator, MatchSettledNotification::class);
    Notification::assertSentTo($taker, MatchSettledNotification::class);
});

test('AdminSettleDrawAction notifies both players with MatchSettledNotification', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::ManualReview]);
    $admin = User::factory()->create();

    app(AdminSettleDrawAction::class)->handle($match, $admin, 'test draw');

    Notification::assertSentTo($creator, MatchSettledNotification::class);
    Notification::assertSentTo($taker, MatchSettledNotification::class);
});

// ─── DisputeResolved + ManualReview (ResolveDisputeAction branches) ─────────

test('ResolveDisputeAction notifies both players with DisputeResolvedNotification on Confirmed', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);
    mockGameApi()->forceWinner($creator->id);

    app(ResolveDisputeAction::class)->handle($match);

    Notification::assertSentTo($creator, DisputeResolvedNotification::class);
    Notification::assertSentTo($taker, DisputeResolvedNotification::class);
});

test('ResolveDisputeAction notifies both players with DisputeResolvedNotification on Drawn', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);
    mockGameApi()->forceDraw();

    app(ResolveDisputeAction::class)->handle($match);

    Notification::assertSentTo($creator, DisputeResolvedNotification::class);
    Notification::assertSentTo($taker, DisputeResolvedNotification::class);
});

test('ResolveDisputeAction notifies both players with MatchManualReviewNotification on Unknown', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);
    mockGameApi()->forceUnknown();

    app(ResolveDisputeAction::class)->handle($match);

    Notification::assertSentTo($creator, MatchManualReviewNotification::class);
    Notification::assertSentTo($taker, MatchManualReviewNotification::class);
});

// ─── ManualReview on timeout ────────────────────────────────────────────────

test('ResolveMatchTimeoutAction notifies both players with MatchManualReviewNotification', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();

    $outcome = app(ResolveMatchTimeoutAction::class)->handle($match->id, now()->addHour());

    expect($outcome)->toBe('manual-review');
    Notification::assertSentTo($creator, MatchManualReviewNotification::class);
    Notification::assertSentTo($taker, MatchManualReviewNotification::class);
});

// ─── DisputeOpened ──────────────────────────────────────────────────────────

test('OpenDisputeAction notifies only the opponent, not the opener', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();

    app(OpenDisputeAction::class)->handle($creator, $match, 'opponent claims they won but the game shows me winning');

    Notification::assertSentTo($taker, DisputeOpenedNotification::class);
    Notification::assertNotSentTo($creator, DisputeOpenedNotification::class);
});

// ─── Cancellation flow ──────────────────────────────────────────────────────

test('RequestCancellationAction notifies only the opponent of the requester', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();

    app(RequestCancellationAction::class)->handle($creator, $match);

    Notification::assertSentTo($taker, CancellationRequestedNotification::class);
    Notification::assertNotSentTo($creator, CancellationRequestedNotification::class);
});

test('AcceptCancellationAction notifies only the original requester', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();
    app(RequestCancellationAction::class)->handle($creator, $match);

    app(AcceptCancellationAction::class)->handle($taker, $match->fresh());

    Notification::assertSentTo($creator, CancellationAcceptedNotification::class);
    Notification::assertNotSentTo($taker, CancellationAcceptedNotification::class);
});

test('RejectCancellationAction notifies only the original requester', function () {
    Notification::fake();
    [$creator, $taker, , $match] = pendingMatch();
    app(RequestCancellationAction::class)->handle($creator, $match);

    app(RejectCancellationAction::class)->handle($taker, $match->fresh());

    Notification::assertSentTo($creator, CancellationRejectedNotification::class);
    Notification::assertNotSentTo($taker, CancellationRejectedNotification::class);
});

// ─── ListingExpired ─────────────────────────────────────────────────────────

test('ExpireListingAction notifies the listing creator with ListingExpiredNotification', function () {
    Notification::fake();
    $creator = User::factory()->active()->withLichess()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $listing = Listing::factory()->open()->forLichess()->for($creator)->state([
        'stake_amount' => '100',
        'expires_at' => now()->subMinute(),
    ])->create();
    Wallet::hold($creator, '100', listing: $listing, reference: "listing-create:{$listing->id}");

    $result = app(ExpireListingAction::class)->handle($listing->id);

    expect($result)->toBeTrue();
    Notification::assertSentTo($creator, ListingExpiredNotification::class);
});

// ─── Sound policy ──────────────────────────────────────────────────────────
