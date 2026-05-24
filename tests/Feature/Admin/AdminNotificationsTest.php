<?php

use App\Actions\Admin\NotifyAdminsAction;
use App\Actions\GameMatch\OpenDisputeAction;
use App\Actions\GameMatch\ResolveMatchTimeoutAction;
use App\Enums\MatchStatus;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * M17 Phase 2 — admin notification dispatch. Covers:
 *   - Role scoping: only `admin` role users receive (regular users + platform
 *     user blocked).
 *   - Idempotency-adjacent: graceful no-op when the admin role doesn't exist
 *     in the DB yet (early seeder state, fresh test DBs).
 *   - Lifecycle hooks: `OpenDisputeAction` and `ResolveMatchTimeoutAction`
 *     both fire notifications, but only on success (no fire on race-loss).
 *   - Persistence: notifications land in the `notifications` table with the
 *     right title/body/URL.
 */

// ─── NotifyAdminsAction — role scoping ─────────────────────────────────────

test('notifies only users with the admin role', function () {
    $admin = User::factory()->admin()->create();
    $regularUser = User::factory()->create();
    $platformUser = User::factory()->admin()->create(['is_platform' => true]);

    app(NotifyAdminsAction::class)->handle(
        title: 'Test notification',
        body: 'body text',
        url: 'https://example.test/admin',
    );

    expect(DatabaseNotification::query()->where('notifiable_id', $admin->id)->exists())->toBeTrue();
    expect(DatabaseNotification::query()->where('notifiable_id', $regularUser->id)->exists())->toBeFalse();
    expect(DatabaseNotification::query()->where('notifiable_id', $platformUser->id)->exists())->toBeFalse();
});

test('no-ops gracefully when no admin role exists yet', function () {
    // Fresh DB, no admin role seeded — should not throw.
    User::factory()->create();

    app(NotifyAdminsAction::class)->handle(
        title: 'Test',
        body: 'body',
    );

    expect(DatabaseNotification::query()->count())->toBe(0);
});

test('persists notification with title, body, and action URL', function () {
    $admin = User::factory()->admin()->create();

    app(NotifyAdminsAction::class)->handle(
        title: 'Dispute opened',
        body: 'Alice vs Bob, $200 stake',
        url: 'https://stakly.test/admin/disputes/42',
        color: 'warning',
    );

    $notification = DatabaseNotification::query()->where('notifiable_id', $admin->id)->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['title'] ?? null)->toBe('Dispute opened');
    expect($notification->data['body'] ?? null)->toBe('Alice vs Bob, $200 stake');
});

// ─── OpenDisputeAction → notification fires on success ─────────────────────

test('opening a dispute fires admin notification with match details', function () {
    $admin = User::factory()->admin()->create();
    [$creator, , , $match] = pendingMatch();

    app(OpenDisputeAction::class)->handle($creator, $match);

    $notification = DatabaseNotification::query()
        ->where('notifiable_id', $admin->id)
        ->latest()
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['title'] ?? null)->toContain("Dispute opened — match #{$match->id}");
});

test('repeat openDispute on already-Disputed match does NOT fire a duplicate notification', function () {
    $admin = User::factory()->admin()->create();
    [$creator, , , $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Disputed, 'dispute_opened_at' => now()]);

    app(OpenDisputeAction::class)->handle($creator, $match);

    // Race-loss path: no new notification because the action returned false.
    expect(DatabaseNotification::query()->where('notifiable_id', $admin->id)->count())->toBe(0);
});

// ─── ResolveMatchTimeoutAction → notification fires on success ─────────────

test('timeout flip to ManualReview fires admin notification with match details', function () {
    $admin = User::factory()->admin()->create();
    [, , , $match] = pendingMatch();
    $match->forceFill(['created_at' => now()->subHours(6)])->save();

    app(ResolveMatchTimeoutAction::class)->handle($match->id, now()->subHours(4));

    $notification = DatabaseNotification::query()
        ->where('notifiable_id', $admin->id)
        ->latest()
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['title'] ?? null)->toContain("Match auto-flagged — #{$match->id}");
});

test('skipped timeout (already settled) does NOT fire notification', function () {
    $admin = User::factory()->admin()->create();
    [$creator, , $listing, $match] = pendingMatch();
    $match->update(['status' => MatchStatus::Settled, 'winner_user_id' => $creator->id, 'settled_at' => now()]);

    app(ResolveMatchTimeoutAction::class)->handle($match->id, now()->subHours(4));

    expect(DatabaseNotification::query()->where('notifiable_id', $admin->id)->count())->toBe(0);
});
