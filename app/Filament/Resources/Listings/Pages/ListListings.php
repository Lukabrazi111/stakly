<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Filament\Resources\Listings\ListingResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Marketplace index for admins. No header actions — listings are created by
 * users only; the one moderation action (force-cancel) lives on the View
 * page (M32 P2) so the confirmation modal can show the listing details.
 */
class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
