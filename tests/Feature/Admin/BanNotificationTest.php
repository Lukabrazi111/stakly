<?php

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use App\Models\UserModerationLog;
use App\Notifications\AccountBanned;
use App\Notifications\AccountRestored;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * M30 Phase 4 — user-facing ban feedback: three-channel notification
 * (database + broadcast + mail) on ban + on unban, persistent in-app
 * banner driven off `auth.user.ban` shared data.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

// ─── Notification dispatch ──────────────────────────────────────────────────

test('banning a user dispatches AccountBanned to that user', function () {
    Notification::fake();

    $target = User::factory()->create(['banned_at' => null]);

    actingAs($this->admin);
    Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
        ->callAction('toggle_ban', data: ['reason' => 'Off-platform deal solicitation.'])
        ->assertHasNoActionErrors();

    Notification::assertSentTo(
        $target,
        AccountBanned::class,
        fn (AccountBanned $n) => $n->log->reason === 'Off-platform deal solicitation.'
            && $n->log->action === UserModerationLog::ACTION_BAN,
    );
});

test('unbanning a user dispatches AccountRestored to that user', function () {
    Notification::fake();

    $target = User::factory()->create(['banned_at' => now()->subDay()]);

    actingAs($this->admin);
    Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()])
        ->callAction('toggle_ban', data: ['reason' => 'False positive on chat regex.'])
        ->assertHasNoActionErrors();

    Notification::assertSentTo(
        $target,
        AccountRestored::class,
        fn (AccountRestored $n) => $n->log->action === UserModerationLog::ACTION_UNBAN,
    );

    Notification::assertNothingSentTo($this->admin);
});

// ─── Channel coverage ───────────────────────────────────────────────────────

test('AccountBanned fans out to database, broadcast, and mail', function () {
    $target = User::factory()->create(['banned_at' => now()]);
    $log = UserModerationLog::create([
        'user_id' => $target->id,
        'admin_user_id' => $this->admin->id,
        'action' => UserModerationLog::ACTION_BAN,
        'reason' => 'Test reason.',
    ]);

    $channels = (new AccountBanned($log))->via($target);

    expect($channels)->toEqualCanonicalizing(['database', 'broadcast', 'mail']);
});

test('AccountRestored fans out to database, broadcast, and mail', function () {
    $target = User::factory()->create();
    $log = UserModerationLog::create([
        'user_id' => $target->id,
        'admin_user_id' => $this->admin->id,
        'action' => UserModerationLog::ACTION_UNBAN,
        'reason' => 'Reviewed appeal.',
    ]);

    $channels = (new AccountRestored($log))->via($target);

    expect($channels)->toEqualCanonicalizing(['database', 'broadcast', 'mail']);
});

// ─── Mail payload ───────────────────────────────────────────────────────────

test('AccountBanned mail subject and body include the suspension reason', function () {
    $target = User::factory()->create(['banned_at' => now()]);
    $log = UserModerationLog::create([
        'user_id' => $target->id,
        'admin_user_id' => $this->admin->id,
        'action' => UserModerationLog::ACTION_BAN,
        'reason' => 'Repeated off-platform deal solicitation.',
    ]);

    $mail = (new AccountBanned($log))->toMail($target);

    expect($mail->subject)->toBe('Your Stakly account has been suspended');
    expect(collect($mail->introLines)->implode("\n"))
        ->toContain('Repeated off-platform deal solicitation.');
});

test('AccountRestored mail subject is the restored-account heading', function () {
    $target = User::factory()->create();
    $log = UserModerationLog::create([
        'user_id' => $target->id,
        'admin_user_id' => $this->admin->id,
        'action' => UserModerationLog::ACTION_UNBAN,
        'reason' => 'Reviewed appeal.',
    ]);

    $mail = (new AccountRestored($log))->toMail($target);

    expect($mail->subject)->toBe('Your Stakly account has been restored');
});

// ─── Inertia share: auth.user.ban ──────────────────────────────────────────

test('Inertia auth.user.ban carries the latest ban reason for a banned user', function () {
    $target = User::factory()->create(['banned_at' => now()]);
    UserModerationLog::create([
        'user_id' => $target->id,
        'admin_user_id' => $this->admin->id,
        'action' => UserModerationLog::ACTION_BAN,
        'reason' => 'The current active ban reason.',
    ]);

    actingAs($target);
    $response = get(route('home'));

    $response->assertInertia(
        fn ($page) => $page
            ->where('auth.user.ban.reason', 'The current active ban reason.')
            ->where('auth.user.ban.banned_at', fn ($value) => is_string($value)),
    );
});

test('Inertia auth.user.ban is null for a not-banned user', function () {
    $user = User::factory()->create(['banned_at' => null]);

    actingAs($user);
    $response = get(route('home'));

    $response->assertInertia(
        fn ($page) => $page->where('auth.user.ban', null),
    );
});

test('Inertia auth.user.ban reads the most recent ban after a ban-unban-ban chain', function () {
    $target = User::factory()->create(['banned_at' => now()]);

    UserModerationLog::create([
        'user_id' => $target->id,
        'admin_user_id' => $this->admin->id,
        'action' => UserModerationLog::ACTION_BAN,
        'reason' => 'First ban reason.',
    ]);
    UserModerationLog::create([
        'user_id' => $target->id,
        'admin_user_id' => $this->admin->id,
        'action' => UserModerationLog::ACTION_UNBAN,
        'reason' => 'Lifted after appeal.',
    ]);
    UserModerationLog::create([
        'user_id' => $target->id,
        'admin_user_id' => $this->admin->id,
        'action' => UserModerationLog::ACTION_BAN,
        'reason' => 'Second ban reason.',
    ]);

    actingAs($target);
    $response = get(route('home'));

    $response->assertInertia(
        fn ($page) => $page->where('auth.user.ban.reason', 'Second ban reason.'),
    );
});
