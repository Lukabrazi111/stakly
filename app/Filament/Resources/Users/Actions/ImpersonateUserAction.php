<?php

namespace App\Filament\Resources\Users\Actions;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use STS\FilamentImpersonate\Actions\Impersonate;

/**
 * Stakly impersonation action. Extends the upstream package action with two
 * modal fields the upstream skips — current-password re-entry and a free-text
 * reason. Reason is stashed in session via `before()` so the
 * `EnterImpersonation` listener can persist it onto the audit row.
 *
 * The package's own `canImpersonate()` already blocks: re-impersonation,
 * self, soft-deleted, and delegates to `User::canImpersonate()` (admin gate)
 * + `User::canBeImpersonated()` (platform + banned gate). We don't add
 * `visible(...)` here — the package's chain is sufficient.
 */
class ImpersonateUserAction extends Impersonate
{
    public static function getDefaultName(): ?string
    {
        return 'impersonate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Impersonate user')
            ->color('danger')
            ->icon('heroicon-o-finger-print')
            ->modalHeading('Impersonate this user?')
            ->modalDescription('You will be signed in as this user across the public site. A persistent banner shows the session and links back to your admin account. Auto-expires after 30 minutes. The reason is recorded in the audit log.')
            ->modalSubmitActionLabel('Impersonate')
            ->schema([
                TextInput::make('current_password')
                    ->label('Your admin password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule('current_password')
                    ->autocomplete('current-password'),
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. Investigating Alice\'s wallet-history bug.')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->before(function (array $data): void {
                session()->put('impersonate.reason', $data['reason']);
            });
    }
}
