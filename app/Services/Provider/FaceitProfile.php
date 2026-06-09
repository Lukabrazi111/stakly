<?php

namespace App\Services\Provider;

/**
 * Subset of FACEIT's `GET /data/v4/players/{player_id}` response that
 * Stakly cares about during the link callback (M15 Phase 2). The CS2
 * fields are nullable because a FACEIT user who has never played CS2
 * has no `games.cs2` block — we still want to link the account, just
 * with `skill_rating = null` until they pick the game up.
 */
final readonly class FaceitProfile
{
    public function __construct(
        public string $playerId,
        public string $nickname,
        public ?int $cs2Elo,
        public ?int $cs2SkillLevel,
    ) {}
}
