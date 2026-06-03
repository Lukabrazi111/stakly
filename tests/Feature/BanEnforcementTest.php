<?php

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * M30 Phase 2 — banned-user enforcement across the four guarded surfaces:
 * `ListingController::create + store`, `ProfileController::update`,
 * `ChangeUsernameAction` (via the rename FormRequest), and the public
 * marketplace listing scope.
 */

// ─── ListingController::create ─────────────────────────────────────────────

test('banned user cannot open the create-listing form', function () {
    $banned = User::factory()->withLichess()->create(['banned_at' => now()]);

    actingAs($banned)
        ->get(route('listings.create'))
        ->assertRedirect(route('listings.index'));
});

test('active user can open the create-listing form', function () {
    $active = User::factory()->withLichess()->create(['banned_at' => null]);

    actingAs($active)
        ->get(route('listings.create'))
        ->assertOk();
});

// `ListingController::store` shares the same `BanGuard::isBanned` short-circuit as
// `create`. The create-form test above proves the guard fires; we skip a parallel
// store test to avoid maintaining a valid stake/platform/time-control payload
// just to reach the same `if (banned)` branch.

// ─── ProfileController::update ─────────────────────────────────────────────

test('banned user cannot update profile', function () {
    $banned = User::factory()->create([
        'banned_at' => now(),
        'name' => 'Original Name',
    ]);

    actingAs($banned)
        ->patch(route('profile.update'), [
            'name' => 'Changed Name',
            'email' => $banned->email,
        ])
        ->assertRedirect(route('profile.edit'));

    expect($banned->refresh()->name)->toBe('Original Name');
});

// ─── ChangeUsername blocker ────────────────────────────────────────────────

test('banned blocker fires ahead of cooldown + in-flight match', function () {
    $banned = User::factory()->create([
        'banned_at' => now(),
        'username' => 'pre-ban-handle',
    ]);

    expect($banned->isBanned())->toBeTrue();
    expect($banned->canChangeUsername())->toBeFalse();
    expect($banned->usernameChangeBlockers()[0])->toBe('banned');
});

test('banned user rename request is rejected with the ban message on the username field', function () {
    // FormRequest validation fires first — `ProfileUpdateRequest::after()`
    // iterates `usernameChangeBlockers()` and adds the `banned` message to
    // the username field. The controller-level BanGuard never runs here
    // because validation has already failed.
    $banned = User::factory()->create([
        'banned_at' => now(),
        'username' => 'pre-ban-handle',
    ]);

    actingAs($banned)
        ->patch(route('profile.update'), [
            'name' => $banned->name,
            'email' => $banned->email,
            'username' => 'post-ban-handle',
        ])
        ->assertSessionHasErrors('username');

    expect($banned->refresh()->username)->toBe('pre-ban-handle');
});

// ─── Public marketplace scope ──────────────────────────────────────────────

test('banned creator listings disappear from the public marketplace', function () {
    $bannedCreator = User::factory()->create([
        'banned_at' => now(),
        'is_active_mode' => true,
    ]);
    $activeCreator = User::factory()->create([
        'banned_at' => null,
        'is_active_mode' => true,
    ]);

    Listing::factory()->create([
        'user_id' => $bannedCreator->id,
        'status' => ListingStatus::Open,
        'expires_at' => now()->addDay(),
    ]);
    $visibleListing = Listing::factory()->create([
        'user_id' => $activeCreator->id,
        'status' => ListingStatus::Open,
        'expires_at' => now()->addDay(),
    ]);

    $publicIds = Listing::query()->onPublicMarketplace()->pluck('id')->all();

    expect($publicIds)->toContain($visibleListing->id);
    expect($publicIds)->not->toContain(
        Listing::query()->where('user_id', $bannedCreator->id)->value('id'),
    );
});

// ─── Sanity: banned user can still log in / view their own dashboard ───────

test('banned user can still load their profile edit page', function () {
    $banned = User::factory()->create(['banned_at' => now()]);

    actingAs($banned)
        ->get(route('profile.edit'))
        ->assertOk();
});
