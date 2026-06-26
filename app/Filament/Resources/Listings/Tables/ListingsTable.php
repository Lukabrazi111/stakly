<?php

namespace App\Filament\Resources\Listings\Tables;

use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Models\Listing;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin marketplace index. Newest listings at the top by default. Status
 * badge auto-colors from the `ListingStatus` enum's `HasColor` contract.
 * Wide columns (Game / Time controls / Region / Languages) are toggleable +
 * hidden by default so the default view stays scannable.
 */
class ListingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Listing #')
                    ->sortable(),

                TextColumn::make('user.username')
                    ->label('Creator')
                    ->searchable()
                    ->url(fn (Listing $record): ?string => $record->user
                        ? route('filament.admin.resources.users.view', $record->user)
                        : null,
                    ),

                TextColumn::make('game')
                    ->label('Game')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('platform')
                    ->label('Platform')
                    ->formatStateUsing(fn (LinkedAccountProvider $state): string => match ($state) {
                        LinkedAccountProvider::ChessCom => 'chess.com',
                        LinkedAccountProvider::Lichess => 'Lichess',
                    })
                    ->sortable(),

                TextColumn::make('stake_amount')
                    ->label('Stake')
                    ->alignRight()
                    ->formatStateUsing(fn ($state): string => '$'.number_format((float) $state, 2).' USDT')
                    ->sortable(),

                TextColumn::make('skill_range')
                    ->label('Skill range')
                    ->state(fn (Listing $record): string => self::formatSkillRange($record))
                    ->toggleable(),

                TextColumn::make('time_control')
                    ->label('Time control')
                    ->state(fn (Listing $record): string => self::formatTimeControl($record))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('region')
                    ->label('Region')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('language')
                    ->label('Languages')
                    ->state(fn (Listing $record): string => self::formatLanguages($record))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('M j, Y H:i:s'))
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('M j, Y H:i:s'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(ListingStatus::class),

                SelectFilter::make('platform')
                    ->label('Platform')
                    ->options([
                        LinkedAccountProvider::ChessCom->value => 'chess.com',
                        LinkedAccountProvider::Lichess->value => 'Lichess',
                    ]),

                SelectFilter::make('user_id')
                    ->label('Creator')
                    ->relationship('user', 'username')
                    ->searchable(),

                Filter::make('stake_amount')
                    ->label('Stake range')
                    ->schema([
                        TextInput::make('min')->numeric()->label('Min'),
                        TextInput::make('max')->numeric()->label('Max'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['min'] ?? null, fn ($q, $v) => $q->where('stake_amount', '>=', $v))
                        ->when($data['max'] ?? null, fn ($q, $v) => $q->where('stake_amount', '<=', $v)),
                    ),

                SelectFilter::make('region')
                    ->label('Region')
                    ->options([
                        'Global' => 'Global',
                        'EU' => 'EU',
                        'NA' => 'NA',
                        'Asia' => 'Asia',
                        'CIS' => 'CIS',
                        'LATAM' => 'LATAM',
                    ]),

                Filter::make('language')
                    ->label('Language contains')
                    ->schema([
                        TextInput::make('contains')->label('Language code'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['contains'] ?? null,
                            // jsonb `language` is an array of strings; cast to
                            // text + ilike is the simplest predicate that hits
                            // both `["en"]` and `["en","ru"]` shapes.
                            fn ($q, $v) => $q->whereRaw('language::text ilike ?', ['%'.$v.'%']),
                        ),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function formatSkillRange(Listing $record): string
    {
        if ($record->skill_min === null && $record->skill_max === null) {
            return 'Any';
        }

        return ($record->skill_min ?? '?').'–'.($record->skill_max ?? '?');
    }

    private static function formatTimeControl(Listing $record): string
    {
        return $record->time_control !== null
            ? ucfirst($record->time_control->value)
            : '—';
    }

    private static function formatLanguages(Listing $record): string
    {
        return implode(', ', $record->language ?? []) ?: '—';
    }
}
