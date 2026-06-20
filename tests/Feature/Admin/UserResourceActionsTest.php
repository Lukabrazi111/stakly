<?php

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use App\Models\UserModerationLog;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * M30 Phase 2 — `ViewUser` header actions: View as visitor, Manual email
 * verify, Reset 2FA, Ban toggle. Each persists the right state + writes
 * the right audit row.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

function viewUserPage(User $target)
{
    return Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()]);
}

// ─── Manual email verify ───────────────────────────────────────────────────

test('verify_email action marks the user as verified', function () {
    $target = User::factory()->unverified()->create();

    viewUserPage($target)
        ->callAction('verify_email')
        ->assertHasNoActionErrors();

    expect($target->refresh()->email_verified_at)->not->toBeNull();
});

test('verify_email action is hidden when the user is already verified', function () {
    $target = User::factory()->create(['email_verified_at' => now()]);

    viewUserPage($target)
        ->assertActionHidden('verify_email');
});

// ─── Reset 2FA ─────────────────────────────────────────────────────────────

test('reset_2fa action clears all 2FA columns', function () {
    $target = User::factory()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ]);

    viewUserPage($target)
        ->callAction('reset_2fa')
        ->assertHasNoActionErrors();

    $target->refresh();
    expect($target->two_factor_secret)->toBeNull();
    expect($target->two_factor_recovery_codes)->toBeNull();
    expect($target->two_factor_confirmed_at)->toBeNull();
});

test('reset_2fa action is hidden when the user has no 2FA enrolled', function () {
    $target = User::factory()->create([
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);

    viewUserPage($target)
        ->assertActionHidden('reset_2fa');
});

// ─── Ban toggle ────────────────────────────────────────────────────────────

test('toggle_ban with no reason fails validation', function () {
    $target = User::factory()->create();

    viewUserPage($target)
        ->callAction('toggle_ban', data: [])
        ->assertHasActionErrors(['reason']);

    expect($target->refresh()->banned_at)->toBeNull();
});

test('toggle_ban from active flips to banned + writes a ban log row', function () {
    $target = User::factory()->create(['banned_at' => null]);

    viewUserPage($target)
        ->callAction('toggle_ban', data: ['reason' => 'Off-platform deal solicitation in chat.'])
        ->assertHasNoActionErrors();

    expect($target->refresh()->banned_at)->not->toBeNull();

    $log = UserModerationLog::query()->where('user_id', $target->id)->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe(UserModerationLog::ACTION_BAN);
    expect($log->admin_user_id)->toBe($this->admin->id);
    expect($log->reason)->toBe('Off-platform deal solicitation in chat.');
});

test('toggle_ban from banned flips to active + writes an unban log row', function () {
    $target = User::factory()->create(['banned_at' => now()->subDay()]);

    viewUserPage($target)
        ->callAction('toggle_ban', data: ['reason' => 'False positive on the chat regex flag.'])
        ->assertHasNoActionErrors();

    expect($target->refresh()->banned_at)->toBeNull();

    $log = UserModerationLog::query()->where('user_id', $target->id)->first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe(UserModerationLog::ACTION_UNBAN);
});
