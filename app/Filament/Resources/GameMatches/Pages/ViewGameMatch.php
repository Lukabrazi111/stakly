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
 * M12 Phase 2 — admin match resolution page. Three header actions wired to
 * `AdminSettleToWinnerAction` / `AdminSettleDrawAction`. Each:
 *   - Requires a `reason` textarea (audit trail completeness).
 *   - Confirms before executing (`requiresConfirmation()`).
 *   - Hidden on terminal statuses (Settled / Cancelled) to avoid the wrong
 *     button on a match that's already resolved. The underlying Settle
 *     actions are also status-guarded + idempotent — UI visibility is the
 *     first defense, the action's own check is the second.
 *
 * Errors from the action (race condition where a second admin already
 * settled, or unexpected status) surface as a Filament danger notification
 * rather than crashing the page.
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

    /**
     * Opens the player-side match page in a new tab. Useful for verifying
     * what the players actually see (status banner copy, evidence prompts,
     * etc.) without losing your place in the admin review.
     *
     * Works on all statuses (including Settled / Cancelled) — admins can
     * audit a resolved match's final visible state.
     */
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
     * Shared resolve-and-notify wrapper. Catches the InvalidArgumentException
     * thrown by the underlying Settle actions on race-loss (e.g. another
     * admin settled this match a moment ago) and shows it as a danger toast
     * instead of crashing the page. After success, refreshes the record so
     * the page reflects the new status without a full reload.
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
