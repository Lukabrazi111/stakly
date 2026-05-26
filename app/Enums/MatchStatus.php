<?php

namespace App\Enums;

/**
 * Match lifecycle states. See milestones.md M6 + M10 + M16 for the full state
 * machine.
 *
 * Pending      — match created, auto-fetch polling the game API for a result.
 *                4-hour deadline: if no result is found, the match flips to
 *                ManualReview via `ResolveMatchTimeoutAction`.
 * Disputed     — a participant clicked "Report a problem" while Pending. The
 *                game API is queried; resolves to Settled or ManualReview.
 * Settled      — winner determined (or draw — `winner_user_id IS NULL`),
 *                payout + fee posted to the ledger. Terminal.
 * ManualReview — game API couldn't determine a winner OR the match timed out
 *                without a result. Money stays locked until an admin resolves
 *                manually via the Filament panel (M12).
 * Cancelled    — both players agreed to call the match off (one requested,
 *                the other accepted). Both stakes refunded via `Wallet::release`,
 *                no platform fee charged. Terminal. Distinct from `Settled` w/ no
 *                winner (draw) — draws count as a played match, cancellation does
 *                not.
 */
enum MatchStatus: string
{
    case Pending = 'pending';
    case Disputed = 'disputed';
    case Settled = 'settled';
    case ManualReview = 'manual_review';
    case Cancelled = 'cancelled';
}
