<?php

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $dataOverrides
 */
function makeNotif(
    User $user,
    string $type = 'App\\Notifications\\ListingTakenNotification',
    ?CarbonInterface $createdAt = null,
    ?CarbonInterface $readAt = null,
    array $dataOverrides = [],
): DatabaseNotification {
    $createdAt ??= now();

    $defaultData = [
        'event_type' => 'listing_taken',
        'title' => 'Your listing was taken!',
        'body' => 'Someone took your $100 match.',
        'action_url' => '/matches/1',
        'sound_priority' => 'urgent',
        'related_id' => 1,
    ];

    if (str_starts_with($type, 'Filament\\')) {
        unset($defaultData['event_type']);
        $defaultData['format'] = 'filament';
    }

    return DatabaseNotification::forceCreate([
        'id' => Str::uuid()->toString(),
        'type' => $type,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => array_merge($defaultData, $dataOverrides),
        'read_at' => $readAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

// ─── Auth-gating ────────────────────────────────────────────────────────────

test('endpoints require auth', function () {
    $this->get('/notifications')->assertRedirect();
    $this->getJson('/notifications/recent')->assertUnauthorized();
    $this->postJson('/notifications/seen')->assertUnauthorized();
    $this->postJson('/notifications/read-all')->assertUnauthorized();
    $this->postJson('/notifications/'.Str::uuid()->toString().'/read')->assertUnauthorized();
});

// ─── GET /notifications (Inertia page) ──────────────────────────────────────

test('index returns paginated player notifications for the current user', function () {
    $user = User::factory()->create();
    makeNotif($user);
    makeNotif($user);
    makeNotif($user);

    $this->actingAs($user)
        ->get('/notifications')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('notifications/index', false)
            ->has('notifications.data', 3)
            ->has('notifications.links')
            ->has('notifications.meta'));
});

test('index excludes Filament admin notifications', function () {
    $user = User::factory()->create();
    makeNotif($user, 'App\\Notifications\\ListingTakenNotification');
    makeNotif($user, 'Filament\\Notifications\\DatabaseNotification', dataOverrides: ['format' => 'filament']);

    $this->actingAs($user)
        ->get('/notifications')
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1));
});

test('index excludes other users notifications', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    makeNotif($user);
    makeNotif($other);

    $this->actingAs($user)
        ->get('/notifications')
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1));
});

// ─── GET /notifications/recent (dropdown JSON) ──────────────────────────────

test('recent returns up to 15 player notifications, newest first', function () {
    $user = User::factory()->create();
    Carbon::setTestNow('2026-06-01 12:00:00');
    foreach (range(0, 19) as $i) {
        makeNotif($user, createdAt: now()->subMinutes($i));
    }
    Carbon::setTestNow();

    $response = $this->actingAs($user)->getJson('/notifications/recent');
    $response->assertSuccessful();

    $data = $response->json('data');
    expect($data)->toHaveCount(15);

    $timestamps = array_column($data, 'created_at');
    expect($timestamps)->toBe(array_values(array_reverse(array_slice(array_reverse($timestamps), 0, 15))));
});

test('recent excludes admin notifications and other users', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    makeNotif($user);
    makeNotif($user, type: 'Filament\\Notifications\\DatabaseNotification');
    makeNotif($other);

    $response = $this->actingAs($user)->getJson('/notifications/recent');

    expect($response->json('data'))->toHaveCount(1);
});

test('recent payload exposes the player-facing fields', function () {
    $user = User::factory()->create();
    makeNotif($user, dataOverrides: [
        'event_type' => 'listing_taken',
        'title' => 'Bob took your listing',
        'body' => 'Match starting now',
        'action_url' => '/matches/42',
        'sound_priority' => 'urgent',
        'related_id' => 42,
    ]);

    $response = $this->actingAs($user)->getJson('/notifications/recent');

    $row = $response->json('data.0');
    expect($row['event_type'])->toBe('listing_taken')
        ->and($row['title'])->toBe('Bob took your listing')
        ->and($row['body'])->toBe('Match starting now')
        ->and($row['action_url'])->toBe('/matches/42')
        ->and($row['sound_priority'])->toBe('urgent')
        ->and($row['related_id'])->toBe(42)
        ->and($row['read_at'])->toBeNull()
        ->and($row['created_at'])->toBeString();
});

// ─── POST /notifications/seen (badge anchor) ────────────────────────────────

