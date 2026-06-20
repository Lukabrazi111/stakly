<?php

namespace App\Services\Provider;

/**
 * Single roster entry on a FACEIT match team. `anticheatRequired` is the
 * per-player signal Stakly uses to gate settlement — see
 * `FaceitMatchResult::isAntiCheatComplete()`.
 */
final readonly class FaceitRosterPlayer
{
    public function __construct(
        public string $playerId,
        public string $nickname,
        public bool $anticheatRequired,
    ) {}
}
