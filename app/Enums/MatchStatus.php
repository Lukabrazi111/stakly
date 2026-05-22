<?php

namespace App\Enums;

/**
 * Match lifecycle states. See milestones.md M6 + M10 for the full state machine.
 *
 * Pending      — match created, waiting for both players to confirm an outcome.
 * Disputed     — players disagreed (or someone opened a dispute / a timeout
 *                fired with no confirmations). Game-API queried for tiebreaker.
 * Settled      — winner determined, payout + fee posted to the ledger. Terminal.
 * ManualReview — game-API couldn't determine a winner. Money stays locked
 *                until an admin resolves manually. Terminal pending M12 admin
 *                tooling.
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
