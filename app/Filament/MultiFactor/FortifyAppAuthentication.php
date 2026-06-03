<?php

namespace App\Filament\MultiFactor;

use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticationProvider as FortifyProvider;
use SensitiveParameter;

/**
 * M30 Phase 6 — bridges Filament's built-in multi-factor login challenge
 * to Fortify's existing 2FA setup. Filament's `Login::authenticate()`
 * iterates registered providers; when `isEnabled()` returns true for the
 * authenticating user, the login form swaps to the challenge form and
 * `getChallengeFormComponents()` runs the per-code validation rules.
 *
 * No new columns, no plugin, no custom Inertia page — Fortify still owns
 * 2FA setup at `/settings/security`, this only adds the per-login challenge
 * to admin logins through `/admin/login`.
 */
class FortifyAppAuthentication implements MultiFactorAuthenticationProvider
{
    public function __construct(
        protected FortifyProvider $fortify,
    ) {}

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'fortify-app';
    }

    public function getLoginFormLabel(): string
    {
        return __('Authentication code');
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return $user->two_factor_confirmed_at !== null;
    }

    /**
     * No management UI inside Filament — admins enroll / disable 2FA at
     * the Fortify-backed `/settings/security` page, same as every other
     * user.
     *
     * @return array<int, never>
     */
    public function getManagementSchemaComponents(): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        return [
            OneTimeCodeInput::make('code')
                ->label(__('Authentication code'))
                ->belowContent(fn (Get $get): Action => Action::make('useRecoveryCode')
                    ->label(__('Use a recovery code'))
                    ->link()
                    ->action(fn (Set $set) => $set('useRecoveryCode', true))
                    ->visible(fn (): bool => ! $get('useRecoveryCode')))
                ->required(fn (Get $get): bool => blank($get('recoveryCode')))
                ->rule($this->codeRule($user)),

            TextInput::make('recoveryCode')
                ->label(__('Recovery code'))
                ->password()
                ->revealable()
                ->rule($this->recoveryRule($user))
                ->visible(fn (Get $get): bool => (bool) $get('useRecoveryCode'))
                ->live(onBlur: true),
        ];
    }

    private function codeRule(Authenticatable $user): Closure
    {
        return function () use ($user): Closure {
            return function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                if ($user->two_factor_secret === null) {
                    $fail(__('Two-factor authentication is not enabled for this account.'));

                    return;
                }

                // Fortify stores `two_factor_secret` encrypted via its own
                // encrypter (no model cast) — mirror its `verify()` callsite
                // in `ConfirmTwoFactorAuthentication` exactly so we don't
                // hand Google2FA a base64 blob.
                $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

                if ($this->fortify->verify($secret, $value)) {
                    return;
                }

                $fail(__('The authentication code is invalid.'));
            };
        };
    }

    private function recoveryRule(Authenticatable $user): Closure
    {
        return function () use ($user): Closure {
            return function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                $codes = $user->recoveryCodes();

                if (! in_array($value, $codes, true)) {
                    $fail(__('The recovery code is invalid.'));

                    return;
                }

                $user->replaceRecoveryCode($value);
            };
        };
    }
}
