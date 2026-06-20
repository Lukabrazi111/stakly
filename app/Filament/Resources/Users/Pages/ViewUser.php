<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\ImpersonateUserAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Models\UserModerationLog;
use App\Notifications\AccountBanned;
use App\Notifications\AccountRestored;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

/**
 * Admin user view page. Header actions: View as visitor (public profile in
 * new tab), Manual email verify, Reset 2FA, Ban toggle (reason required in
 * both directions, writes a `user_moderation_logs` row), and Impersonate
 * (password + reason required, writes an `admin_impersonations` row via the
 * `EnterImpersonation` listener).
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->viewAsVisitorAction(),
            $this->verifyEmailAction(),
            $this->resetTwoFactorAction(),
            $this->banToggleAction(),
            ImpersonateUserAction::make()->record($this->getRecord()),
        ];
    }

    private function viewAsVisitorAction(): Action
    {
        return Action::make('view_as_visitor')
            ->label('View as visitor')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->url(fn (User $record): string => route('users.show', $record))
            ->openUrlInNewTab();
    }

    private function verifyEmailAction(): Action
    {
        return Action::make('verify_email')
            ->label('Verify email')
            ->color('info')
            ->icon('heroicon-o-envelope-open')
            ->visible(fn (User $record): bool => $record->email_verified_at === null)
            ->requiresConfirmation()
            ->modalHeading('Verify this user\'s email?')
            ->modalDescription('Marks the email as verified. Use only when the user has lost inbox access or the original verification link expired.')
            ->modalSubmitActionLabel('Mark verified')
            ->action(function (User $record): void {
                $record->forceFill(['email_verified_at' => now()])->save();

                Notification::make()
                    ->title('Email verified.')
                    ->success()
                    ->send();
            });
    }

    private function resetTwoFactorAction(): Action
    {
        return Action::make('reset_2fa')
            ->label('Reset 2FA')
            ->color('warning')
            ->icon('heroicon-o-key')
            ->visible(fn (User $record): bool => $record->two_factor_secret !== null
                || $record->two_factor_confirmed_at !== null,
            )
            ->requiresConfirmation()
            ->modalHeading('Reset 2FA for this user?')
            ->modalDescription('Clears the user\'s authenticator + recovery codes. They will re-enroll on next login. Use only when the user has lost their device.')
            ->modalSubmitActionLabel('Reset 2FA')
            ->action(function (User $record): void {
                $record->forceFill([
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ])->save();

                Notification::make()
                    ->title('2FA reset.')
                    ->success()
                    ->send();
            });
    }

    private function banToggleAction(): Action
    {
        return Action::make('toggle_ban')
            ->label(fn (User $record): string => $record->isBanned() ? 'Unban user' : 'Ban user')
            ->color(fn (User $record): string => $record->isBanned() ? 'success' : 'danger')
            ->icon(fn (User $record): string => $record->isBanned()
                ? 'heroicon-o-check-circle'
                : 'heroicon-o-no-symbol',
            )
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => $record->isBanned()
                ? 'Lift the ban on this user?'
                : 'Ban this user?',
            )
            ->modalDescription(fn (User $record): string => $record->isBanned()
                ? 'Restores create-listing, profile-update, username-change, and marketplace visibility.'
                : 'Blocks create-listing, profile-update, username-change, and hides their listings from the marketplace.',
            )
            ->modalSubmitActionLabel(fn (User $record): string => $record->isBanned() ? 'Unban' : 'Ban')
            ->schema([
                Textarea::make('reason')
                    ->label(fn (User $record): string => $record->isBanned()
                        ? 'Why lift the ban?'
                        : 'Why ban this user?',
                    )
                    ->placeholder(fn (User $record): string => $record->isBanned()
                        ? 'e.g. Reviewed support ticket — the initial ban was a false positive on the chat regex flag.'
                        : 'e.g. Repeated off-platform deal solicitation in chat after warning.',
                    )
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (User $record, array $data): void {
                $newState = $record->isBanned() ? null : now();
                $action = $record->isBanned()
                    ? UserModerationLog::ACTION_UNBAN
                    : UserModerationLog::ACTION_BAN;

                $log = DB::transaction(function () use ($record, $newState, $action, $data): UserModerationLog {
                    $record->forceFill(['banned_at' => $newState])->save();

                    return UserModerationLog::create([
                        'user_id' => $record->id,
                        'admin_user_id' => auth()->id(),
                        'action' => $action,
                        'reason' => $data['reason'],
                    ]);
                });

                $record->notify($action === UserModerationLog::ACTION_BAN
                    ? new AccountBanned($log)
                    : new AccountRestored($log),
                );

                Notification::make()
                    ->title($action === UserModerationLog::ACTION_BAN
                        ? 'User banned.'
                        : 'Ban lifted.',
                    )
                    ->success()
                    ->send();
            });
    }
}
