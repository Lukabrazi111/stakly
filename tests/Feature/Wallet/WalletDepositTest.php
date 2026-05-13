<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Wallet deposit page (8.3)
|--------------------------------------------------------------------------
|
| The deposit page hands the user their TRC20 address (a v1 mock generated
| at registration). All we have to verify is that the prop matches the
| user's stored address — the QR rendering happens client-side.
|
*/

test('tronAddress prop matches the users stored address', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/wallet/deposit')
        ->assertInertia(fn ($page) => $page
            ->component('wallet/deposit')
            ->where('tronAddress', $user->tron_address)
        );
});

test('the deposit page renders successfully for verified users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/wallet/deposit')
        ->assertOk();
});
