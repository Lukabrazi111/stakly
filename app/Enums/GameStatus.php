<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Display state for a `Game` row in the homepage tile catalog.
 *
 * - Active: backend support exists (matches an `App\Enums\Game` case) and
 *   players can stake on it. Tile shows no badge.
 * - ComingSoon: visible on the homepage with a "Soon" badge but no backend
 *   integration. Admin can add these freely without code changes.
 * - Disabled: hidden from the homepage entirely. Used for parking tiles
 *   without deleting them (e.g. test data, deprecated games).
 */
enum GameStatus: string implements HasLabel
{
    case Active = 'active';
    case ComingSoon = 'coming_soon';
    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::ComingSoon => 'Coming soon',
            self::Disabled => 'Disabled',
        };
    }
}
