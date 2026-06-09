<?php

namespace App\Enums;

/**
 * Outcome of one auto-fetch attempt, recorded on `match_auto_fetch_attempts`
 * for the audit trail.
 *
 * - NoMatch on chess.com triggers the retry chain (one row per attempt);
 *   after exhaustion the match stays Pending until next dispatch trigger.
 * - Ambiguous is a silent skip — wrong game evidence is worse than no evidence.
 * - AcIncomplete (M15 P4) — FACEIT-specific terminal outcome: the match was
 *   found via API but FACEIT AC wasn't required on every roster slot. No
 *   card posted, no retry, match falls to ManualReview via timeout.
 * - Skipped means the attempt never reached the provider; `outcome_reason`
 *   carries the why (`not_pending`, `snapshot_missing`, `already_posted`).
 */
enum AutoFetchOutcome: string
{
    case Matched = 'matched';
    case NoMatch = 'no_match';
    case Ambiguous = 'ambiguous';
    case AcIncomplete = 'ac_incomplete';
    case Error = 'error';
    case Skipped = 'skipped';
}
