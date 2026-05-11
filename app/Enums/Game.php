<?php

namespace App\Enums;

/**
 * Supported games on Stakly. v1 = chess only. New games land here first +
 * `resources/js/config/games.ts` on the frontend.
 */
enum Game: string
{
    case Chess = 'chess';
}
