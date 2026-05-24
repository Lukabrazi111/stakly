<?php

namespace App\Filament\Resources\GameMatches\Pages;

use App\Filament\Resources\GameMatches\GameMatchResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Admin can't create matches — they're created by `TakeListingAction` when
 * a taker accepts a listing. No header actions on the list page; the
 * default filter (Disputed + ManualReview) puts the queue front-and-center.
 */
class ListGameMatches extends ListRecords
{
    protected static string $resource = GameMatchResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
