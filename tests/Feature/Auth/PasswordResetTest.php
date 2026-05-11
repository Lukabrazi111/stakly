<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('forgot password route redirects to home with auth modal flag', function () {
    $response = $this->get(route('password.request'));

    $response->assertRedirect('/?auth=forgot-password');
});

test('reset password link can be requested and flashes a toast', function () {
    Notification::fake();

    $user = User::factory()->create();

    $response = $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);

    $response->assertRedirect('/');
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => "Password reset link sent to {$user->email}.",
    ]);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->get(route('password.reset', $notification->token).'?email='.urlencode($user->email));

        $response->assertOk();

        return true;
    });
});

test('reset password screen redirects home when token is missing or already used', function () {
    $user = User::factory()->create();

    $response = $this->get(route('password.reset', 'bogus-token').'?email='.urlencode($user->email));

    $response->assertRedirect('/');
    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'This password reset link is invalid or has expired.',
    ]);
});

test('reset password screen redirects home when email is missing', function () {
    $response = $this->get(route('password.reset', 'some-token'));

    $response->assertRedirect('/');
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/?auth=login')
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                'message' => 'Password reset. You can now log in with your new password.',
            ]);

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
