<?php

namespace App\Enums;

/**
 * Self-reported outcome of a match for a single player. Stored in
 * `game_matches.creator_confirmed_outcome` and `taker_confirmed_outcome`.
 *
 * Resolution rules (in `App\Actions\GameMatch\ConfirmOutcomeAction::resolveBothConfirmed`):
 *   - Mirror Won/Lost (one Won + one Lost) → settle via `SettleMatchAction`.
 *   - Both Drawn → settle as draw via `SettleDrawMatchAction` (refund both
 *     stakes, no winner, no platform fee).
 *   - Both Won OR both Lost (impossible in good faith) → dispute → game-API.
 *   - Any combination involving Drawn that isn't both-Drawn (e.g. one Drawn,
 *     one Won) → dispute → game-API. We don't pick sides when one player
 *     claims a draw and the other claims a win.
 */
enum MatchOutcome: string
{
    case Won = 'won';
    case Lost = 'lost';
    case Drawn = 'drawn';
}
