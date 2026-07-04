<?php

namespace App\Services\Provider;

use App\Enums\TimeControl;

/**
 * One time-control rating parsed from a chess provider (M41 P3b). Carries the
 * raw `rd` (Glicko deviation) alongside the resolved `isProvisional` flag so the
 * provider-specific provisional rule (Lichess `prov` vs chess.com `rd` cutoff)
 * is decided in the client, not downstream.
 */
final readonly class ChessTimeControlRating
{
    public function __construct(
        public TimeControl $timeControl,
        public int $rating,
        public ?int $rd,
        public bool $isProvisional,
    ) {}
}
