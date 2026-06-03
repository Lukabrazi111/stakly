<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\ListingStatus;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin user index. Hides `is_platform = true` rows (the platform-rake
 * account is never user-facing). Latest registrations at the top by default;
 * admin can re-sort on `usdt_balance` or `created_at` from the column headers.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('is_platform', false))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('User #')
                    ->sortable(),

                TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->wrap()
                    ->limit(40),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: false),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->tooltip(fn ($state): string => $state ? 'Email verified' : 'Email not verified')
                    ->sortable(),

                IconColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->boolean()
                    ->tooltip(fn ($state): string => $state ? '2FA enrolled' : '2FA not enrolled')
                    ->sortable(),

                TextColumn::make('usdt_balance')
                    ->label('Balance')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 2))
                    ->sortable()
                    ->alignRight(),

                TextColumn::make('banned_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Banned' : 'Active')
                    ->color(fn ($state): string => $state ? 'danger' : 'success')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('M j, Y')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('email_verified_at')
                    ->label('Email verified')
                    ->nullable()
                    ->placeholder('All')
                    ->trueLabel('Verified')
                    ->falseLabel('Unverified'),

                TernaryFilter::make('banned_at')
                    ->label('Banned')
                    ->nullable()
                    ->placeholder('All')
                    ->trueLabel('Banned')
                    ->falseLabel('Active'),

                TernaryFilter::make('two_factor_confirmed_at')
                    ->label('Has 2FA')
                    ->nullable()
                    ->placeholder('All')
                    ->trueLabel('Enrolled')
                    ->falseLabel('Not enrolled'),

                TernaryFilter::make('has_active_listing')
                    ->label('Has active listing')
                    ->placeholder('All')
                    ->trueLabel('Has open listing')
                    ->falseLabel('No open listings')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas(
                            'listings',
                            fn (Builder $q) => $q->where('status', ListingStatus::Open),
                        ),
                        false: fn (Builder $query) => $query->whereDoesntHave(
                            'listings',
                            fn (Builder $q) => $q->where('status', ListingStatus::Open),
                        ),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
