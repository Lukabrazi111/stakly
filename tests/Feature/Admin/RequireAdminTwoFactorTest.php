<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;

use function Pest\Laravel\actingAs;

/**
 * M30 Phase 3 — admin panel access requires 2FA enrollment. The middleware
 * fires only for users with the `admin` role; non-admins fall through to
 * Filament's own `canAccessPanel` 403.
 */
test('admin without 2FA is redirected to the security settings page', function () {
    $admin = User::factory()->admin()->create([
        'two_factor_confirmed_at' => null,
    ]);

    actingAs($admin)
        ->get('/admin')
        ->assertRedirect(route('security.edit'));
});

test('admin with 2FA enrolled reaches the admin panel', function () {
    // Factory default already stamps `two_factor_confirmed_at`, but assert
    // it explicitly here so the intent of this test is obvious.
    $admin = User::factory()->admin()->create([
        'two_factor_confirmed_at' => now(),
    ]);

    actingAs($admin)
        ->get('/admin')
        ->assertOk();
});

test('non-admin user without 2FA still gets the panel 403 instead of the 2FA redirect', function () {
    // Middleware short-circuit only fires for users WITH the admin role.
    // A regular user with no 2FA should still hit Filament's own
    // `canAccessPanel` 403 rather than being bounced to /settings/security.
    $regular = User::factory()->create(['two_factor_confirmed_at' => null]);

    actingAs($regular)
        ->get('/admin')
        ->assertForbidden();
});

test('admin without 2FA reaching the security page is bounced through password-confirm first', function () {
    // The redirect target itself is outside the /admin/* route group, so
    // the M30 P3 middleware doesn't gate it. Fortify's `password.confirm`
    // middleware does though (because `confirmPassword: true` in
    // config/fortify.php). The real flow: admin hits /admin → bounced to
    // /settings/security → bounced to /user/confirm-password → enters
    // password → lands on /settings/security → enrolls 2FA.
    $admin = User::factory()->admin()->create([
        'two_factor_confirmed_at' => null,
    ]);

    actingAs($admin)
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));
});

test('AdminUserSeeder stamps two_factor_confirmed_at so dev + CI dont trip the gate', function () {
    $this->seed(AdminUserSeeder::class);

    $admin = User::query()->where('email', 'admin@stakly.test')->first();

    expect($admin)->not->toBeNull();
    expect($admin->two_factor_confirmed_at)->not->toBeNull();
});
