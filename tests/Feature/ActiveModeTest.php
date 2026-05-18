<?php

use App\Models\User;

// ─── Auth gates ───────────────────────────────────────────────────────────

test('guests cannot toggle active mode (redirected to login)', function () {
    $this->post('/active-mode', ['active' => true])
        ->assertRedirect(route('login'));
});

test('unverified users are blocked by the verified middleware', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post('/active-mode', ['active' => true])
        ->assertRedirect(route('verification.notice'));
});

test('platform user gets 403 — the platform account has no listings to gate', function () {
    $platform = User::factory()->create(['is_platform' => true]);

    $this->actingAs($platform)
        ->post('/active-mode', ['active' => false])
        ->assertForbidden();
});

// ─── State flipping ──────────────────────────────────────────────────────

test('flipping inactive → active updates the user and flashes a success toast', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->actingAs($user)->post('/active-mode', ['active' => true]);

    $response->assertRedirect();
    expect($user->fresh()->is_active_mode)->toBeTrue();
    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Active Mode on. Your listings are back on the marketplace.',
    ]);
});

test('flipping active → inactive updates the user and flashes an info toast', function () {
    $user = User::factory()->active()->create();

    $response = $this->actingAs($user)->post('/active-mode', ['active' => false]);

    $response->assertRedirect();
    expect($user->fresh()->is_active_mode)->toBeFalse();
    $response->assertInertiaFlash('toast', [
        'type' => 'info',
        'message' => 'Active Mode off. Your listings are hidden from the marketplace.',
    ]);
});

// ─── Idempotency ──────────────────────────────────────────────────────────

test('submitting the same state as already set is a silent no-op', function () {
    // The controller short-circuits inside the transaction: `if (locked->is_active_mode === active) return 'unchanged'`.
    // Result: no DB write (updated_at unchanged), no success/info toast (the
    // `match` returns null for the 'unchanged' branch).
    $user = User::factory()->active()->create();
    $before = $user->fresh()->updated_at;

    // Travel forward so any UPDATE would bump the timestamp visibly.
    $this->travel(2)->seconds();

    $response = $this->actingAs($user)->post('/active-mode', ['active' => true]);

    $response->assertRedirect();

    $fresh = $user->fresh();
    expect($fresh->is_active_mode)->toBeTrue()
        ->and($fresh->updated_at->equalTo($before))->toBeTrue();

    // No success/info toast — the match expression returns null for 'unchanged'.
    $response->assertInertiaFlash('toast', null);
});

test('same-state idempotency holds for the inactive side too', function () {
    $user = User::factory()->inactive()->create();
    $before = $user->fresh()->updated_at;

    $this->travel(2)->seconds();

    $response = $this->actingAs($user)->post('/active-mode', ['active' => false]);

    $response->assertRedirect();

    $fresh = $user->fresh();
    expect($fresh->is_active_mode)->toBeFalse()
        ->and($fresh->updated_at->equalTo($before))->toBeTrue();

    $response->assertInertiaFlash('toast', null);
});

// ─── Validation ───────────────────────────────────────────────────────────

test('missing active key returns 422', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/active-mode', [])
        ->assertJsonValidationErrors('active');
});

test('non-boolean active value is rejected', function (mixed $bad) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/active-mode', ['active' => $bad])
        ->assertJsonValidationErrors('active');
})->with([
    'string' => 'yes',
    'array' => [[]],
    'null' => null,
]);
