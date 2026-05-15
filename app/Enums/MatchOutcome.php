<?php

namespace App\Enums;

/**
 * Self-reported outcome of a match for a single player. Stored in
 * `game_matches.creator_confirmed_outcome` and `taker_confirmed_outcome`.
 *
 * Both players claiming the same winner = settle.
 * Both players claiming they each won (mismatch) = dispute → game-API.
 */
enum MatchOutcome: string
{
    case Won = 'won';
    case Lost = 'lost';
}
