<?php

namespace App\Services\GameApi;

use App\Enums\GameApiConfidence;

/**
 * Immutable value object returned by `GameApi::getMatchResult(...)`.
 *
 * Shape is intentionally driver-agnostic so the real chess.com / Lichess
 * adapters (M8) can map their wildly different response shapes into the
 * same surface that `ResolveDisputeAction` consumes.
 *
 * `winner_user_id` is null when `confidence === Unknown` — settlement code
 * MUST gate on confidence first, never read winner_user_id without checking.
 *
 * `raw_response` is the driver's raw payload, persisted to
 * `game_matches.api_response` for audit / admin review of ManualReview matches.
 *
 * @phpstan-type RawResponse array<string, mixed>
 */
final readonly class GameApiResult
{
    /**
     * @param  array<string, mixed>  $raw_response
     */
    public function __construct(
        public ?int $winner_user_id,
        public GameApiConfidence $confidence,
        public array $raw_response,
    ) {}
}
