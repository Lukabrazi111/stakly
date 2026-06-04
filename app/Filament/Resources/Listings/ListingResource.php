<?php

namespace App\Filament\Resources\Listings;

use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\Tables\ListingsTable;
use App\Models\Listing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin marketplace surface (M32). Read-only at the resource level —
 * create / edit / delete are explicitly disabled. The View page (M32 P2)
 * adds a single moderation action (force-cancel) that routes through
 * `App\Actions\Listing\CancelListingAction` so escrow releases via
 * `Wallet::release` and the ledger invariant holds. Admin never writes to
 * `users.usdt_balance` directly.
 */
class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Listings';

    protected static ?string $modelLabel = 'Listing';

    protected static ?string $pluralModelLabel = 'Listings';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $slug = 'listings';

    public static function table(Table $table): Table
    {
        return ListingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
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
