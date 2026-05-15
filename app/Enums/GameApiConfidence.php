<?php

namespace App\Enums;

/**
 * Confidence level returned by a `GameApi` driver for a match result.
 *
 * Confirmed — the driver knows the winner with certainty (e.g. the chess.com
 *             API returned a finished game with a clear winner). Settlement
 *             proceeds with the API's winner_user_id.
 *
 * Unknown   — the driver could not determine a winner (game not found,
 *             ambiguous, abandoned, or — in the mock — a forced test branch).
 *             The match transitions to ManualReview and money stays in
 *             escrow until an admin resolves it.
 */
enum GameApiConfidence: string
{
    case Confirmed = 'confirmed';
    case Unknown = 'unknown';
}
