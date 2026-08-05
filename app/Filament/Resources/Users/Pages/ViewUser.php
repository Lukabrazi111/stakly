<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\KycStatus;
use App\Filament\Resources\Users\Actions\ImpersonateUserAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Models\UserModerationLog;
use App\Notifications\AccountBanned;
use App\Notifications\AccountRestored;
use App\Services\KycGate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
            $this->freezeToggleAction(),
            $this->kycStatusAction(),
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

    /**
     * Money-level freeze (M9 Phase 0b). Separate from the ban toggle because
     * they solve different problems: a ban removes product access, a freeze
     * stops money moving OUT while letting credits land, so a frozen player's
     * opponents can still be paid or refunded.
     *
     * This is also the per-payout hold we deliberately don't have — a single
     * ledger row's clearance can't be extended without an UPDATE on the
     * append-only ledger, so account-level freeze covers that case instead.
     */
    private function freezeToggleAction(): Action
    {
        return Action::make('toggle_freeze')
            ->label(fn (User $record): string => $record->isFrozen() ? 'Unfreeze funds' : 'Freeze funds')
            ->color(fn (User $record): string => $record->isFrozen() ? 'success' : 'warning')
            ->icon(fn (User $record): string => $record->isFrozen()
                ? 'heroicon-o-lock-open'
                : 'heroicon-o-lock-closed',
            )
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => $record->isFrozen()
                ? 'Unfreeze this user\'s funds?'
                : 'Freeze this user\'s funds?',
            )
            ->modalDescription(fn (User $record): string => $record->isFrozen()
                ? 'Restores withdrawals and staking. Their balance was never touched.'
                : 'Blocks withdrawals and new stakes. Deposits, refunds, and payouts still land, so any in-flight match can finish settling.',
            )
            ->modalSubmitActionLabel(fn (User $record): string => $record->isFrozen() ? 'Unfreeze' : 'Freeze')
            ->schema([
                Textarea::make('reason')
                    ->label(fn (User $record): string => $record->isFrozen()
                        ? 'Why lift the freeze?'
                        : 'Why freeze this user?',
                    )
                    ->placeholder(fn (User $record): string => $record->isFrozen()
                        ? 'e.g. chess.com confirmed the fair-play flag was cleared on appeal.'
                        : 'e.g. Opponent reported suspected engine use; awaiting chess.com review.',
                    )
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (User $record, array $data): void {
                $freezing = ! $record->isFrozen();

                DB::transaction(function () use ($record, $freezing, $data): void {
                    $record->forceFill([
                        'frozen_at' => $freezing ? now() : null,
                        'frozen_reason' => $freezing ? $data['reason'] : null,
                    ])->save();

                    UserModerationLog::create([
                        'user_id' => $record->id,
                        'admin_user_id' => auth()->id(),
                        'action' => $freezing
                            ? UserModerationLog::ACTION_FREEZE
                            : UserModerationLog::ACTION_UNFREEZE,
                        'reason' => $data['reason'],
                    ]);
                });

                Notification::make()
                    ->title($freezing ? 'Funds frozen.' : 'Freeze lifted.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Records an identity-verification outcome (M9 Phase 0c).
     *
     * Verification is deliberately admin-driven — there is no document-upload
     * flow, because picking a KYC vendor is a decision we haven't made. An
     * operator confirms out-of-band and records the result here.
     *
     * The action stays visible even while `stakly.kyc_enabled` is off (its
     * default) so a backlog can be worked through before the gate is switched
     * on; the notice below says so rather than hiding the control.
     */
    private function kycStatusAction(): Action
    {
        return Action::make('set_kyc_status')
            ->label('Set KYC status')
            ->color('gray')
            ->icon('heroicon-o-identification')
            ->modalHeading('Record identity verification')
            ->modalDescription(fn (): string => KycGate::enabled()
                ? 'Gates withdrawals above the configured volume threshold. Verified accounts are never gated.'
                : 'KYC is currently OFF platform-wide, so this gates nothing today. Recording it now is safe — it takes effect if the switch is turned on.',
            )
            ->modalSubmitActionLabel('Record')
            ->schema([
                Select::make('kyc_status')
                    ->label('Status')
                    ->options(KycStatus::class)
                    ->default(fn (User $record): string => $record->kyc_status->value)
                    ->required(),
                Textarea::make('reason')
                    ->label('Note')
                    ->placeholder('e.g. Passport + selfie received by email, matched account name.')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (User $record, array $data): void {
                $status = KycStatus::from($data['kyc_status']);

                DB::transaction(function () use ($record, $status, $data): void {
                    $record->forceFill([
                        'kyc_status' => $status,
                        'kyc_verified_at' => $status === KycStatus::Verified ? now() : null,
                        'kyc_note' => $data['reason'],
                    ])->save();

                    UserModerationLog::create([
                        'user_id' => $record->id,
                        'admin_user_id' => auth()->id(),
                        'action' => UserModerationLog::ACTION_KYC,
                        'reason' => "{$status->value}: {$data['reason']}",
                    ]);
                });

                Notification::make()
                    ->title("KYC status set to {$status->getLabel()}.")
                    ->success()
                    ->send();
            });
    }
}
