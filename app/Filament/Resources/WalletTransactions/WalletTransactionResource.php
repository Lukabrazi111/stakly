<?php

namespace App\Filament\Resources\WalletTransactions;

use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Resources\WalletTransactions\Pages\ViewWalletTransaction;
use App\Filament\Resources\WalletTransactions\Schemas\WalletTransactionInfolist;
use App\Filament\Resources\WalletTransactions\Tables\WalletTransactionsTable;
use App\Models\WalletTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin wallet ledger (M31). Read-only audit surface on top of
 * `wallet_transactions` — every money write still goes through
 * `App\Services\Wallet`, preserving the
 * `users.usdt_balance == SUM(wallet_transactions.amount)` invariant
 * asserted in `WalletTest`.
 *
 * Create + Edit + Delete are explicitly disabled. The View page (M31 P2)
 * adds an infolist with contextual links to related entities.
 */
class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Wallet ledger';

    protected static ?string $modelLabel = 'Wallet transaction';

    protected static ?string $pluralModelLabel = 'Wallet transactions';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $slug = 'wallet-transactions';

    public static function infolist(Schema $schema): Schema
    {
        return WalletTransactionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WalletTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletTransactions::route('/'),
            'view' => ViewWalletTransaction::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
