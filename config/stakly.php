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

    /*
    |--------------------------------------------------------------------------
    | Withdrawal money knobs (M9 Phase 0b)
    |--------------------------------------------------------------------------
    |
    | All amounts are BCMath-safe strings, never floats (CLAUDE.md). The
    | margin is Stakly's flat platform cut on a withdrawal — booked to the
    | platform user at *send* time, never at request, so a rejected
    | withdrawal can't record phantom revenue.
    |
    | The network (gas) fee is NOT configured here: it comes from the active
    | `PaymentGateway` driver via `estimatePayoutFee()`, so it tracks real
    | chain conditions once a provider is wired. `MockGateway` reads its own
    | flat `services.payments.mock.network_fee`.
    |
    */

    'withdrawal_margin' => env('STAKLY_WITHDRAWAL_MARGIN', '0.50'),

    'min_withdrawal' => env('STAKLY_MIN_WITHDRAWAL', '10'),

    /*
    | Declared as the single source of truth for stake floors, but NOT yet
    | enforced — `StoreListingRequest` still validates `min:1`. Raising it is
    | a product decision (the billing plan argues for '20', since a ~$1.50
    | network fee eats an absurd share of a $5 pot). Flip the rule when that
    | decision lands; nothing else needs to change.
    */
    'min_stake' => env('STAKLY_MIN_STAKE', '20'),

    /*
    |--------------------------------------------------------------------------
    | Payout clearing / insurance window (M9 Phase 0b)
    |--------------------------------------------------------------------------
    |
    | Match winnings are credited immediately but aren't *withdrawable* until
    | they clear. Deposits and escrow refunds never clear — they're final
    | on-chain or the player's own stake back. Uncleared winnings can still be
    | staked into new matches; they just can't leave the platform.
    |
    | The threat model is a provider (chess.com / Lichess) retroactively
    | closing an account for fair play days-to-weeks after the games — the
    | chargeback-equivalent for this product. The acute case is already
    | handled by the auto-detection pipeline; this covers the retrospective
    | one, where the money would otherwise be long gone.
    |
    | Clearing moves NO money: `wallet_transactions.clears_at` is stamped once
    | at settlement and availability is computed against it. There is no
    | scheduled job — funds clear because time passed.
    |
    | Setting `enabled` to false is a true kill-switch: it makes withdrawals
    | instant AND releases holds already stamped on existing rows, rather than
    | stranding them behind a window nobody is enforcing any more.
    |
    */

    'withdrawal_insurance_enabled' => (bool) env('STAKLY_WITHDRAWAL_INSURANCE_ENABLED', true),

    'withdrawal_insurance_base_hours' => (int) env('STAKLY_WITHDRAWAL_INSURANCE_BASE_HOURS', 48),

    'withdrawal_insurance_elevated_hours' => (int) env('STAKLY_WITHDRAWAL_INSURANCE_ELEVATED_HOURS', 168),

    /*
    | Any one of these firing escalates a payout from base to elevated hours.
    | Deliberately coarse — these are the signals available without building a
    | risk-scoring system, and they cover the cases where a retroactive
    | provider ban is most likely to land on money we can't claw back.
    */
    'withdrawal_insurance_risk' => [
        // Account registered less recently than this clears at the base rate.
        'new_account_days' => (int) env('STAKLY_INSURANCE_RISK_NEW_ACCOUNT_DAYS', 7),
        // A player who has never completed a withdrawal is unproven.
        'first_withdrawal' => (bool) env('STAKLY_INSURANCE_RISK_FIRST_WITHDRAWAL', true),
        // Payouts at or above this amount (USDT string) always get the long window.
        'large_payout_amount' => env('STAKLY_INSURANCE_RISK_LARGE_PAYOUT', '500'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity verification / KYC (M9 Phase 0c)
    |--------------------------------------------------------------------------
    |
    | OFF BY DEFAULT, and nothing currently requires it. NOWPayments asks
    | crypto-only merchants for KYB/KYC only "in a rare case when a certain
    | transaction is marked as suspicious", and imposes nothing on our end
    | users under the permanent-address deposit model — players are addresses
    | sending money, not accounts on their platform. (That changes if we ever
    | adopt Custody sub-accounts; see `docs/billing-roadmap.md` D1/D2.)
    |
    | This exists so verification is a SWITCH, not a rewrite. `KycGate` sits at
    | the single choke point every cash-out already passes through, so turning
    | it on is a config flip — no schema change against a live money table.
    |
    | It is a TIERED VOLUME gate, not an all-users wall: below the threshold
    | nothing is asked. That matters because verification is admin-driven
    | (an operator confirms out-of-band and flips the status) — there is no
    | document-upload flow, so a blanket gate would brick cash-out for every
    | player the moment it was enabled.
    |
    | Set `kyc_threshold` to '0' to require verification for any withdrawal.
    |
    */

    'kyc_enabled' => (bool) env('STAKLY_KYC_ENABLED', false),

    /*
    | Lifetime withdrawal volume (USDT string) above which a player must be
    | verified. Counts Pending + Sending + Completed withdrawals INCLUDING the
    | one being requested — counting only Completed would let a player split
    | one large cash-out into several concurrent requests to stay under the
    | line. Rejected/Failed are excluded; those were credited back.
    */
    'kyc_threshold' => env('STAKLY_KYC_THRESHOLD', '1000'),

    /*
    |--------------------------------------------------------------------------
    | 2FA step-up on withdrawal (M9 Phase 0d)
    |--------------------------------------------------------------------------
    |
    | ON by default. Account takeover -> drain to an attacker address is the
    | highest-severity money path in the product, and Fortify TOTP is already
    | wired, so this is close to free.
    |
    | STEP-UP, not a prerequisite: a fresh code is required on EVERY withdrawal,
    | not merely "2FA must be enabled". Requiring only enrolment would leave a
    | hijacked live session able to drain freely — that session already passed
    | 2FA at login. The code proves a human with the device is present at
    | withdrawal time.
    |
    | Enforced at the HTTP boundary (`WithdrawRequest`), NOT inside
    | `Withdrawals::request()`. Freeze and KYC are DB state and must be checked
    | under the row lock; a TOTP code is a credential that only exists in a
    | request context, and seeders / admin-initiated withdrawals legitimately
    | have none to present.
    |
    | Turn off locally (`STAKLY_WITHDRAWAL_REQUIRE_2FA=false`) if you'd rather
    | not run a TOTP app in dev — though the seeded dev user carries a fixed,
    | known secret precisely so you don't have to.
    |
    */

    'withdrawal_require_2fa' => (bool) env('STAKLY_WITHDRAWAL_REQUIRE_2FA', true),

    /*
    |--------------------------------------------------------------------------
    | New-address cooldown (M9 Phase 0e)
    |--------------------------------------------------------------------------
    |
    | How long a withdrawal to a NEVER-USED destination address waits before the
    | payout is handed to the provider. Closes the gap the 2FA step-up leaves
    | open: a code proves someone with the device is present, but a phished or
    | coerced code still sends funds wherever the request says. The delay plus
    | the notification turns an instant irreversible drain into a window where
    | the real owner can react.
    |
    | HELD, not blocked. The withdrawal is accepted and the balance debited
    | immediately (so it can't be spent twice); only the send waits. Blocking
    | outright would just fail every legitimate first withdrawal. Admin Reject
    | during the hold credits the full gross back through the existing path.
    |
    | An address counts as known once the player has a non-reversed withdrawal
    | to it, so only the FIRST send to a given address waits.
    |
    | Set to 0 to disable — same convention as the insurance window.
    |
    */

    'withdrawal_address_cooldown_hours' => (int) env('STAKLY_WITHDRAWAL_ADDRESS_COOLDOWN_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Withdrawal velocity cap (M9 Phase 0f)
    |--------------------------------------------------------------------------
    |
    | Maximum USDT a single account may withdraw in any rolling 24 hours.
    |
    | This is the backstop for exploits nobody predicted. Freeze, KYC, the 2FA
    | step-up, and the new-address cooldown each block a KNOWN attack; a daily
    | ceiling bounds the worst-case loss from an unknown one, so the damage from
    | any single compromised account is capped no matter how it happened.
    |
    | ROLLING 24h, not calendar-day: a calendar reset lets an attacker withdraw
    | the full limit at 23:59 and again at 00:01 for double the intended cap.
    |
    | Counts Pending + Sending + Completed, including the request being made —
    | Rejected/Failed are excluded because that money came back. Same rule as
    | the KYC threshold, and checked under the same user row lock.
    |
    | Set to 0 to disable.
    |
    */

    'withdrawal_daily_limit' => env('STAKLY_WITHDRAWAL_DAILY_LIMIT', '5000'),

];
