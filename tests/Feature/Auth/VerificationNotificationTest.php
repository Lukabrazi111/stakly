<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('sends verification notification and flashes a toast', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->post(route('verification.send'));

    Notification::assertSentTo($user, VerifyEmail::class);

    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Verification email sent. Check your inbox.',
    ]);
    $response->assertInertiaFlash('verify_cooldown_seconds', 60);
});

test('verification resend is rate-limited to 1 request per minute per user', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect();

    // Immediate second hit must be throttled by our custom limiter.
    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertTooManyRequests();
});

test('does not send verification notification if email is verified', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    Notification::assertNothingSent();
});
