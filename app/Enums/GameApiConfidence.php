<?php

namespace App\Enums;

/**
 * Result type returned by a `GameApi` driver for a match result. (Named
 * "Confidence" historically — when only `Confirmed` / `Unknown` existed it
 * really was a confidence scale. With `Drawn` added it's effectively a
 * three-way result-type discriminator. The name is kept to avoid churn.)
 *
 * Confirmed — the driver knows the winner with certainty (e.g. the chess.com
 *             API returned a finished game with a clear winner). Settlement
 *             proceeds with the API's `winner_user_id`.
 *
 * Drawn     — the driver knows the game ended in a draw (stalemate, threefold
 *             repetition, 50-move rule, agreement, time-out vs insufficient
 *             material). `winner_user_id` is null. Settlement refunds both
 *             stakes via `MatchSettlement::settleDraw` — no platform fee.
 *
 * Unknown   — the driver could not determine a result (game not found,
 *             ambiguous, abandoned, or — in the mock — a forced test branch).
 *             The match transitions to ManualReview and money stays in
 *             escrow until an admin resolves it.
 */
enum GameApiConfidence: string
{
    case Confirmed = 'confirmed';
    case Drawn = 'drawn';
    case Unknown = 'unknown';
}
