<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Header actions (verify email / reset 2FA / ban toggle / impersonate / view
 * as visitor) land in M30 Phase 2 and Phase 4. Phase 1 is the page stub.
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
