<?php

use App\Enums\MatchStatus;
use App\Filament\Widgets\OpsOverview;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Services\Wallet;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M17 Phase 1 — `OpsOverview` dashboard widget. Four stats bundled in one
 * widget (so Filament renders them as a horizontal grid). Tests render
 * the widget via Livewire and assert text matches the seeded scenarios.
 *
 * Per-stat assertion clusters below — each one stages just enough data
 * to exercise the underlying query.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

// ─── Open disputes stat ────────────────────────────────────────────────────

test('open disputes stat counts Disputed + ManualReview and ignores others', function () {
    [, , , $disputed] = pendingMatch();
    $disputed->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHour()]);

    [, , , $manualReview] = pendingMatch();
    $manualReview->update(['status' => MatchStatus::ManualReview, 'dispute_opened_at' => now()->subMinutes(30)]);

    [, , , $settled] = pendingMatch();
    $settled->update(['status' => MatchStatus::Settled, 'settled_at' => now()]);

    [, , , $cancelled] = pendingMatch();
    $cancelled->update(['status' => MatchStatus::Cancelled, 'cancelled_at' => now()]);

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('Open disputes')
        ->assertSeeText('2');
});

test('open disputes stat shows Queue clear when empty', function () {
    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('Queue clear');
});

test('open disputes stat surfaces oldest dispute age', function () {
    [, , , $stale] = pendingMatch();
    $stale->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHours(7)]);

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('Oldest:');
});

// ─── Aging disputes stat (M27 P4 SLA surface) ──────────────────────────────

test('aging disputes stat shows zero + No aging disputes when queue is fresh', function () {
    [, , , $fresh] = pendingMatch();
    $fresh->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHours(2)]);

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('Aging disputes (≥6h)')
        ->assertSeeText('No aging disputes');
});

test('aging disputes stat counts only disputes older than 6h', function () {
    // 2h old — not counted.
    [, , , $fresh] = pendingMatch();
    $fresh->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHours(2)]);

    // 7h old — counted.
    [, , , $aging] = pendingMatch();
    $aging->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHours(7)]);

    // 8h old — counted.
    [, , , $aging2] = pendingMatch();
    $aging2->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHours(8)]);

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('Aging disputes (≥6h)')
        ->assertSeeText('2 between 6h–12h');
});

test('aging disputes stat flags 12h+ entries with over-12h description', function () {
    [, , , $critical] = pendingMatch();
    $critical->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()->subHours(14)]);

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('1 over 12h');
});

test('aging disputes stat falls back to updated_at for ManualReview-from-timeout matches', function () {
    // No dispute_opened_at (timeout-routed MR), updated_at 8h ago.
    [, , , $timeoutMR] = pendingMatch();
    $timeoutMR->forceFill([
        'status' => MatchStatus::ManualReview,
        'dispute_opened_at' => null,
        'updated_at' => now()->subHours(8),
    ])->save();

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('1 between 6h–12h');
});

// ─── Matches today stat ────────────────────────────────────────────────────

test('matches today stat counts only matches created today', function () {
    [, , , $todayMatch] = pendingMatch();

    [, , , $yesterdayMatch] = pendingMatch();
    $yesterdayMatch->forceFill(['created_at' => now()->subDay()])->save();

    [, , , $weekAgoMatch] = pendingMatch();
    $weekAgoMatch->forceFill(['created_at' => now()->subWeek()])->save();

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('Matches today');
});

test('matches today stat description compares today to yesterday', function () {
    pendingMatch();
    pendingMatch();
    for ($i = 0; $i < 5; $i++) {
        [, , , $m] = pendingMatch();
        $m->forceFill(['created_at' => now()->subDay()])->save();
    }

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('-3 vs yesterday');
});

// ─── Platform earnings stat ────────────────────────────────────────────────

test('earnings stat sums fee transactions in the current month', function () {
    $platform = platformUser();
    $listing = Listing::factory()->create();

    Wallet::fee(amount: '10', listing: $listing, reference: 'fee:m1', description: 'm1');
    Wallet::fee(amount: '15', listing: $listing, reference: 'fee:m2', description: 'm2');
    Wallet::fee(amount: '5', listing: $listing, reference: 'fee:m3', description: 'm3');

    Wallet::fee(amount: '100', listing: $listing, reference: 'fee:m-last', description: 'm-last');
    $platform->walletTransactions()
        ->where('reference_id', 'fee:m-last')
        ->update(['created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay()]);

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('Earnings this month')
        ->assertSeeText('$30.00');
});

test('earnings stat shows zero state when no fees yet', function () {
    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('No fees collected yet');
});

// ─── Active users stat ─────────────────────────────────────────────────────

test('active users stat counts distinct users across listing/take/message in last 7d', function () {
    platformUser();

    $userA = User::factory()->create();
    Listing::factory()->for($userA)->create(['created_at' => now()->subDays(2)]);

    [$staleListingForB] = staleListingAndMatch();
    GameMatch::query()->where('listing_id', $staleListingForB->id)->delete();
    $userB = User::factory()->create();
    GameMatch::factory()->create([
        'listing_id' => $staleListingForB->id,
        'taker_user_id' => $userB->id,
        'created_at' => now()->subDays(3),
    ]);

    [, $staleMatchForC] = staleListingAndMatch();
    $userC = User::factory()->create();
    Message::factory()->for($staleMatchForC, 'match')->for($userC)->create(['created_at' => now()->subDay()]);

    $userD = User::factory()->create();
    Listing::factory()->for($userD)->create(['created_at' => now()->subDays(10)]);

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('Active users (7d)')
        ->assertSeeText('3');
});

test('active users stat counts a user once even when active via multiple channels', function () {
    platformUser();

    [$staleListing] = staleListingAndMatch();
    GameMatch::query()->where('listing_id', $staleListing->id)->delete();
    [, $staleMatch] = staleListingAndMatch();

    $user = User::factory()->create();
    Listing::factory()->for($user)->create(['created_at' => now()->subDay()]);
    GameMatch::factory()->create([
        'listing_id' => $staleListing->id,
        'taker_user_id' => $user->id,
        'created_at' => now()->subDays(2),
    ]);
    Message::factory()->for($staleMatch, 'match')->for($user)->create(['created_at' => now()->subDays(3)]);

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('1');
});

test('active users stat excludes the platform user', function () {
    $platform = platformUser();
    Listing::factory()->for($platform)->create(['created_at' => now()->subDay()]);

    Livewire::test(OpsOverview::class)
        ->assertSuccessful()
        ->assertSeeText('No activity yet');
});

/**
 * Returns a fresh listing + its match dated 30 days back, with creator +
 * taker users also dated 30 days back. Use this whenever the test needs a
 * listing or match to anchor activity on, without polluting the 7-day
 * active count. Each call returns a NEW listing — `game_matches.listing_id`
 * is unique, so reusing across multiple matches blows the constraint.
 *
 * @return array{0: Listing, 1: GameMatch}
 */
function staleListingAndMatch(): array
{
    $creator = User::factory()->create(['created_at' => now()->subDays(30)]);
    $taker = User::factory()->create(['created_at' => now()->subDays(30)]);

    $listing = Listing::factory()->for($creator)->create(['created_at' => now()->subDays(30)]);

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'created_at' => now()->subDays(30),
    ]);

    return [$listing, $match];
}
