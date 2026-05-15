<?php

namespace App\Enums;

/**
 * Match lifecycle states. See milestones.md M6 for the full state machine.
 *
 * Pending      — match created, waiting for both players to confirm an outcome.
 * Disputed     — players disagreed (or someone opened a dispute / a timeout
 *                fired with no confirmations). Game-API queried for tiebreaker.
 * Settled      — winner determined, payout + fee posted to the ledger. Terminal.
 * ManualReview — game-API couldn't determine a winner. Money stays locked
 *                until an admin resolves manually. Terminal in v1 (admin tools
 *                deferred to a post-M6 milestone).
 */
enum MatchStatus: string
{
    case Pending = 'pending';
    case Disputed = 'disputed';
    case Settled = 'settled';
    case ManualReview = 'manual_review';
}
