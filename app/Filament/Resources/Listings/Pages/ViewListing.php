<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Actions\Listing\CancelListingAction;
use App\Actions\Lobby\Admin\ForceCancelTeamLobbyAction;
use App\Enums\ListingStatus;
use App\Filament\Resources\Listings\ListingResource;
use App\Models\Listing;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Admin listing view. One header action: Force cancel, visible only on
 * Open listings, routed through `CancelListingAction` so escrow releases
 * via `Wallet::release` and the ledger invariant holds. Admin never writes
 * `users.usdt_balance` directly from this surface.
 */
class ViewListing extends ViewRecord
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->forceCancelAction(),
            $this->forceCancelTeamLobbyAction(),
        ];
    }

    private function forceCancelAction(): Action
    {
        return Action::make('force_cancel')
            ->label('Force cancel')
            ->color('danger')
            ->icon('heroicon-o-no-symbol')
            // 1v1 only — team lobbies route through `forceCancelTeamLobbyAction`
            // (CancelListingAction mis-refunds a team lobby's pooled escrow).
            ->visible(fn (Listing $record): bool => $record->status === ListingStatus::Open && ! $record->isTeamPlay())
            ->requiresConfirmation()
            ->modalHeading('Force cancel this listing?')
            ->modalDescription(fn (Listing $record): string => sprintf(
                'Listing #%d · $%s stake · @%s. The stake is refunded to the creator via Wallet::release and the listing moves to Cancelled. This cannot be undone.',
                $record->id,
                number_format((float) $record->stake_amount, 2),
                $record->user?->username ?? 'unknown',
            ))
            ->modalSubmitActionLabel('Force cancel')
            ->action(function (Listing $record): void {
                // Defense in depth — re-check the gate inside the callback in
                // case the action was triggered from a stale page where the
                // listing has since been Taken or Cancelled. The visibility
                // gate above is the primary guard; this is the belt.
                if ($record->status !== ListingStatus::Open || $record->isTeamPlay()) {
                    Notification::make()
                        ->title('Listing is no longer Open.')
                        ->danger()
                        ->send();

                    return;
                }

                app(CancelListingAction::class)->handle($record);

                Notification::make()
                    ->title('Listing cancelled.')
                    ->body('Stake refunded to the creator.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Team-play force-cancel. Refunds every Ready player's pooled escrow,
     * clears the roster, and cancels the lobby + paired match — the lobby-aware
     * path the 1v1 `CancelListingAction` can't safely do.
     */
    private function forceCancelTeamLobbyAction(): Action
    {
        return Action::make('force_cancel_team')
            ->label('Force cancel lobby')
            ->color('danger')
            ->icon('heroicon-o-no-symbol')
            ->visible(fn (Listing $record): bool => $record->status === ListingStatus::Open && $record->isTeamPlay())
            ->requiresConfirmation()
            ->modalHeading('Force cancel this team lobby?')
            ->modalDescription(fn (Listing $record): string => sprintf(
                'Listing #%d · %dv%d · $%s per player. Every Ready player\'s stake is refunded via Wallet::release, the roster is cleared, and the lobby + match move to Cancelled. This cannot be undone.',
                $record->id,
                $record->team_size,
                $record->team_size,
                number_format((float) $record->stake_amount, 2),
            ))
            ->modalSubmitActionLabel('Force cancel lobby')
            ->action(function (Listing $record): void {
                if ($record->status !== ListingStatus::Open || ! $record->isTeamPlay()) {
                    Notification::make()
                        ->title('Lobby is no longer cancellable.')
                        ->danger()
                        ->send();

                    return;
                }

                $result = app(ForceCancelTeamLobbyAction::class)->handle($record);

                if ($result !== 'cancelled') {
                    Notification::make()
                        ->title('Lobby is no longer cancellable.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Team lobby cancelled.')
                    ->body('Ready players refunded; roster cleared.')
                    ->success()
                    ->send();
            });
    }
}
