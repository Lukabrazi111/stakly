<?php

use App\Models\Listing;
use App\Models\User;
use App\Notifications\CancellationRequestedNotification;
use App\Notifications\ListingExpiredNotification;
use App\Notifications\MatchSettledNotification;
use App\Notifications\PlayerNotification;
use Illuminate\Support\Facades\DB;

function defaultPayload(): array
{
    $payload = ['preferences' => [], 'notification_sound' => 'classic'];

    foreach (PlayerNotification::CONFIGURABLE_EVENT_TYPES as $eventType) {
        $payload['preferences'][$eventType] = [
            'in_app' => true,
            'sound' => false,
            'email' => true,
        ];
    }

    return $payload;
}

// ─── Controller: edit ───────────────────────────────────────────────────────

test('edit page requires auth', function () {
    $this->get('/settings/notifications')->assertRedirect();
});

test('edit returns merged preferences for configurable events', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/notifications')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('settings/notifications', false)
            ->has('preferences', 5)
            ->where('preferences.listing_taken.in_app', true)
            ->where('preferences.listing_taken.sound', true)
            ->where('preferences.match_settled.sound', false)
            ->has('mandatoryEventTypes')
            ->has('configurableEventTypes', 5)
            ->has('soundChoices', 4)
            ->where('notificationSound', 'classic'));
});

test('edit returns DB-stored values over defaults', function () {
    $user = User::factory()->create();
    $user->notificationPreferences()->create([
        'event_type' => 'listing_taken',
        'in_app' => false,
        'sound' => false,
        'email' => false,
    ]);

    $this->actingAs($user)
        ->get('/settings/notifications')
        ->assertInertia(fn ($page) => $page
            ->where('preferences.listing_taken.in_app', false)
            ->where('preferences.listing_taken.sound', false));
});

test('edit returns saved sound choice when set', function () {
    $user = User::factory()->create(['notification_sound' => 'soft']);

    $this->actingAs($user)
        ->get('/settings/notifications')
        ->assertInertia(fn ($page) => $page->where('notificationSound', 'soft'));
});

// ─── Controller: update ─────────────────────────────────────────────────────

test('update upserts preferences and saves sound choice', function () {
    $user = User::factory()->create();

    $payload = defaultPayload();
    $payload['preferences']['listing_taken'] = [
        'in_app' => false,
        'sound' => false,
        'email' => false,
    ];
    $payload['notification_sound'] = 'ding';

    $this->actingAs($user)
        ->patch('/settings/notifications', $payload)
        ->assertRedirect();

    expect($user->fresh()->notification_sound)->toBe('ding');
    expect($user->getNotificationPreference('listing_taken')['in_app'])->toBeFalse();
    expect($user->getNotificationPreference('listing_taken')['sound'])->toBeFalse();
});

test('update forces mandatory in_app to true regardless of input', function () {
    $user = User::factory()->create();

    $payload = defaultPayload();
    foreach (PlayerNotification::CONFIGURABLE_EVENT_TYPES as $eventType) {
        $payload['preferences'][$eventType] = [
            'in_app' => false,
            'sound' => false,
            'email' => false,
        ];
    }

    $this->actingAs($user)->patch('/settings/notifications', $payload);

    expect($user->getNotificationPreference('match_settled')['in_app'])->toBeTrue();
    expect($user->getNotificationPreference('cancellation_requested')['in_app'])->toBeTrue();
});

test('update ignores non-configurable event types', function () {
    $user = User::factory()->create();

    $payload = defaultPayload();
    $payload['preferences']['listing_expired'] = [
        'in_app' => false,
        'sound' => false,
        'email' => false,
    ];

    $this->actingAs($user)->patch('/settings/notifications', $payload)->assertRedirect();

    expect($user->notificationPreferences()->where('event_type', 'listing_expired')->exists())->toBeFalse();
});

test('update rejects invalid sound choice', function () {
    $user = User::factory()->create();

    $payload = defaultPayload();
    $payload['notification_sound'] = 'unknown_sound';

    $this->actingAs($user)
        ->patch('/settings/notifications', $payload)
        ->assertSessionHasErrors('notification_sound');
});

// ─── Inertia share ──────────────────────────────────────────────────────────

test('shares notification_sound_map for the per-event sound check', function () {
    $user = User::factory()->create();
    $user->notificationPreferences()->create([
        'event_type' => 'listing_taken',
        'in_app' => true,
        'sound' => false,
        'email' => true,
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.notification_sound_map.listing_taken', false)
            ->where('auth.user.notification_sound_map.match_settled', false));
});

// ─── Enforcement: PlayerNotification::via() ─────────────────────────────────

test('muted optional event does not insert a database row', function () {
    $user = User::factory()->create();
    $user->notificationPreferences()->create([
        'event_type' => 'listing_taken',
        'in_app' => false,
        'sound' => false,
        'email' => false,
    ]);

    $listing = Listing::factory()->for($user)->create();
    $user->notify(new ListingExpiredNotification($listing));

    // listing_expired has no preference row → fires by default.
    expect(DB::table('notifications')
        ->where('notifiable_id', $user->id)
        ->count())->toBe(1);
});

test('mandatory event bypasses an in_app=false preference', function () {
    [$creator, $taker, $listing, $match] = pendingMatch();

    $creator->notificationPreferences()->create([
        'event_type' => 'match_settled',
        'in_app' => false,
        'sound' => false,
        'email' => false,
    ]);

    $creator->notify(new MatchSettledNotification($match, $creator, 'won', '85'));

    expect(DB::table('notifications')
        ->where('notifiable_id', $creator->id)
        ->where('type', MatchSettledNotification::class)
        ->count())->toBe(1);
});

test('default preferences allow notification dispatch', function () {
    $user = User::factory()->create();
    $listing = Listing::factory()->for($user)->create();

    $user->notify(new ListingExpiredNotification($listing));

    expect(DB::table('notifications')
        ->where('notifiable_id', $user->id)
        ->where('type', ListingExpiredNotification::class)
        ->count())->toBe(1);
});

test('mandatory cancellation event bypasses muted preference', function () {
    [$creator, $taker] = [User::factory()->create(), User::factory()->create()];

    $creator->notificationPreferences()->create([
        'event_type' => 'cancellation_requested',
        'in_app' => false,
        'sound' => false,
        'email' => false,
    ]);

    [, , , $match] = pendingMatch();

    $creator->notify(new CancellationRequestedNotification($match, $taker, 'changed my mind'));

    expect(DB::table('notifications')
        ->where('notifiable_id', $creator->id)
        ->where('type', CancellationRequestedNotification::class)
        ->count())->toBe(1);
});
