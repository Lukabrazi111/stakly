<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Wallet route gating (8.1)
|--------------------------------------------------------------------------
|
| Every wallet route is locked behind `auth + verified` middleware + a
| controller-level `abort_if(is_platform, 403)` guard. This file exercises
| all three gates against all five routes — one dataset row per route.
|
| Note: POST /wallet/withdraw is intentionally absent from the platform
| dataset. The platform check there runs AFTER WithdrawRequest validation,
| so reaching the 403 requires a valid payload. The GET paths (which run
| `abort_if` first) prove the defense; testing POST too would just verify
| the same constant in a noisier way.
|
*/

dataset('all_wallet_routes', [
    'GET /wallet' => ['get', '/wallet'],
    'GET /wallet/deposit' => ['get', '/wallet/deposit'],
    'GET /wallet/withdraw' => ['get', '/wallet/withdraw'],
    'POST /wallet/withdraw' => ['post', '/wallet/withdraw'],
    'GET /wallet/history' => ['get', '/wallet/history'],
]);

dataset('wallet_get_routes', [
    'GET /wallet' => '/wallet',
    'GET /wallet/deposit' => '/wallet/deposit',
    'GET /wallet/withdraw' => '/wallet/withdraw',
    'GET /wallet/history' => '/wallet/history',
]);

test('guests are redirected to login', function (string $method, string $url) {
    $this->{$method}($url)->assertRedirect(route('login'));
})->with('all_wallet_routes');

test('unverified users are redirected to the verification notice', function (string $method, string $url) {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->{$method}($url)
        ->assertRedirect(route('verification.notice'));
})->with('all_wallet_routes');

test('platform user is 403d from every wallet GET route', function (string $url) {
    $platform = User::factory()->create(['is_platform' => true]);

    $this->actingAs($platform)->get($url)->assertForbidden();
})->with('wallet_get_routes');