test('markSeen bumps notifications_last_seen_at to now and returns ISO timestamp', function () {
    $user = User::factory()->create(['notifications_last_seen_at' => null]);

    $response = $this->actingAs($user)->postJson('/notifications/seen');

    $response->assertSuccessful();
    expect($user->fresh()->notifications_last_seen_at)->not->toBeNull()
        ->and($response->json('notifications_last_seen_at'))->toBeString()
        ->and($response->json('notifications_last_seen_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T/');
});

// ─── POST /notifications/{id}/read (per-item) ───────────────────────────────

test('markRead sets read_at on the notification', function () {
    $user = User::factory()->create();
    $notif = makeNotif($user);

    $this->actingAs($user)
        ->postJson("/notifications/{$notif->id}/read")
        ->assertSuccessful();

    expect($notif->fresh()->read_at)->not->toBeNull();
});

test('markRead 404s for another user notification', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $notif = makeNotif($other);

    $this->actingAs($user)
        ->postJson("/notifications/{$notif->id}/read")
        ->assertNotFound();

    expect($notif->fresh()->read_at)->toBeNull();
});

test('markRead 404s for an admin notification on the same table', function () {
    $user = User::factory()->create();
    $adminNotif = makeNotif($user, type: 'Filament\\Notifications\\DatabaseNotification');

    $this->actingAs($user)
        ->postJson("/notifications/{$adminNotif->id}/read")
        ->assertNotFound();

    expect($adminNotif->fresh()->read_at)->toBeNull();
});

test('markRead is a no-op on an already-read notification', function () {
    $user = User::factory()->create();
    $original = now()->subHour()->startOfSecond();
    $notif = makeNotif($user, readAt: $original);

    $this->actingAs($user)
        ->postJson("/notifications/{$notif->id}/read")
        ->assertSuccessful();

    expect($notif->fresh()->read_at->equalTo($original))->toBeTrue();
});

// ─── POST /notifications/read-all ───────────────────────────────────────────

test('markAllRead marks every unread player notification as read', function () {
    $user = User::factory()->create();
    makeNotif($user);
    makeNotif($user);
    makeNotif($user, readAt: now()->subHour());

    $this->actingAs($user)
        ->postJson('/notifications/read-all')
        ->assertSuccessful();

    expect($user->playerNotifications()->whereNull('read_at')->count())->toBe(0);
});

test('markAllRead does not touch admin notifications', function () {
    $user = User::factory()->create();
    $adminNotif = makeNotif($user, type: 'Filament\\Notifications\\DatabaseNotification');
    makeNotif($user);

    $this->actingAs($user)->postJson('/notifications/read-all');

    expect($adminNotif->fresh()->read_at)->toBeNull();
});

test('markAllRead does not touch other users notifications', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherNotif = makeNotif($other);

    $this->actingAs($user)->postJson('/notifications/read-all');

    expect($otherNotif->fresh()->read_at)->toBeNull();
});

// ─── Inertia share (auth.user) ──────────────────────────────────────────────

test('unread count is notifications created after last_seen_at', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');
    $user = User::factory()->create(['notifications_last_seen_at' => now()->subHour()]);
    makeNotif($user, createdAt: now()->subMinutes(30));
    makeNotif($user, createdAt: now()->subMinutes(15));
    makeNotif($user, createdAt: now()->subHours(2));

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('auth.user.unread_notifications_count', 2));

    Carbon::setTestNow();
});

test('unread count treats null last_seen_at as epoch — counts everything', function () {
    $user = User::factory()->create(['notifications_last_seen_at' => null]);
    makeNotif($user);
    makeNotif($user);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('auth.user.unread_notifications_count', 2));
});

test('unread count excludes admin notifications', function () {
    $user = User::factory()->create(['notifications_last_seen_at' => null]);
    makeNotif($user);
    makeNotif($user, type: 'Filament\\Notifications\\DatabaseNotification');

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('auth.user.unread_notifications_count', 1));
});

test('notifications_last_seen_at shared as ISO8601 when set, null when unset', function () {
    $user = User::factory()->create(['notifications_last_seen_at' => null]);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('auth.user.notifications_last_seen_at', null));

    $user->forceFill(['notifications_last_seen_at' => now()])->save();

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.notifications_last_seen_at', fn ($v) => is_string($v) && str_contains($v, 'T')));
});
