<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Marketplace listing lifecycle. Read by Filament's TextColumn::badge() +
 * infolist TextEntry to auto-style each case across admin surfaces (M32 +
 * future).
 */
enum ListingStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Taken = 'taken';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Taken => 'Taken',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Taken => 'warning',
            self::Expired => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
