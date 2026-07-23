<?php

namespace App\Filament\Resources\GameMatches\Tables;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dispute queue table. Default filter pre-selects Disputed + ManualReview
 * (the actual queue); admin can widen the filter for context lookups.
 */
class GameMatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'listing.user',
                'taker',
                'winner',
            ]))
            // Latest matches at the top — standard admin browsing default.
            // SLA cues live in the Age column's color badge + the OpsOverview
            // "Aging disputes" stat, not in the row ordering.
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Match #')
                    ->sortable(),

                TextColumn::make('listing.user.username')
                    ->label('Creator')
                    ->searchable()
                    ->url(fn ($record) => $record->listing?->user
                        ? route('users.show', $record->listing->user->username)
                        : null,
                        shouldOpenInNewTab: true),

                TextColumn::make('listing.team_size')
                    ->label('Format')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => (int) $state > 1 ? $state.'v'.$state : '1v1')
                    ->sortable(),

                // Team matches have no single taker — show the format instead of
                // a blank cell (which reads as an untaken 1v1).
                TextColumn::make('taker.username')
                    ->label('Taker / opponent')
                    ->state(fn (GameMatch $record): string => ($record->listing?->isTeamPlay() ?? false)
                        ? 'Team '.$record->listing->team_size.'v'.$record->listing->team_size
                        : ($record->taker?->username !== null ? '@'.$record->taker->username : '—'),
                    )
                    ->url(fn (GameMatch $record) => (! ($record->listing?->isTeamPlay() ?? false) && $record->taker)
                        ? route('users.show', $record->taker->username)
                        : null,
                        shouldOpenInNewTab: true)
                    ->searchable(),

                TextColumn::make('listing.stake_amount')
                    ->label('Stake / player')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 2).' USDT')
                    ->sortable(),

                TextColumn::make('pot')
                    ->label('Pot')
                    // Per-player stake × full headcount (team_size × 2); the true
                    // escrow at stake, not the per-player figure.
                    ->state(fn (GameMatch $record): string => $record->listing !== null
                        ? '$'.number_format((float) bcmul((string) $record->listing->stake_amount, (string) ($record->listing->team_size * 2), 6), 2).' USDT'
                        : '—',
                    )
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (MatchStatus $state): string => match ($state) {
                        MatchStatus::LobbyFilling => 'gray',
                        MatchStatus::Pending => 'gray',
                        MatchStatus::Disputed => 'warning',
                        MatchStatus::ManualReview => 'danger',
                        MatchStatus::Settled => 'success',
                        MatchStatus::Cancelled => 'gray',
                    })
                    ->formatStateUsing(fn (MatchStatus $state): string => match ($state) {
                        MatchStatus::LobbyFilling => 'Lobby Filling',
                        MatchStatus::Pending => 'Pending',
                        MatchStatus::Disputed => 'Disputed',
                        MatchStatus::ManualReview => 'Manual Review',
                        MatchStatus::Settled => 'Settled',
                        MatchStatus::Cancelled => 'Cancelled',
                    }),

                TextColumn::make('dispute_opened_at')
                    ->label('Age')
                    ->since()
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (GameMatch $record): string => self::ageBadgeColor($record))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created at')
                    ->dateTime('M j, Y H:i')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options([
                        MatchStatus::LobbyFilling->value => 'Lobby Filling',
                        MatchStatus::Pending->value => 'Pending',
                        MatchStatus::Disputed->value => 'Disputed',
                        MatchStatus::ManualReview->value => 'Manual Review',
                        MatchStatus::Settled->value => 'Settled',
                        MatchStatus::Cancelled->value => 'Cancelled',
                    ])
                    ->default([
                        MatchStatus::Disputed->value,
                        MatchStatus::ManualReview->value,
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Mirrors the OpsOverview "Aging disputes" SLA scale so admins see one
     * consistent color story across the dashboard widget + the queue table.
     */
    private static function ageBadgeColor(GameMatch $record): string
    {
        if (! in_array($record->status, [MatchStatus::Disputed, MatchStatus::ManualReview], true)) {
            return 'gray';
        }

        $aging = $record->dispute_opened_at ?? $record->updated_at;

        if ($aging === null) {
            return 'gray';
        }

        $hours = abs(now()->diffInHours($aging));

        return match (true) {
            $hours >= 12 => 'danger',
            $hours >= 6 => 'warning',
            default => 'success',
        };
    }
}
