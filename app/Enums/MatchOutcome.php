<?php

namespace App\Enums;

/**
 * Self-reported outcome of a match for a single player.
 *
 * **Deprecated as of M16** — the player Won/Lost/Drawn confirm flow was
 * removed; the game API is now the only outcome source. The enum stays
 * because pre-M16 matches persisted these values into
 * `game_matches.creator_confirmed_outcome` + `taker_confirmed_outcome`
 * (kept nullable for historical audit). No new writes happen.
 */
enum MatchOutcome: string
{
    case Won = 'won';
    case Lost = 'lost';
    case Drawn = 'drawn';
}
