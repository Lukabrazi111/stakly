<?php

namespace App\Filament\Resources\Withdrawals;

use App\Filament\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Filament\Resources\Withdrawals\Tables\WithdrawalsTable;
use App\Models\Withdrawal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin view of player cash-outs (M9 Phase 0b).
 *
 * Deliberately has NO approve action: the anti-abuse hold lives on the payout's
 * insurance window (`App\Services\PayoutClearance`), so a withdrawal that
 * reaches this table has already cleared and nothing gates the happy path.
 * Admin involvement is exception-only — Reject a queued withdrawal, or Freeze
 * the account from the user resource.
 *
 * Create + Edit + Delete are disabled. Every state change goes through
 * `App\Services\Withdrawals` so the row and the ledger can't drift.
 */
class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Withdrawals';

    protected static ?string $modelLabel = 'Withdrawal';

    protected static ?string $pluralModelLabel = 'Withdrawals';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $slug = 'withdrawals';

    public static function table(Table $table): Table
    {
        return WithdrawalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWithdrawals::route('/'),
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
