<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform fee rate
    |--------------------------------------------------------------------------
    |
    | The percentage Stakly takes from the pot at match settlement. Stored as
    | a BCMath-safe string (NOT a float — float arithmetic on money is
    | forbidden per CLAUDE.md). Default: '0.10' = 10% (M6 lock 2026-05-15).
    |
    | The fee is computed at settlement time as `pot * platform_fee_rate`,
    | where `pot = creator_stake + taker_stake`. Winner's payout is `pot - fee`.
    |
    */

    'platform_fee_rate' => env('STAKLY_PLATFORM_FEE_RATE', '0.10'),

    /*
    |--------------------------------------------------------------------------
    | Game-API driver
    |--------------------------------------------------------------------------
    |
    | Which `App\Services\GameApi\GameApi` implementation to bind. Used by
    | `MatchSettlement::resolveDispute` to verify outcomes when players
    | disagree (or when the 4h timeout fires with no agreement).
    |
    | v1: 'mock' only — `MockGameApi` returns deterministic results from
    | match.id, with test helpers for the ManualReview branch. Real
    | chess.com / Lichess adapters land in M8.
    |
    | Supported: 'mock'
    |
    */

    'game_api_driver' => env('STAKLY_GAME_API_DRIVER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Match confirmation timeout (hours)
    |--------------------------------------------------------------------------
    |
    | How long both players have to confirm a match outcome after the match
    | is created (= the taker hit Take). After this window, the
    | `matches:resolve-timeouts` scheduled command resolves the match per the
    | rules in milestones.md Phase 7:
    |
    |   - One Won  + silent opponent → confirmer wins.
    |   - One Lost + silent opponent → opponent wins (claim is honored).
    |   - One Drawn + silent opponent → game-API arbitrates.
    |   - Neither confirmed → game-API arbitrates.
    |
    | Default: 4 hours. Frontend `MatchTimer` (resources/js/components/match/
    | match-timer.tsx) currently hardcodes the same value; if you change one,
    | change both — there's no shared source yet.
    |
    */

    'match_confirmation_timeout_hours' => (int) env('STAKLY_MATCH_CONFIRMATION_TIMEOUT_HOURS', 4),

    /*
    |--------------------------------------------------------------------------
    | chess.com User-Agent header
    |--------------------------------------------------------------------------
    |
    | chess.com's Published Data API asks consumers to send a User-Agent that
    | identifies the project and includes a contact email so they can reach
    | out if they need to. Used by `App\Services\Provider\ChessComProfileClient`
    | (M8 Phase 1) for linked-account verification.
    |
    | Format suggested by chess.com: `Project/Version (contact@email)`.
    |
    */

    'chess_com_user_agent' => env('STAKLY_CHESS_COM_USER_AGENT', 'Stakly/1.0'),

    /*
    |--------------------------------------------------------------------------
    | Linked-account verification code TTL
    |--------------------------------------------------------------------------
    |
    | How long (in minutes) a generated bio-code is valid before the user
    | must request a new one. Short enough that a leaked code has a tiny
    | attack window; long enough that a user can comfortably copy → paste →
    | switch tabs → verify without rushing.
    |
    */

    'link_verification_ttl_minutes' => (int) env('STAKLY_LINK_VERIFICATION_TTL_MINUTES', 15),

];
