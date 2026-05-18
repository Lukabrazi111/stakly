<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('register route redirects to home with auth modal flag', function () {
    $response = $this->get(route('register'));

    $response->assertRedirect('/?auth=register');
});

test('new users can register and land on home with a toast flash', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/');
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => "We've sent a verification link to test@example.com.",
    ]);
    $response->assertInertiaFlash('verify_cooldown_seconds', 60);
});

test('newly registered users start inactive — must opt in to appear on the marketplace', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($user->is_active_mode)->toBeFalse();
});
