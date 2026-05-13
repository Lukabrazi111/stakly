<?php

use App\Actions\Fortify\CreateNewUser;
use App\Http\Requests\Wallet\WithdrawRequest;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Tron address generation at registration (8.8)
|--------------------------------------------------------------------------
|
| Every newly registered user gets a TRC20-shaped address assigned to
| `users.tron_address` by `CreateNewUser`. In v1 these come from
| `App\Support\MockTronAddress` (no on-chain meaning); pre-launch they're
| replaced by real HD derivation. The shape contract — `T` + 33 base58
| chars, total 34 — must hold either way so the deposit UI can render
| the same regardless of the underlying generator.
|
*/

function registerVia(string $name, string $email): User
{
    return (new CreateNewUser)->create([
        'name' => $name,
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
}

test('registration assigns a non-null tron_address to every new user', function () {
    $user = registerVia('John Smith', 'john@example.com');

    expect($user->tron_address)->not->toBeNull();
});

test('the generated address matches the TRC20 regex', function () {
    $user = registerVia('Mary Jane', 'mary@example.com');

    expect($user->tron_address)->toMatch(WithdrawRequest::TRC20_REGEX);
});

test('the generated address is 34 chars and starts with T', function () {
    $user = registerVia('Carlos Diaz', 'carlos@example.com');

    expect(strlen($user->tron_address))->toBe(34)
        ->and($user->tron_address[0])->toBe('T');
});

test('two separate registrations produce two distinct addresses', function () {
    $alice = registerVia('Alice Smith', 'alice@example.com');
    $bob = registerVia('Bob Jones', 'bob@example.com');

    expect($alice->tron_address)->not->toBe($bob->tron_address);
});

test('factory-created users also get a valid TRC20 address', function () {
    $user = User::factory()->create();

    expect($user->tron_address)->toMatch(WithdrawRequest::TRC20_REGEX)
        ->and(strlen($user->tron_address))->toBe(34);
});
