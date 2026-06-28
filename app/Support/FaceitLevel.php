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

    /**
     * Lowest ELO that still maps to a given level — the inclusive floor used to
     * translate a "level ≥ N" marketplace filter into an ELO bound on the stored
     * `skill_rating`. Level 1 floors at 0 (catches every sub-500 rating). Mirror
     * of {@see self::fromElo()} thresholds, so floor/ceil round-trip cleanly.
     */
    public static function eloFloor(int $level): int
    {
        return match (true) {
            $level <= 1 => 0,
            $level === 2 => 501,
            $level === 3 => 751,
            $level === 4 => 901,
            $level === 5 => 1051,
            $level === 6 => 1201,
            $level === 7 => 1351,
            $level === 8 => 1531,
            $level === 9 => 1751,
            default => 2001,
        };
    }

    /**
     * Highest ELO that still maps to a given level — the inclusive ceiling for a
     * "level ≤ N" filter. Level 10 is open-ended (2001+), so it returns null and
     * the caller applies no upper bound.
     */
    public static function eloCeil(int $level): ?int
    {
        return match (true) {
            $level <= 1 => 500,
            $level === 2 => 750,
            $level === 3 => 900,
            $level === 4 => 1050,
            $level === 5 => 1200,
            $level === 6 => 1350,
            $level === 7 => 1530,
            $level === 8 => 1750,
            $level === 9 => 2000,
            default => null,
        };
    }
}
