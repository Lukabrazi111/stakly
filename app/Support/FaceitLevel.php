<?php

namespace App\Support;

/**
 * Derives a FACEIT CS2 skill level (1–10) from a raw ELO. The level is a pure
 * function of ELO, so we derive it at display time rather than storing it —
 * `linked_accounts` keeps a single rating column and the level can never drift
 * from the ELO it was computed against.
 *
 * Bands are FACEIT's published CS2 level thresholds; level 10 is open-ended
 * (2001+). A null ELO (unrated / no CS2 history) yields null.
 */
final class FaceitLevel
{
    public static function fromElo(?int $elo): ?int
    {
        if ($elo === null) {
            return null;
        }

        return match (true) {
            $elo <= 500 => 1,
            $elo <= 750 => 2,
            $elo <= 900 => 3,
            $elo <= 1050 => 4,
            $elo <= 1200 => 5,
            $elo <= 1350 => 6,
            $elo <= 1530 => 7,
            $elo <= 1750 => 8,
            $elo <= 2000 => 9,
            default => 10,
        };
    }
}
