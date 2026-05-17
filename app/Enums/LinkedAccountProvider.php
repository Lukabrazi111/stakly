<?php

namespace App\Enums;

/**
 * External game-account providers Stakly users can link.
 *
 * v1: chess.com + Lichess (chess only). Future games (Dota 2, CS2, etc.)
 * each get their own provider case when the integration lands; M8 reserves
 * provider routing in `RequestLinkVerificationAction` / `VerifyLinkedAccountAction`
 * via `match` expressions so a new case slots in cleanly.
 *
 * Stored as snake_case strings on `users.pending_verification_provider` and
 * on `listings.platform` (M8 Phase 5).
 */
enum LinkedAccountProvider: string
{
    case ChessCom = 'chess_com';
    case Lichess = 'lichess';

    public function displayName(): string
    {
        return match ($this) {
            self::ChessCom => 'chess.com',
            self::Lichess => 'Lichess',
        };
    }

    /**
     * Regex pattern (with delimiters) for valid usernames on the provider.
     * chess.com allows 3–25 chars, Lichess 2–20 chars; both accept
     * alphanumeric + hyphen + underscore. Used by FormRequest validation
     * AND defensively by `RequestLinkVerificationAction` so the action
     * stays correct if called from contexts other than the HTTP layer.
     */
    public function usernamePattern(): string
    {
        return match ($this) {
            self::ChessCom => '/^[a-zA-Z0-9_-]{3,25}$/',
            self::Lichess => '/^[a-zA-Z0-9_-]{2,20}$/',
        };
    }
}
