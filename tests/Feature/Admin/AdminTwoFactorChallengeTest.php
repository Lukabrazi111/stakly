<?php

use App\Filament\MultiFactor\FortifyAppAuthentication;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/**
 * M30 Phase 6 — admin 2FA challenge on every login through Filament's
 * built-in MFA flow. Bridges to Fortify's existing TOTP secret + recovery
 * codes via `FortifyAppAuthentication`. No custom login page / controller
 * / Inertia surface; Filament's `Login::authenticate()` orchestrates the
 * challenge form when our provider's `isEnabled()` returns true.
 */
function freshTotpFor(User $user): string
{
    return (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));
}

function adminWithRealTwoFactor(): User
{
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    return User::factory()->admin()->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['valid-recovery-code'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

// ─── Provider isEnabled ─────────────────────────────────────────────────────

test('isEnabled returns true when the user has confirmed 2FA', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

    expect(FortifyAppAuthentication::make()->isEnabled($user))->toBeTrue();
});

test('isEnabled returns false when the user has not confirmed 2FA', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => null]);

    expect(FortifyAppAuthentication::make()->isEnabled($user))->toBeFalse();
});

// ─── Filament login MFA flow ───────────────────────────────────────────────

test('admin with 2FA hits the multi-factor challenge before authenticating', function () {
    $admin = adminWithRealTwoFactor();

    Livewire::test(Login::class)
        ->set('data.email', $admin->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->check())->toBeFalse();
});

test('admin with 2FA completes login when the correct TOTP code is supplied', function () {
    $admin = adminWithRealTwoFactor();

    Livewire::test(Login::class)
        ->set('data.email', $admin->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->set('data.multiFactor.fortify-app.code', freshTotpFor($admin))
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($admin->id);
});

test('admin with 2FA stays on the challenge when an invalid TOTP code is supplied', function () {
    $admin = adminWithRealTwoFactor();

    Livewire::test(Login::class)
        ->set('data.email', $admin->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->set('data.multiFactor.fortify-app.code', '000000')
        ->call('authenticate')
        ->assertHasErrors();

    expect(auth()->check())->toBeFalse();
});

test('admin with 2FA completes login via a valid recovery code and consumes it', function () {
    $admin = adminWithRealTwoFactor();

    Livewire::test(Login::class)
        ->set('data.email', $admin->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->set('data.multiFactor.fortify-app.useRecoveryCode', true)
        ->set('data.multiFactor.fortify-app.recoveryCode', 'valid-recovery-code')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($admin->id);
    expect($admin->refresh()->recoveryCodes())->not->toContain('valid-recovery-code');
});

test('admin with 2FA cannot reuse a recovery code with a wrong recovery code', function () {
    $admin = adminWithRealTwoFactor();

    Livewire::test(Login::class)
        ->set('data.email', $admin->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->set('data.multiFactor.fortify-app.useRecoveryCode', true)
        ->set('data.multiFactor.fortify-app.recoveryCode', 'wrong-code')
        ->call('authenticate')
        ->assertHasErrors();

    expect(auth()->check())->toBeFalse();
});

test('admin without 2FA skips the MFA challenge', function () {
    $admin = User::factory()->admin()->create([
        'two_factor_confirmed_at' => null,
    ]);

    Livewire::test(Login::class)
        ->set('data.email', $admin->email)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($admin->id);
});
