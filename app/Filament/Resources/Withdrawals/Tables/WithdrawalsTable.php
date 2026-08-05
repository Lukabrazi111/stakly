<?php

namespace App\Filament\Resources\Withdrawals\Tables;

use App\Enums\WithdrawalStatus;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\WithdrawalAddressCooldown;
use App\Services\Withdrawals;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Withdrawal queue (M9 Phase 0b). Newest first.
 *
 * The only mutating action is Reject, and it's available only while the
 * withdrawal is still non-terminal — once the money has left custody there's
 * nothing to reverse from our side.
 */
class WithdrawalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('WD #')
                    ->sortable(),

                TextColumn::make('user.username')
                    ->label('User')
                    ->searchable()
                    ->url(fn (Withdrawal $record): ?string => $record->user
                        ? route('filament.admin.resources.users.view', $record->user)
                        : null,
                    ),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Gross')
                    ->alignRight()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => WalletTransaction::formatAmount((string) $state)),

                TextColumn::make('platform_fee')
                    ->label('Margin')
                    ->alignRight()
                    ->formatStateUsing(fn ($state): string => WalletTransaction::formatAmount((string) $state))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('network_fee')
                    ->label('Gas')
                    ->alignRight()
                    ->formatStateUsing(fn ($state): ?string => $state === null
                        ? null
                        : WalletTransaction::formatAmount((string) $state),
                    )
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('destination_address')
                    ->label('Destination')
                    ->limit(18)
                    ->copyable()
                    ->copyMessage('Address copied')
                    ->searchable(),

                TextColumn::make('tx_hash')
                    ->label('Tx hash')
                    ->limit(18)
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('rejected_reason')
                    ->label('Reason')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Requested')
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('M j, Y H:i:s'))
                    ->sortable(),

                // New-address cooldown (M9 Phase 0e). Surfaced so a long-Pending
                // row reads as "waiting by design" rather than stuck — and so
                // an operator handling an "I didn't request this" report can see
                // at a glance whether there's still time to Reject before it sends.
                TextColumn::make('hold_until')
                    ->label('Held until')
                    ->badge()
                    ->color('warning')
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('M j, Y H:i:s'))
                    ->placeholder('—')
                    ->visible(fn (): bool => WithdrawalAddressCooldown::hours() > 0)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(WithdrawalStatus::class),

                SelectFilter::make('user_id')
                    ->label('User')
                    ->relationship('user', 'username')
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reject withdrawal')
                    ->modalDescription(fn (Withdrawal $record): string => sprintf(
                        'Withdrawal #%d · %s to %s. The full gross is credited back to @%s and no platform margin is booked. This cannot be undone.',
                        $record->id,
                        WalletTransaction::formatAmount((string) $record->amount),
                        $record->destination_address,
                        $record->user?->username ?? 'unknown',
                    ))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Shown to the player on their withdrawals page.'),
                    ])
                    // Hidden once terminal: the money either already left or was
                    // already credited back, so there is nothing left to reverse.
                    ->visible(fn (Withdrawal $record): bool => ! $record->status->isTerminal())
                    ->action(function (Withdrawal $record, array $data): void {
                        Withdrawals::reject($record, $data['reason'], auth()->user());

                        Notification::make()
                            ->title('Withdrawal rejected')
                            ->body('The full amount has been credited back to the player.')
                            ->success()
                            ->send();
                    }),

                // M9 Phase 0f — the resolution levers for a payout the provider
                // already took. `ProcessWithdrawal::failed()` deliberately does
                // NOT auto-reverse once `provider_payout_id` is set, because the
                // funds may already be on-chain; it logs `critical` and leaves
                // the row here. Until now Reject was the only action available,
                // which credits the full gross back — i.e. a manual double-pay
                // if the send actually landed. These two make the operator's
                // real decision expressible.
                //
                // Both are restricted to rows the provider has seen; for
                // anything else Reject is already the correct and only tool.
                Action::make('mark_completed')
                    ->label('Mark completed')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirm the payout landed')
                    ->modalDescription(fn (Withdrawal $record): string => sprintf(
                        'Withdrawal #%d · %s to %s. Books the %s platform margin and closes the row. Only do this once you have confirmed the transaction on the provider dashboard or a block explorer — it does NOT send anything.',
                        $record->id,
                        WalletTransaction::formatAmount((string) $record->amount),
                        $record->destination_address,
                        WalletTransaction::formatAmount((string) $record->platform_fee),
                    ))
                    ->schema([
                        TextInput::make('tx_hash')
                            ->label('Transaction hash')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Recorded on the row and shown to the player.'),
                    ])
                    ->visible(fn (Withdrawal $record): bool => ! $record->status->isTerminal()
                        && $record->provider_payout_id !== null,
                    )
                    ->action(function (Withdrawal $record, array $data): void {
                        Withdrawals::markCompleted($record, $data['tx_hash']);

                        Notification::make()
                            ->title('Withdrawal marked completed')
                            ->body('Margin booked and the transaction hash recorded.')
                            ->success()
                            ->send();
                    }),

                Action::make('force_reverse')
                    ->label('Force reverse')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reverse a payout the provider already has')
                    ->modalDescription(fn (Withdrawal $record): string => sprintf(
                        'DANGER: withdrawal #%d was accepted by the provider (payout %s). Credits %s back to @%s. If the payout DID reach the chain this pays the player twice. Only proceed once you have confirmed with the provider that it will never settle.',
                        $record->id,
                        $record->provider_payout_id ?? 'unknown',
                        WalletTransaction::formatAmount((string) $record->amount),
                        $record->user?->username ?? 'unknown',
                    ))
                    ->schema([
                        Textarea::make('reason')
                            ->label('How was this confirmed?')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Shown to the player, and the audit trail for a decision that can double-pay.'),
                    ])
                    ->visible(fn (Withdrawal $record): bool => ! $record->status->isTerminal()
                        && $record->provider_payout_id !== null,
                    )
                    ->action(function (Withdrawal $record, array $data): void {
                        Withdrawals::markFailed(
                            $record,
                            'Provider confirmed the payout will not settle: '.$data['reason'],
                        );

                        Notification::make()
                            ->title('Withdrawal reversed')
                            ->body('The full amount has been credited back to the player.')
                            ->warning()
                            ->send();
                    }),
            ]);
    }
}
