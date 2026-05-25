<?php

use App\Http\Resources\UserProfileResource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Spatie writes uploads + derived conversions to the `public` disk
    // (see config/media-library.php). Faking the disk keeps tests
    // hermetic — no real files land in storage/app/public.
    Storage::fake('public');
});

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

// ─── Bio (M18 Phase 1) ─────────────────────────────────────────────────────

test('bio can be updated with line breaks', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'bio' => "Line one\nLine two\nLine three",
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->bio)->toBe("Line one\nLine two\nLine three");
});

test('bio is rejected when longer than 500 characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'bio' => str_repeat('a', 501),
        ])
        ->assertSessionHasErrors('bio');
});

// ─── Avatar upload (M18 Phase 1) ───────────────────────────────────────────

test('avatar can be uploaded and is exposed via the profile resource', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 600, 600),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->getFirstMedia('profile-avatar'))->not->toBeNull();
    expect($user->avatar_url)->toBeString();
    expect($user->avatar_thumb_url)->toBeString();

    $resource = (new UserProfileResource($user))->resolve();
    expect($resource['avatar_url'])->toBeString();
    expect($resource['avatar_thumb_url'])->toBeString();
});

test('avatar resource fields are null until upload', function () {
    $user = User::factory()->create();

    $resource = (new UserProfileResource($user))->resolve();
    expect($resource['avatar_url'])->toBeNull();
    expect($resource['avatar_thumb_url'])->toBeNull();
});

test('avatar upload replaces the previous file', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('first.jpg', 600, 600),
        ])
        ->assertSessionHasNoErrors();

    $firstMedia = $user->refresh()->getFirstMedia('profile-avatar');
    expect($firstMedia)->not->toBeNull();
    $firstMediaId = $firstMedia->id;

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('second.jpg', 600, 600),
        ])
        ->assertSessionHasNoErrors();

    $secondMedia = $user->refresh()->getFirstMedia('profile-avatar');
    expect($secondMedia)->not->toBeNull();
    // `singleFile()` on the Spatie collection swaps the file — there should
    // only ever be one media row, and its id changed from the first upload.
    expect($user->getMedia('profile-avatar'))->toHaveCount(1);
    expect($secondMedia->id)->not->toBe($firstMediaId);
});

test('avatar is rejected for unsupported MIME types', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->create('avatar.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('avatar');

    expect($user->refresh()->getFirstMedia('profile-avatar'))->toBeNull();
});

test('avatar is rejected when larger than 2 MB', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            // `image()->size($kb)` — anything above 2048 KB should fail the
            // `max:2048` rule on `ProfileUpdateRequest`.
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 600, 600)->size(2500),
        ])
        ->assertSessionHasErrors('avatar');

    expect($user->refresh()->getFirstMedia('profile-avatar'))->toBeNull();
});

test('profile update without an avatar leaves the existing avatar untouched', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 600, 600),
        ])
        ->assertSessionHasNoErrors();

    $mediaId = $user->refresh()->getFirstMedia('profile-avatar')->id;

    // Subsequent name-only update — no avatar key in the payload.
    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Renamed',
            'email' => $user->email,
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->getFirstMedia('profile-avatar')->id)->toBe($mediaId);
    expect($user->name)->toBe('Renamed');
});

// ─── Remove avatar (M18 Phase 1 polish) ────────────────────────────────────

test('avatar can be removed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 600, 600),
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->getFirstMedia('profile-avatar'))->not->toBeNull();

    $this->actingAs($user)
        ->delete(route('profile.avatar.destroy'))
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->getFirstMedia('profile-avatar'))->toBeNull();
    expect($user->avatar_url)->toBeNull();
    expect($user->avatar_thumb_url)->toBeNull();
});

test('removing an avatar that does not exist is a no-op', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('profile.avatar.destroy'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->getFirstMedia('profile-avatar'))->toBeNull();
});

test('guest cannot remove an avatar', function () {
    $this->delete(route('profile.avatar.destroy'))
        ->assertRedirect(route('login'));
});
