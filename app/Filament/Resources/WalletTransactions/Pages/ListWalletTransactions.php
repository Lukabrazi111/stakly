<?php

namespace App\Filament\Resources\WalletTransactions\Pages;

use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Ledger index. Read-only, no header actions — every row is the result of a
 * domain event routed through `App\Services\Wallet`, never manually entered.
 */
class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
