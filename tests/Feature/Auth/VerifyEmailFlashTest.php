<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;

test('verify-email redirect emits Inertia flash that survives to the next request', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    // Hit the verify endpoint and confirm flash is queued for the next request.
    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect('/')
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Email verified.',
        ]);

    // Follow the redirect: the flash should be on the resulting page's
    // top-level flash data (not in props, per Inertia v3 conventions).
    $response = $this->actingAs($user->fresh())
        ->followingRedirects()
        ->get($verificationUrl);

    $response->assertOk();

    preg_match(
        '/<script[^>]*data-page="app"[^>]*>(.*?)<\/script>/s',
        $response->getContent(),
        $matches,
    );

    expect($matches[1] ?? null)->not->toBeNull();

    $page = json_decode(html_entity_decode($matches[1]), true);

    expect($page['flash']['toast'] ?? null)
        ->toBe([
            'type' => 'success',
            'message' => 'Email verified.',
        ]);
});
