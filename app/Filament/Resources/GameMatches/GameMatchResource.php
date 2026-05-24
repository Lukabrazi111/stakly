<?php

namespace App\Filament\Resources\GameMatches;

use App\Filament\Resources\GameMatches\Pages\ListGameMatches;
use App\Filament\Resources\GameMatches\Pages\ViewGameMatch;
use App\Filament\Resources\GameMatches\Schemas\GameMatchInfolist;
use App\Filament\Resources\GameMatches\Tables\GameMatchesTable;
use App\Models\GameMatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * M12 Phase 2 — admin dispute queue. Read-only resource: admins view
 * matches (with full chat history inline) and resolve via three header
 * actions on the View page (Settle to Creator / Settle to Taker / Draw —
 * refund both). No create or edit — matches are created from the player
 * app via `TakeListingAction` and lifecycle-managed by the existing match
 * actions.
 */
class GameMatchResource extends Resource
{
    protected static ?string $model = GameMatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Disputes';

    protected static ?string $modelLabel = 'Match';

    protected static ?string $pluralModelLabel = 'Matches';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    // Pretty URL — `/admin/disputes` instead of Filament's auto-generated
    // `/admin/game-matches`. Sidebar nav already labels this "Disputes"
    // (see `$navigationLabel`); keeping the URL slug consistent makes
    // links + bookmarks read naturally.
    protected static ?string $slug = 'disputes';

    public static function infolist(Schema $schema): Schema
    {
        return GameMatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GameMatchesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGameMatches::route('/'),
            'view' => ViewGameMatch::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
