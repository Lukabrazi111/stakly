<?php

namespace App\Filament\Resources\WalletTransactions\Tables;

use App\Enums\WalletTransactionType;
use App\Models\WalletTransaction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only wallet ledger table (M31 P1). Newest rows first. Amounts render
 * via BCMath formatting to preserve display precision — the column's raw
 * value is a `decimal:6` string from the model cast.
 *
 * Sum summarizer on the amount column shows the net of currently-visible
 * rows. Filtering by type lets the admin see "platform revenue this month"
 * (type = Fee) or "withdrawals last week" (type = Withdrawal) in one click.
 */
class WalletTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Tx #')
                    ->sortable(),

                TextColumn::make('user.username')
                    ->label('User')
                    ->searchable()
                    ->url(fn ($record): ?string => $record->user
                        ? route('filament.admin.resources.users.view', $record->user)
                        : null,
                    ),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->alignRight()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => WalletTransaction::formatAmount((string) $state))
                    ->color(fn ($state): string => str_starts_with((string) $state, '-')
                        ? 'danger'
                        : 'success',
                    )
                    ->summarize(
                        Sum::make()
                            ->label('Net total')
                            ->formatStateUsing(fn ($state): string => WalletTransaction::formatAmount((string) $state)),
                    ),

                TextColumn::make('reference_id')
                    ->label('Reference')
                    ->limit(30)
                    ->copyable()
                    ->copyMessage('Reference copied')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('M j, Y H:i:s'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->multiple()
                    ->options(WalletTransactionType::class),

                SelectFilter::make('user_id')
                    ->label('User')
                    ->relationship('user', 'username')
                    ->searchable(),

                Filter::make('created_at')
                    ->label('Date range')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)),
                    ),

                Filter::make('amount')
                    ->label('Amount range')
                    ->schema([
                        TextInput::make('min')->numeric()->label('Min'),
                        TextInput::make('max')->numeric()->label('Max'),
                    ])
                    // Range is on magnitude: debits are stored negative, so a raw
                    // signed `>=` on a positive Min would silently drop every debit.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['min'] ?? null, fn ($q, $v) => $q->whereRaw('abs(amount) >= ?', [$v]))
                        ->when($data['max'] ?? null, fn ($q, $v) => $q->whereRaw('abs(amount) <= ?', [$v])),
                    ),

                Filter::make('reference_id')
                    ->label('Reference contains')
                    ->schema([
                        TextInput::make('contains')->label('Reference contains'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['contains'] ?? null,
                            fn ($q, $v) => $q->where('reference_id', 'ilike', "%{$v}%"),
                        ),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
