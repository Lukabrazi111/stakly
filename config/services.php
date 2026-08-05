<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Lichess API token for the StaklyBot account (M25). Used by
     * `LichessGameClient`, `LichessProfileClient`, and `LichessStreamCommand`
     * to authenticate every request as the registered bot — higher rate-limit
     * bucket, stable stream connections, point-of-contact for Lichess support.
     *
     * To rotate:
     *   1. Log in to the StaklyBot account at lichess.org.
     *   2. Preferences → API access tokens → Create a new personal access token.
     *   3. No scopes needed — public read endpoints accept any valid token.
     *   4. Copy the token, paste it into `.env` as `LICHESS_API_TOKEN=...`, restart
     *      the queue worker + the `stakly:lichess-stream` sidecar.
     *
     * Anonymous fallback: when this value is null (env var unset), the Lichess
     * clients omit the `Authorization` header and call the public endpoints
     * unauthenticated — same behavior as pre-M25. Useful for casual dev or CI
     * runs that don't need the higher limits.
     */
    'lichess' => [
        'token' => env('LICHESS_API_TOKEN'),

        /*
         * Outbound API self-throttle (M35 Phase 2). Caps how many
         * `AutoFetchLichessGameJob` executions per minute hit the
         * provider. Default 60 reflects per-provider tuning — Lichess
         * publishes a 20 req/sec global limit (= 1200/min) so 60/min
         * leaves a 20× safety margin. Tune via env if production data
         * shows headroom (or 429s).
         */
        'requests_per_minute' => (int) env('LICHESS_REQUESTS_PER_MINUTE', 60),

        /*
         * M41 P3b — chess rating-refresh self-throttle + freshness TTL, on a
         * SEPARATE budget from `requests_per_minute` so a rating-refresh burst
         * can't starve the settlement-critical `lichess-api` limiter. Lichess
         * doesn't publish a numeric read limit ("one request at a time, 60s
         * back-off on 429"); 30/min is the CLAUDE.md conservative default.
         */
        'rating_requests_per_minute' => (int) env('LICHESS_RATING_REQUESTS_PER_MINUTE', 30),
        'rating_ttl_hours' => (int) env('LICHESS_RATING_TTL_HOURS', 24),
        // M41 P4 revision — a perf with fewer than this many games is
        // "provisional" (shown with a "?"); Lichess's own `prov` flag (rd-based)
        // no longer decides it, so a rusty-but-established perf isn't hidden.
        'provisional_min_games' => (int) env('LICHESS_PROVISIONAL_MIN_GAMES', 20),

        /*
         * Per-provider `ProviderCircuitBreaker` thresholds (M15 P5 Item 3).
         * Each provider can tune its own trip + cooldown without affecting
         * the others. Missing values fall back to the class-level defaults
         * (10-min window, 5 min attempts, 50% error rate, 5-min cooldown).
         */
        'circuit_breaker' => [
            'window_seconds' => (int) env('LICHESS_BREAKER_WINDOW_SECONDS', 600),
            'min_attempts' => (int) env('LICHESS_BREAKER_MIN_ATTEMPTS', 5),
            'error_rate_threshold' => (float) env('LICHESS_BREAKER_ERROR_RATE_THRESHOLD', 0.5),
            'cooldown_seconds' => (int) env('LICHESS_BREAKER_COOLDOWN_SECONDS', 300),
        ],
    ],

    /*
     * chess.com Published Data API client throttle (M35 Phase 1). Caps how
     * many `AutoFetchChessComGameJob` executions per minute hit the
     * provider — paired with `RateLimiter::for('chess-com-api', ...)` in
     * AppServiceProvider and `RateLimited` job middleware. Default 30 is
     * the M35 conservative cap for undocumented provider quotas; tune via
     * the env var if production data shows headroom (or 429s).
     */
    'chess_com' => [
        'requests_per_minute' => (int) env('CHESS_COM_REQUESTS_PER_MINUTE', 30),

        /*
         * M41 P3b — chess rating-refresh self-throttle + freshness TTL on a
         * SEPARATE budget from settlement's `chess-com-api`. chess.com asks for
         * serial requests with no numeric quota; 30/min is the conservative
         * default. `provisional_min_games` (M41 P4 revision): a rating with fewer
         * than this many games is "provisional" (shown with a "?"); `rd` no
         * longer decides it — an established-but-rusty rating has inflated rd but
         * is NOT provisional.
         */
        'rating_requests_per_minute' => (int) env('CHESS_COM_RATING_REQUESTS_PER_MINUTE', 30),
        'rating_ttl_hours' => (int) env('CHESS_COM_RATING_TTL_HOURS', 24),
        'provisional_min_games' => (int) env('CHESS_COM_PROVISIONAL_MIN_GAMES', 20),

        // Per-provider `ProviderCircuitBreaker` thresholds (M15 P5 Item 3).
        'circuit_breaker' => [
            'window_seconds' => (int) env('CHESS_COM_BREAKER_WINDOW_SECONDS', 600),
            'min_attempts' => (int) env('CHESS_COM_BREAKER_MIN_ATTEMPTS', 5),
            'error_rate_threshold' => (float) env('CHESS_COM_BREAKER_ERROR_RATE_THRESHOLD', 0.5),
            'cooldown_seconds' => (int) env('CHESS_COM_BREAKER_COOLDOWN_SECONDS', 300),
        ],
    ],

    /*
     * FACEIT OAuth + Data API credentials (M15 Phase 2). Wired as a Socialite
     * community provider in `AppServiceProvider::registerSocialiteListeners()`
     * via the `socialiteproviders/faceit` package.
     *
     * Register the app at developers.faceit.com → App Studio. One redirect
     * URI per FACEIT app (their limitation), so dev and production each need
     * a separate FACEIT app.
     *
     * `api_key` is the server-side API key from App Studio → API KEYS. Used
     * for Data API calls (player profile / ELO lookups). FACEIT's OAuth user
     * tokens are scoped to identity (openid) and cannot read the Data API —
     * 403 Forbidden — so the API key is the only path. When unset, link still
     * works but `skill_rating` is null until the key is configured.
     */
    'faceit' => [
        'client_id' => env('FACEIT_CLIENT_ID'),
        'client_secret' => env('FACEIT_CLIENT_SECRET'),
        'redirect' => env('FACEIT_REDIRECT_URI'),
        'api_key' => env('FACEIT_API_KEY'),

        // Shared-secret header value FACEIT POSTs back to `/webhooks/faceit`
        // (M15 P4 Slice 4). Configured in the FACEIT developer portal under
        // the webhook subscription's Authentication settings. Stakly checks
        // it via `hash_equals` against the `X-Faceit-Webhook-Secret` header.
        // Defense-in-depth — the webhook is never trusted as proof; the
        // dispatched `AutoFetchFaceitGameJob` re-fetches via Data API.
        // IP allowlist (`webhook_egress_ips`) is a planned second layer
        // once FACEIT support confirms egress IPs.
        'webhook_secret' => env('FACEIT_WEBHOOK_SECRET'),

        /*
         * Outbound API self-throttle (M35 Phase 3). Caps how many
         * `AutoFetchFaceitGameJob` executions per minute hit the
         * provider. Default 30 reflects the CLAUDE.md conservative-cap
         * rule for undocumented provider quotas — FACEIT support hasn't
         * confirmed their production-key limit yet. Tune via the env var
         * once we observe real headroom or 429s.
         */
        'requests_per_minute' => (int) env('FACEIT_REQUESTS_PER_MINUTE', 30),

        /*
         * M41 P1 — rating-refresh self-throttle + freshness TTL. The rating
         * limiter is SEPARATE from `requests_per_minute` so a refresh burst
         * can never consume the budget money-critical settlement
         * (`AutoFetchFaceitGameJob`) depends on. Rating refresh is low-volume,
         * hence the lower default cap. `rating_ttl_hours` is how long a cached
         * rating is considered fresh before the lazy path re-pulls it.
         */
        'rating_requests_per_minute' => (int) env('FACEIT_RATING_REQUESTS_PER_MINUTE', 20),
        'rating_ttl_hours' => (int) env('FACEIT_RATING_TTL_HOURS', 24),

        // Per-provider `ProviderCircuitBreaker` thresholds (M15 P5 Item 3).
        'circuit_breaker' => [
            'window_seconds' => (int) env('FACEIT_BREAKER_WINDOW_SECONDS', 600),
            'min_attempts' => (int) env('FACEIT_BREAKER_MIN_ATTEMPTS', 5),
            'error_rate_threshold' => (float) env('FACEIT_BREAKER_ERROR_RATE_THRESHOLD', 0.5),
            'cooldown_seconds' => (int) env('FACEIT_BREAKER_COOLDOWN_SECONDS', 300),
        ],
    ],

    /*
     * Custodial crypto payment provider (M9 — billing / chain integration).
     * `driver` selects the bound `App\Services\Payments\PaymentGateway`
     * implementation (see `AppServiceProvider::bindPaymentGateway`). Default
     * `mock` is provider-agnostic and needs no signup/keys/sandbox — the real
     * `nowpayments` / `cryptomus` clients are stubs until go-ahead.
     *
     * The per-provider `requests_per_minute` + `circuit_breaker` blocks mirror
     * the game-client shape and are declared ahead of their consumers: the
     * `payments-api` throttle and breaker reuse land with the real clients,
     * not with this abstraction.
     */
    'payments' => [
        'driver' => env('PAYMENTS_DRIVER', 'mock'),

        'mock' => [
            // Fake flat gas used by the mock payout/fee estimate (USDT string).
            'network_fee' => env('PAYMENTS_MOCK_NETWORK_FEE', '1.500000'),
            // HMAC-SHA256 key the mock webhook verifier checks against the
            // `X-Mock-Signature` header — lets the receiver's signature path be
            // tested without a real provider.
            'webhook_secret' => env('PAYMENTS_MOCK_WEBHOOK_SECRET'),
        ],

        'nowpayments' => [
            'base_url' => env('NOWPAYMENTS_BASE_URL'),
            'api_key' => env('NOWPAYMENTS_API_KEY'),
            'ipn_secret' => env('NOWPAYMENTS_IPN_SECRET'),
            'requests_per_minute' => (int) env('NOWPAYMENTS_REQUESTS_PER_MINUTE', 30),
            'circuit_breaker' => [
                'window_seconds' => (int) env('NOWPAYMENTS_BREAKER_WINDOW_SECONDS', 600),
                'min_attempts' => (int) env('NOWPAYMENTS_BREAKER_MIN_ATTEMPTS', 5),
                'error_rate_threshold' => (float) env('NOWPAYMENTS_BREAKER_ERROR_RATE_THRESHOLD', 0.5),
                'cooldown_seconds' => (int) env('NOWPAYMENTS_BREAKER_COOLDOWN_SECONDS', 300),
            ],
        ],

        'cryptomus' => [
            'base_url' => env('CRYPTOMUS_BASE_URL', 'https://api.cryptomus.com'),
            'merchant_uuid' => env('CRYPTOMUS_MERCHANT_UUID'),
            'payment_key' => env('CRYPTOMUS_PAYMENT_KEY'),
            'payout_key' => env('CRYPTOMUS_PAYOUT_KEY'),
            'requests_per_minute' => (int) env('CRYPTOMUS_REQUESTS_PER_MINUTE', 30),
            'circuit_breaker' => [
                'window_seconds' => (int) env('CRYPTOMUS_BREAKER_WINDOW_SECONDS', 600),
                'min_attempts' => (int) env('CRYPTOMUS_BREAKER_MIN_ATTEMPTS', 5),
                'error_rate_threshold' => (float) env('CRYPTOMUS_BREAKER_ERROR_RATE_THRESHOLD', 0.5),
                'cooldown_seconds' => (int) env('CRYPTOMUS_BREAKER_COOLDOWN_SECONDS', 300),
            ],
        ],
    ],

];
