<?php

namespace App\Filament\Resources\GameMatches\Pages;

use App\Filament\Resources\GameMatches\GameMatchResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Admin can't create matches — they're created by `TakeListingAction`.
 */
class ListGameMatches extends ListRecords
{
    protected static string $resource = GameMatchResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
