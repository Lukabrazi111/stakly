<?php

namespace App\Services\GameApi;

use App\Models\GameMatch;

/**
 * Driver-agnostic interface for outcome verification of a played match.
 *
 * v1 implementation is `MockGameApi` (deterministic, in-memory). The real
 * chess.com / Lichess adapters land in M8 — at that point the only change
 * needed is the binding in `AppServiceProvider` (driven by config) and
 * the swap from synchronous calls to a queued job (network latency,
 * retries, rate limiting).
 *
 * Implementations MUST be safe to call multiple times for the same match
 * (callers handle idempotency via `ResolveDisputeAction`'s row lock +
 * status guard, but a driver that mutates external state on every call
 * would break that contract).
 */
interface GameApi
{
    /**
     * Look up the result of $match. Returns the winner + confidence + raw
     * driver payload. Throws if the driver itself fails (network error,
     * malformed response, etc.) — callers should treat this as a transient
     * failure and not flip the match to ManualReview on exception (that's
     * for `Unknown` confidence specifically).
     */
    public function getMatchResult(GameMatch $match): GameApiResult;
}
