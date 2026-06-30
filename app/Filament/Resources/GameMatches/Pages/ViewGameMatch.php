<?php

namespace App\Filament\Resources\GameMatches\Pages;

use App\Actions\GameMatch\Admin\AdminSettleDrawAction;
use App\Actions\GameMatch\Admin\AdminSettleToWinnerAction;
use App\Enums\MatchAdminResolutionAction;
use App\Enums\MatchStatus;
use App\Filament\Resources\GameMatches\GameMatchResource;
use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Admin match resolution. Settle buttons are hidden on terminal statuses,
 * but the underlying actions are status-guarded + idempotent too — UI is
 * the first defense, the action's own check is the second.
 */
class ViewGameMatch extends ViewRecord
{
    protected static string $resource = GameMatchResource::class;

    /**
     * Eager-load everything the infolist renders (roster, snapshots, money,
     * resolution + auto-fetch summaries) on the View page only — the dispute
     * list query stays lean. Without this the infolist fires ~8 lazy/N+1
     * queries per open (per-player accounts, exists()+get() pairs, roster).
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'listing.user',
            'listing.lobbyParticipants.user',
            'taker',
            'winner',
            'disputeOpener',
            'providerSnapshots',
            'adminResolutions.admin',
            'adminResolutions.winner',
            'autoFetchAttempts',
        ]);
    }

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();
        $isTeamPlay = $record->listing?->isTeamPlay() ?? false;

        if ($isTeamPlay) {
            return [
                $this->openAsParticipantAction(),
                $this->settleToTeamAAction(),
                $this->settleToTeamBAction(),
                $this->settleDrawAction(),
            ];
        }

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

    /**
     * Team-aware "Team A wins" button. Re-uses the existing
     * `SettleToCreator` enum because creator-side IS Team A in our
     * data model (`creator_side = 'a'` on every team-play listing,
     * enforced at create time by `CreateTeamPlayListingAction`).
     *
     * The action receives a representative winner user — the slot-0
     * (creator) side-A participant — and `AdminSettleToWinnerAction`
     * derives the full winning roster from there.
     */
    private function settleToTeamAAction(): Action
    {
        return Action::make('settle_team_a')
            ->label('Settle to Team A')
            ->color('success')
            ->icon('heroicon-o-trophy')
            ->visible(fn (GameMatch $record) => $this->isResolvable($record))
            ->requiresConfirmation()
            ->modalHeading('Settle match in favor of Team A')
            ->modalDescription(
                'Pays every Team A player their share of the pot minus '
                .'platform fee. Writes an audit row. This cannot be undone.'
            )
            ->modalSubmitActionLabel('Settle to Team A')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. FACEIT match record confirms Team A won 16-13; Team B dispute reason did not hold up.')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (GameMatch $record, array $data) => $this->resolve(
                fn () => app(AdminSettleToWinnerAction::class)->handle(
                    match: $record,
                    winner: $this->teamSideRepresentative($record, 'a'),
                    admin: auth()->user(),
                    action: MatchAdminResolutionAction::SettleToCreator,
                    reason: $data['reason'],
                ),
                successTitle: 'Match settled — Team A wins.',
            ));
    }

    private function settleToTeamBAction(): Action
    {
        return Action::make('settle_team_b')
            ->label('Settle to Team B')
            ->color('success')
            ->icon('heroicon-o-trophy')
            ->visible(fn (GameMatch $record) => $this->isResolvable($record))
            ->requiresConfirmation()
            ->modalHeading('Settle match in favor of Team B')
            ->modalDescription(
                'Pays every Team B player their share of the pot minus '
                .'platform fee. Writes an audit row. This cannot be undone.'
            )
            ->modalSubmitActionLabel('Settle to Team B')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. FACEIT match record confirms Team B won 16-9; Team A dispute reason did not hold up.')
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(fn (GameMatch $record, array $data) => $this->resolve(
                fn () => app(AdminSettleToWinnerAction::class)->handle(
                    match: $record,
                    winner: $this->teamSideRepresentative($record, 'b'),
                    admin: auth()->user(),
                    action: MatchAdminResolutionAction::SettleToTaker,
                    reason: $data['reason'],
                ),
                successTitle: 'Match settled — Team B wins.',
            ));
    }

    /**
     * Pick one live participant from the given side to seed the
     * settlement. `AdminSettleToWinnerAction` derives the full roster
     * from this user's `lobby_participants` side, so any live
     * participant works — we pick slot 0 for consistency with how
     * `SettleTeamMatchAction` orders winners (slot 0 receives the
     * BCMath truncation remainder).
     */
    private function teamSideRepresentative(GameMatch $record, string $side): User
    {
        $participant = LobbyParticipant::query()
            ->where('listing_id', $record->listing_id)
            ->where('side', $side)
            ->whereNull('kicked_at')
            ->orderBy('slot_index')
            ->with('user')
            ->firstOrFail();

        return $participant->user;
    }

    private function settleDrawAction(): Action
    {
        return Action::make('settle_draw')
            ->label(fn (GameMatch $record) => $record->listing?->isTeamPlay()
                ? 'Draw — refund all'
                : 'Draw — refund both')
            ->color('warning')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (GameMatch $record) => $this->isResolvable($record))
            ->requiresConfirmation()
            ->modalHeading('Settle match as draw')
            ->modalDescription(fn (GameMatch $record) => $record->listing?->isTeamPlay()
                ? 'Refunds every player their full stake. No platform fee charged. Writes an audit row. This cannot be undone.'
                : 'Refunds both players their full stake. No platform fee charged. Writes an audit row. This cannot be undone.')
            ->modalSubmitActionLabel(fn (GameMatch $record) => $record->listing?->isTeamPlay()
                ? 'Refund all'
                : 'Refund both')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. Evidence from both sides is inconclusive; refunding everyone is fairer than picking a winner.')
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
                successTitle: 'Match settled as draw — stakes refunded.',
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
