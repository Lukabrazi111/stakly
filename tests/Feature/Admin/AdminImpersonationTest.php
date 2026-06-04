<?php

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\AdminImpersonation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use STS\FilamentImpersonate\Facades\Impersonation;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * M30 Phase 5 — Filament impersonation action, audit-row persistence,
 * Stakly banner, 30-min expiry middleware.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

function impersonationActionOn(User $target)
{
    return Livewire::test(ViewUser::class, ['record' => $target->getRouteKey()]);
}

// ─── Action visibility ─────────────────────────────────────────────────────

test('impersonate action is hidden for the platform user', function () {
    $platform = User::factory()->create(['is_platform' => true]);

    impersonationActionOn($platform)->assertActionHidden('impersonate');
});

test('impersonate action is hidden for banned users', function () {
    $banned = User::factory()->create(['banned_at' => now()]);

    impersonationActionOn($banned)->assertActionHidden('impersonate');
});

test('impersonate action is hidden on the admin\'s own page', function () {
    impersonationActionOn($this->admin)->assertActionHidden('impersonate');
});

test('impersonate action is visible for a normal target', function () {
    $target = User::factory()->create();

    impersonationActionOn($target)->assertActionVisible('impersonate');
});

// ─── Validation ────────────────────────────────────────────────────────────

test('impersonate fails without a reason', function () {
    $target = User::factory()->create();

    impersonationActionOn($target)
        ->callAction('impersonate', data: ['current_password' => 'password'])
        ->assertHasActionErrors(['reason']);

    expect(AdminImpersonation::count())->toBe(0);
    expect(Impersonation::isImpersonating())->toBeFalse();
});

test('impersonate fails without a password', function () {
    $target = User::factory()->create();

    impersonationActionOn($target)
        ->callAction('impersonate', data: ['reason' => 'investigating'])
        ->assertHasActionErrors(['current_password']);

    expect(AdminImpersonation::count())->toBe(0);
});

test('impersonate fails with the wrong password', function () {
    $target = User::factory()->create();

    impersonationActionOn($target)
        ->callAction('impersonate', data: [
            'current_password' => 'not-the-real-password',
            'reason' => 'investigating',
        ])
        ->assertHasActionErrors(['current_password']);

    expect(AdminImpersonation::count())->toBe(0);
});

// ─── Successful start ──────────────────────────────────────────────────────

test('impersonate writes an audit row and swaps auth to the target', function () {
    $target = User::factory()->create();

    impersonationActionOn($target)
        ->callAction('impersonate', data: [
            'current_password' => 'password',
            'reason' => 'Investigating wallet history bug.',
        ])
        ->assertHasNoActionErrors();

    expect(Impersonation::isImpersonating())->toBeTrue();
    expect(auth()->id())->toBe($target->id);

    $row = AdminImpersonation::query()->first();
    expect($row)->not->toBeNull();
    expect($row->admin_user_id)->toBe($this->admin->id);
    expect($row->target_user_id)->toBe($target->id);
    expect($row->reason)->toBe('Investigating wallet history bug.');
    expect($row->started_at)->not->toBeNull();
    expect($row->ended_at)->toBeNull();
    expect($row->ip_address)->not->toBeNull();
});

// ─── Banner ────────────────────────────────────────────────────────────────

test('Stakly banner renders on a public page while impersonating', function () {
    $target = User::factory()->create(['username' => 'alice-impersonated']);

    impersonationActionOn($target)
        ->callAction('impersonate', data: [
            'current_password' => 'password',
            'reason' => 'investigating',
        ]);

    get('/')
        ->assertSeeText('Viewing as')
        ->assertSeeText('@alice-impersonated');
});

test('Stakly banner is absent when not impersonating', function () {
    get('/')->assertDontSeeText('Viewing as');
});

// ─── Exit ──────────────────────────────────────────────────────────────────

test('leave route closes the audit row and restores the admin', function () {
    $target = User::factory()->create();

    impersonationActionOn($target)
        ->callAction('impersonate', data: [
            'current_password' => 'password',
            'reason' => 'investigating',
        ]);

    expect(Impersonation::isImpersonating())->toBeTrue();

    get(route('filament-impersonate.leave'))->assertRedirect();

    expect(Impersonation::isImpersonating())->toBeFalse();
    expect(auth()->id())->toBe($this->admin->id);

    $row = AdminImpersonation::query()->first();
    expect($row->ended_at)->not->toBeNull();
});

// ─── 30-min expiry ─────────────────────────────────────────────────────────

test('expiry middleware ends impersonation after 30 minutes', function () {
    $target = User::factory()->create();

    impersonationActionOn($target)
        ->callAction('impersonate', data: [
            'current_password' => 'password',
            'reason' => 'investigating',
        ]);

    expect(Impersonation::isImpersonating())->toBeTrue();

    Carbon::setTestNow(now()->addMinutes(31));

    get('/');

    expect(Impersonation::isImpersonating())->toBeFalse();
    expect(auth()->id())->toBe($this->admin->id);

    $row = AdminImpersonation::query()->first();
    expect($row->ended_at)->not->toBeNull();

    Carbon::setTestNow();
});

test('expiry middleware does not end impersonation before 30 minutes', function () {
    $target = User::factory()->create();

    impersonationActionOn($target)
        ->callAction('impersonate', data: [
            'current_password' => 'password',
            'reason' => 'investigating',
        ]);

    Carbon::setTestNow(now()->addMinutes(29));

    get('/');

    expect(Impersonation::isImpersonating())->toBeTrue();
    expect(AdminImpersonation::query()->first()->ended_at)->toBeNull();

    Carbon::setTestNow();
});
