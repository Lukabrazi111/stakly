<?php

namespace App\Enums;

/**
 * Supported games on Stakly. New games land here first +
 * `resources/js/config/games.ts` on the frontend.
 */
enum Game: string
{
    case Chess = 'chess';
}
