<?php

namespace App\Enums;

/**
 * External game-account providers Stakly users can link. Stored as snake_case
 * on `users.pending_verification_provider` and `listings.platform`.
 */
enum LinkedAccountProvider: string
{
    case ChessCom = 'chess_com';
    case Lichess = 'lichess';

    // Stubs reserved for M15 (CS2 / Dota 2 / etc.). Listed here so
    // `Listing.platform` cast accepts seeded test rows without crashing.
    // Real verification flow (bio-code paste / OAuth), ProfileClient, and
    // GameApi adapters land in M15 — until then, these provider values
    // appear ONLY in dev-seed listings, never via the Create flow.
    case Faceit = 'faceit';
    case Steam = 'steam';

    public function displayName(): string
    {
        return match ($this) {
            self::ChessCom => 'chess.com',
            self::Lichess => 'Lichess',
            self::Faceit => 'FACEIT',
            self::Steam => 'Steam',
        };
    }

    /**
     * Regex pattern (with delimiters) for valid usernames on the provider.
     * Used by FormRequest validation AND defensively by
     * `RequestLinkVerificationAction` so it stays correct if called from
     * contexts other than the HTTP layer.
     */
    public function usernamePattern(): string
    {
        return match ($this) {
            self::ChessCom => '/^[a-zA-Z0-9_-]{3,25}$/',
            self::Lichess => '/^[a-zA-Z0-9_-]{2,20}$/',
            // Best-effort patterns for the M15 placeholders. Not exercised
            // anywhere outside seeded test data today.
            self::Faceit => '/^[a-zA-Z0-9_-]{3,20}$/',
            self::Steam => '/^[a-zA-Z0-9_-]{3,32}$/',
        };
    }
}
