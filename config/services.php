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

];
