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
    | `ResolveDisputeAction` when a player hits "Report a problem" (M16
    | removed the player-confirm flow — the auto-fetch / SettleFromCard
    | pipeline is the primary settlement path; this driver only handles
    | the dispute / manual-escalation path).
    |
    | Supported:
    |   - 'chess' (default) — `ChessGameApi`. Reads the auto-fetched
    |     chess card from chat (posted by `AutoFetchLichessGameJob` or
    |     `AutoFetchChessComGameJob`) and returns the winner that card
    |     names. Provider-agnostic — handles BOTH Lichess and chess.com
    |     cards via the card's `provider` discriminator. Falls through to
    |     `MockGameApi` when no card exists.
    |   - 'mock' — `MockGameApi` directly. Deterministic by `match.id`
    |     parity, no card reading. Useful for environments where real
    |     chess APIs shouldn't influence settlement.
    |
    */

    'game_api_driver' => env('STAKLY_GAME_API_DRIVER', 'chess'),

    /*
    |--------------------------------------------------------------------------
    | Dispute fast-path (M14 Slice 4a)
    |--------------------------------------------------------------------------
    |
    | When true, `OpenDisputeAction` invokes `ResolveDisputeAction` synchronously
    | right after flipping the match to Disputed. Skips the wait for the next
    | auto-fetch cron tick and lets the API arbitrate immediately on a player
    | "Report a problem" click.
    |
    | Gated to chess matches (`game === Game::Chess`) — FACEIT / OpenDota / Riot
    | adapters land in M15 and will widen the gate then.
    |
    | Default off until M14 Phase 1's `PipelineHealth` widget shows ~2 weeks of
    | stable auto-fetch (Slice 4b checkpoint).
    |
    */

    'dispute_fast_path_enabled' => (bool) env('STAKLY_DISPUTE_FAST_PATH_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Match confirmation timeout (hours)
    |--------------------------------------------------------------------------
    |
    | How long a Pending match waits for an API-verified game record before
    | the `matches:resolve-timeouts` scheduled command flips it to
    | ManualReview (M16). The auto-fetch triggers (page-visit, chat-send,
    | `stakly:auto-fetch-pending` cron at 5-min cadence) get the full
    | window to find a matching game; if none lands by the deadline, an
    | admin (M12) takes over.
    |
    | Default: 4 hours. The frontend `MatchTimer` countdown reads this value
    | via `GameMatchResource::match_deadline_at` (and `LobbyResource` on the
    | team path) — the backend is the single source, so changing it here also
    | moves the on-screen clock. No hardcoded copy in the React layer (M39 P2).
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

    /*
    |--------------------------------------------------------------------------
    | Supported locales
    |--------------------------------------------------------------------------
    |
    | Public-site i18n (M26 Phase 4). Every locale listed here is a valid
    | URL prefix (`/en/`, `/ka/`, `/ru/`) and a candidate Inertia shared
    | translation bag sourced from `lang/{locale}.json`. `default_locale`
    | is the redirect target when no cookie / prefix is present and the
    | fallback for missing CMS rows.
    |
    | `locales_meta` carries the native label shown by the LocaleSwitcher
    | (Slice C) and the BCP-47 region pair used by `og:locale` on each
    | page (en→en_US, ka→ka_GE, ru→ru_RU).
    |
    | Adding a locale: list it here, drop a `lang/{locale}.json` (can be
    | empty — Laravel falls back to the key), and the routing layer +
    | switcher pick it up. Filament admin is intentionally English-only
    | and not affected.
    |
    */

    'default_locale' => env('STAKLY_DEFAULT_LOCALE', 'en'),

    'locales' => ['en', 'ka', 'ru'],

    'locales_meta' => [
        'en' => ['native_label' => 'English', 'og_locale' => 'en_US'],
        'ka' => ['native_label' => 'ქართული', 'og_locale' => 'ka_GE'],
        'ru' => ['native_label' => 'Русский', 'og_locale' => 'ru_RU'],
    ],

];
