<?php

namespace App\Filament\Resources\WalletTransactions\Pages;

use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only detail view. No header actions — every row is the result of a
 * domain event routed through `App\Services\Wallet`, never edited from here.
 */
class ViewWalletTransaction extends ViewRecord
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
