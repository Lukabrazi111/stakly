<?php

namespace App\Enums;

/**
 * Supported games on Stakly. New games land here first +
 * `resources/js/config/games.ts` on the frontend.
 */
enum Game: string
{
    case Chess = 'chess';
    case Cs2 = 'cs2';
    case Dota2 = 'dota2';

    public function displayName(): string
    {
        return match ($this) {
            self::Chess => 'Chess',
            self::Cs2 => 'CS2',
            self::Dota2 => 'Dota 2',
        };
    }

    /**
     * Linked-account providers a user must be verified on to post or take a
     * listing for this game. Match must be played on one of these — picking
     * a game in the create form gates the platform selector to this list.
     *
     * @return list<LinkedAccountProvider>
     */
    public function requiredProviders(): array
    {
        return match ($this) {
            self::Chess => [LinkedAccountProvider::ChessCom, LinkedAccountProvider::Lichess],
            self::Cs2 => [LinkedAccountProvider::Faceit],
            self::Dota2 => [LinkedAccountProvider::Steam],
        };
    }

    /**
     * True when this game has a real `GameApi` adapter wired into the
     * production composition chain (M15 P5). Drives the
     * `OpenDisputeAction` fast-path gate: disputes for games returning
     * `true` are eligible for synchronous arbitration via
     * `ResolveDisputeAction`; disputes for games returning `false` route
     * straight to admin manual review.
     *
     * Stays in sync with `AppServiceProvider::bindGameApi()` — adding a
     * new game adapter requires both flipping this method's case AND
     * prepending the adapter to the production chain.
     */
    public function hasArbitrationDriver(): bool
    {
        return match ($this) {
            self::Chess, self::Cs2 => true,
            self::Dota2 => false,
        };
    }

    /**
     * Valid `team_size` values for a listing in this game. 1 = the existing
     * `TakeListingAction` flow (chess + legacy CS2 1v1). Values > 1 route
     * through the M34 lobby pipeline.
     *
     *   Chess → [1] (no team play; native 1v1).
     *   CS2   → [1, 5] (1 = legacy 1v1, 5 = M34 competitive 5v5 via lobby;
     *           2v2 Wingman adds [2] in P5).
     *   Dota2 → [1] (no Dota lobby support until M15 Dota adapter + M34
     *           extension land).
     *
     * @return list<int>
     */
    public function allowedTeamSizes(): array
    {
        return match ($this) {
            self::Chess => [1],
            self::Cs2 => [1, 5],
            self::Dota2 => [1],
        };
    }
}
