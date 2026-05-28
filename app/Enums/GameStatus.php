<?php

namespace App\Enums;

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
enum GameStatus: string
{
    case Active = 'active';
    case ComingSoon = 'coming_soon';
    case Disabled = 'disabled';
}
