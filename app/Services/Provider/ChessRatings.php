<?php

namespace App\Services\Provider;

/**
 * Per-time-control chess ratings fetched from a provider (M41 P3b) — the chess
 * analogue of `FaceitProfile`. Holds only the time controls the player has
 * actually played (bullet / blitz / rapid); a time control absent from the list
 * means the player has no rating there ("Unrated").
 *
 * Returned by `LichessProfileClient::fetchRatings()` (parsed from `perfs`) and
 * `ChessComProfileClient::fetchRatings()` (parsed from `/stats`).
 */
final readonly class ChessRatings
{
    /**
     * @param  list<ChessTimeControlRating>  $ratings
     */
    public function __construct(
        public array $ratings,
    ) {}
}
