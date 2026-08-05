<?php

namespace App\Filament\Resources\Withdrawals\Pages;

use App\Filament\Resources\Withdrawals\WithdrawalResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Withdrawals index. Read-only, no header actions — rows are created by
 * players through `Withdrawals::request`, never entered by an admin.
 */
class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
