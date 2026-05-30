<?php

namespace App\Filament\Resources\GameMatches\Pages;

use App\Actions\GameMatch\Admin\AdminSettleDrawAction;
use App\Actions\GameMatch\Admin\AdminSettleToWinnerAction;
use App\Enums\MatchAdminResolutionAction;
use App\Enums\MatchStatus;
use App\Filament\Resources\GameMatches\GameMatchResource;
use App\Models\GameMatch;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

/**
 * Admin match resolution. Settle buttons are hidden on terminal statuses,
 * but the underlying actions are status-guarded + idempotent too — UI is
 * the first defense, the action's own check is the second.
 */
class ViewGameMatch extends ViewRecord
{
    protected static string $resource = GameMatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->openAsParticipantAction(),
            $this->settleToCreatorAction(),
            $this->settleToTakerAction(),
            $this->settleDrawAction(),
        ];
    }

    private function openAsParticipantAction(): Action
    {
        return Action::make('open_as_participant')
            ->label('Open as participant')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->url(fn (GameMatch $record) => route('matches.show', $record))
            ->openUrlInNewTab();
    }

    private function settleToCreatorAction(): Action
    {
        return Action::make('settle_to_creator')
            ->label('Settle to creator')
            ->color('success')
            ->icon('heroicon-o-trophy')
            ->visible(fn (GameMatch $record) => $this->isResolvable($record))
            ->requiresConfirmation()
            ->modalHeading('Settle match in favor of creator')
            ->modalDescription(fn (GameMatch $record) => sprintf(
                'Pays the creator (@%s) the pot minus platform fee. Writes an audit row. This cannot be undone.',
                $record->listing?->user?->username ?? '—',
            ))
            ->modalSubmitActionLabel('Settle to creator')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. Lichess card confirms creator won — taker provided no counter-evidence.')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (GameMatch $record, array $data) => $this->resolve(
                fn () => app(AdminSettleToWinnerAction::class)->handle(
                    match: $record,
                    winner: $record->listing->user,
                    admin: auth()->user(),
                    action: MatchAdminResolutionAction::SettleToCreator,
                    reason: $data['reason'],
                ),
                successTitle: 'Match settled to creator.',
            ));
    }

    private function settleToTakerAction(): Action
    {
        return Action::make('settle_to_taker')
            ->label('Settle to taker')
            ->color('success')
            ->icon('heroicon-o-trophy')
            ->visible(fn (GameMatch $record) => $this->isResolvable($record))
            ->requiresConfirmation()
            ->modalHeading('Settle match in favor of taker')
            ->modalDescription(fn (GameMatch $record) => sprintf(
                'Pays the taker (@%s) the pot minus platform fee. Writes an audit row. This cannot be undone.',
                $record->taker?->username ?? '—',
            ))
            ->modalSubmitActionLabel('Settle to taker')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. chess.com card confirms taker won — creator’s screenshot was from a different game.')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (GameMatch $record, array $data) => $this->resolve(
                fn () => app(AdminSettleToWinnerAction::class)->handle(
                    match: $record,
                    winner: $record->taker,
                    admin: auth()->user(),
                    action: MatchAdminResolutionAction::SettleToTaker,
                    reason: $data['reason'],
                ),
                successTitle: 'Match settled to taker.',
            ));
    }

    private function settleDrawAction(): Action
    {
        return Action::make('settle_draw')
            ->label('Draw — refund both')
            ->color('warning')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (GameMatch $record) => $this->isResolvable($record))
            ->requiresConfirmation()
            ->modalHeading('Settle match as draw')
            ->modalDescription('Refunds both players their full stake. No platform fee charged. Writes an audit row. This cannot be undone.')
            ->modalSubmitActionLabel('Refund both')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. Evidence from both players is inconclusive; refunding both is fairer than picking a winner.')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (GameMatch $record, array $data) => $this->resolve(
                fn () => app(AdminSettleDrawAction::class)->handle(
                    match: $record,
                    admin: auth()->user(),
                    reason: $data['reason'],
                ),
                successTitle: 'Match settled as draw — both stakes refunded.',
            ));
    }

    private function isResolvable(GameMatch $record): bool
    {
        return in_array($record->status, [
            MatchStatus::Pending,
            MatchStatus::Disputed,
            MatchStatus::ManualReview,
        ], true);
    }

    /**
     * Catches race-loss exceptions (another admin settled this match a moment
     * ago) and surfaces them as a danger toast instead of crashing the page.
     */
    private function resolve(callable $callback, string $successTitle): void
    {
        try {
            $callback();

            $this->refreshFormData(['status', 'winner', 'settled_at']);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Could not resolve match')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
