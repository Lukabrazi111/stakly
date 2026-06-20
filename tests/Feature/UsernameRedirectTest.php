<?php

use App\Models\User;
use App\Models\UsernameHistory;
use Carbon\CarbonImmutable;

function renameUserHandle(User $user, string $newUsername): void
{
    test()
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'username' => $newUsername,
        ])
        ->assertSessionHasNoErrors();
}

test('released handle inside the 30-day window redirects to the current owner', function () {
    $alice = User::factory()->create(['username' => 'alice-pro']);

    renameUserHandle($alice, 'alice-new');

    test()
        ->get('/users/alice-pro')
        ->assertStatus(301)
        ->assertRedirect(route('users.show', ['user' => 'alice-new']));
});

test('released handle expires to 404 after the reservation window', function () {
    $alice = User::factory()->create(['username' => 'alice-pro']);

    renameUserHandle($alice, 'alice-new');

    test()->travel(31)->days();

    test()
        ->get('/users/alice-pro')
        ->assertStatus(404);
});

test('after a chained rename, only the most recent reserved handle still redirects', function () {
    // Both the cooldown and reservation are 30 days, so by the time Alice
    // can rename again, her *first* old handle's reservation has already
    // expired. Only the most recent prior handle stays reserved.
    $alice = User::factory()->create(['username' => 'alice-pro']);

    renameUserHandle($alice, 'alice-mid');
    test()->travel(31)->days();
    renameUserHandle($alice->refresh(), 'alice-latest');

    test()
        ->get('/users/alice-pro')
        ->assertStatus(404);

    test()
        ->get('/users/alice-mid')
        ->assertStatus(301)
        ->assertRedirect(route('users.show', ['user' => 'alice-latest']));
});

test('orphan history rows (user deleted) return 404 instead of redirecting', function () {
    $alice = User::factory()->create(['username' => 'alice-pro']);
    renameUserHandle($alice, 'alice-new');

    // `nullOnDelete` on the history FK: hard-deleting the user clears
    // user_id but leaves the row so we don't lose the reservation timer.
    $alice->delete();

    expect(UsernameHistory::where('username', 'alice-pro')->first()->user_id)
        ->toBeNull();

    test()
        ->get('/users/alice-pro')
        ->assertStatus(404);
});

test('unknown handle still 404s without any history match', function () {
    test()
        ->get('/users/never-existed')
        ->assertStatus(404);
});

test('current owner of a previously-released handle resolves normally (no redirect loop)', function () {
    $alice = User::factory()->create(['username' => 'alice-pro']);
    renameUserHandle($alice, 'alice-new');

    test()->travel(31)->days();

    $bob = User::factory()->create();
    test()->actingAs($bob)->patch(route('profile.update'), [
        'name' => $bob->name,
        'email' => $bob->email,
        'username' => 'alice-pro',
    ])->assertSessionHasNoErrors();

    test()
        ->get('/users/alice-pro')
        ->assertStatus(200);
});

test('a history row whose released_at is in the past is not used for redirect', function () {
    $alice = User::factory()->create(['username' => 'alice-pro']);

    // Direct insert with a past `released_at` simulates an expired row that
    // hasn't been GC'd. Should not trigger redirect.
    UsernameHistory::create([
        'user_id' => $alice->id,
        'username' => 'expired-handle',
        'released_at' => CarbonImmutable::now()->subDay(),
    ]);

    test()
        ->get('/users/expired-handle')
        ->assertStatus(404);
});
