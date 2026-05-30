<?php

namespace App\Filament\Resources\GameMatches\Tables;

use App\Enums\MatchStatus;
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

                TextColumn::make('taker.username')
                    ->label('Taker')
                    ->searchable()
                    ->url(fn ($record) => $record->taker
                        ? route('users.show', $record->taker->username)
                        : null,
                        shouldOpenInNewTab: true),

                TextColumn::make('listing.stake_amount')
                    ->label('Stake')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 2).' USDT')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (MatchStatus $state): string => match ($state) {
                        MatchStatus::Pending => 'gray',
                        MatchStatus::Disputed => 'warning',
                        MatchStatus::ManualReview => 'danger',
                        MatchStatus::Settled => 'success',
                        MatchStatus::Cancelled => 'gray',
                    })
                    ->formatStateUsing(fn (MatchStatus $state): string => match ($state) {
                        MatchStatus::Pending => 'Pending',
                        MatchStatus::Disputed => 'Disputed',
                        MatchStatus::ManualReview => 'Manual Review',
                        MatchStatus::Settled => 'Settled',
                        MatchStatus::Cancelled => 'Cancelled',
                    }),

                TextColumn::make('dispute_opened_at')
                    ->label('Disputed at')
                    ->dateTime('M j, Y H:i')
                    ->since()
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
}
