<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Streaming route for cached link-preview images. Same auth-streamed
 * pattern as `MessageController::attachment` but without per-match
 * scoping — link images are by definition public previews of public
 * URLs, so the only gate is "must be logged in" to prevent unauth
 * enumeration of what URLs Stakly users have linked.
 */
beforeEach(function () {
    Storage::fake('local');
});

/**
 * Plant a file at the link-images/{hash}.{ext} path expected by the
 * controller. The bytes don't need to be a valid image — `response()->file`
 * doesn't validate, it just streams whatever's there with the
 * Content-Type the controller stamps. Returns the full filename.
 *
 * Uses a different hex letter per call so tests planting multiple files
 * don't collide.
 */
function plantLinkImage(string $extension = 'jpg', string $hexLetter = 'a'): string
{
    $hash = str_repeat($hexLetter, 64);
    $filename = $hash.'.'.$extension;

    Storage::disk('local')->put('link-images/'.$filename, 'fake-bytes');

    return $filename;
}

test('unauthenticated request is denied', function () {
    $filename = plantLinkImage();

    $this->get('/link-images/'.$filename)->assertRedirect();
});

test('unverified user is denied', function () {
    $user = User::factory()->unverified()->create();
    $filename = plantLinkImage();

    $this->actingAs($user)
        ->get('/link-images/'.$filename)
        ->assertRedirect();
});

test('authenticated user gets the image with image content type', function () {
    $user = User::factory()->create();
    $filename = plantLinkImage();

    $response = $this->actingAs($user)->get('/link-images/'.$filename);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/jpeg');
});

test('response carries the private immutable cache-control header', function () {
    $user = User::factory()->create();
    $filename = plantLinkImage();

    $response = $this->actingAs($user)->get('/link-images/'.$filename);

    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('immutable')
        ->toContain('max-age=31536000');
});

test('serves png with the correct content type', function () {
    $user = User::factory()->create();
    $filename = plantLinkImage('png', 'b');

    $response = $this->actingAs($user)->get('/link-images/'.$filename);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/png');
});

test('rejects filenames that do not match the hash.ext pattern', function (string $filename) {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/link-images/'.$filename)->assertNotFound();
})->with([
    'too short' => ['abc.jpg'],
    'wrong extension' => [str_repeat('a', 64).'.exe'],
    'uppercase hex' => [str_repeat('A', 64).'.jpg'],
    'no extension' => [str_repeat('a', 64)],
    'extra path segment' => ['sub/'.str_repeat('a', 64).'.jpg'],
]);

test('valid pattern but missing file returns 404', function () {
    $user = User::factory()->create();
    $filename = str_repeat('b', 64).'.jpg';

    $this->actingAs($user)->get('/link-images/'.$filename)->assertNotFound();
});
