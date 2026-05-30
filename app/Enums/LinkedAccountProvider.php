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

    public function displayName(): string
    {
        return match ($this) {
            self::ChessCom => 'chess.com',
            self::Lichess => 'Lichess',
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
        };
    }
}
