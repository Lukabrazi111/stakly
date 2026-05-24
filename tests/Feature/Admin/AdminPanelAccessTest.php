<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

/**
 * M12 Phase 1 — Filament admin panel access gate. `canAccessPanel` on
 * the User model is the single source of truth: admin role required,
 * platform user blocked, regular users blocked.
 */

// ─── canAccessPanel (User model gate) ──────────────────────────────────────

test('user with admin role can access the admin panel', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

test('user without admin role cannot access the admin panel', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

test('platform user cannot access the admin panel even if granted the admin role', function () {
    // Defense in depth: the platform user holds the rake balance + must
    // never log in. The is_platform check fires before the role check
    // so even an accidental role grant can't bypass it.
    $platform = User::factory()->admin()->create(['is_platform' => true]);

    expect($platform->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

// ─── /admin route gating ───────────────────────────────────────────────────

test('guest visiting /admin is redirected to the admin login page', function () {
    $this->get('/admin')
        ->assertRedirect('/admin/login');
});

test('authenticated non-admin user gets 403 on /admin', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
});

test('authenticated admin user reaches /admin successfully', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk();
});

// ─── AdminUserSeeder ───────────────────────────────────────────────────────

test('AdminUserSeeder creates the admin role + first admin user', function () {
    $this->seed(AdminUserSeeder::class);

    $admin = User::query()->where('email', 'admin@stakly.test')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue();
});

test('AdminUserSeeder is idempotent — re-running does not duplicate users or roles', function () {
    $this->seed(AdminUserSeeder::class);
    $this->seed(AdminUserSeeder::class);

    expect(User::query()->where('email', 'admin@stakly.test')->count())->toBe(1);
    expect(Role::query()->where('name', 'admin')->count())->toBe(1);
});
