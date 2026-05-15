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

];
