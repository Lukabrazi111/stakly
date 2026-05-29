<?php

namespace App\Enums;

/**
 * Outcome of one auto-fetch attempt, recorded on `match_auto_fetch_attempts`
 * for the M14 P1 audit trail. Each row answers "what happened when the
 * system tried to settle this match from the game API on this attempt?".
 *
 * Matched     — exactly one decisive / draw game found between the snapshotted
 *               handles. Card was posted; `SettleFromCardAction` ran.
 * NoMatch     — the provider returned zero candidates (or all filtered out as
 *               aborted / half-played). On chess.com this triggers the retry
 *               chain (one row per attempt); after the chain exhausts, the
 *               match stays Pending until the next dispatch trigger.
 * Ambiguous   — more than one decisive / draw candidate. Silent skip — wrong
 *               game evidence is worse than no evidence.
 * Error       — provider call threw (network, 5xx, malformed payload). Match
 *               stays Pending; logged + counted in PipelineHealth.
 * Skipped     — the attempt never reached the provider. `outcome_reason`
 *               carries the why (`not_pending`, `snapshot_missing`,
 *               `already_posted`).
 */
enum AutoFetchOutcome: string
{
    case Matched = 'matched';
    case NoMatch = 'no_match';
    case Ambiguous = 'ambiguous';
    case Error = 'error';
    case Skipped = 'skipped';
}
