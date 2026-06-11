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
    ],

];
