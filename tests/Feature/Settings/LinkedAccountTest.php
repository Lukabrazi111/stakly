<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| LinkedAccountController (M8 Phase 1)
|--------------------------------------------------------------------------
|
| HTTP-level tests for /settings/linked-accounts — covers route auth,
| request → pending state, verify happy + sad paths, unlink, throttle.
| Business-logic correctness is asserted in the per-Action tests.
|
*/

// ─── auth gating ────────────────────────────────────────────────────────

test('settings page requires authentication', function () {
    $this->get('/settings/linked-accounts')->assertRedirect(route('login'));
});

test('settings page requires verified email', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/settings/linked-accounts')
        ->assertRedirect();
});

test('settings page renders for verified users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/linked-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/linked-accounts')
            ->has('providers', 2)
            ->where('providers.0.value', 'chess_com')
            ->where('providers.1.value', 'lichess')
            ->where('pending', null)
        );
});

test('settings page surfaces existing linked usernames', function () {
    $user = User::factory()->create([
        'chess_com_username' => 'alice',
        'chess_com_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/settings/linked-accounts')
        ->assertInertia(fn ($page) => $page
            ->where('providers.0.username', 'alice')
            ->where('providers.0.verifiedAt', fn ($v) => $v !== null)
        );
});

test('settings page surfaces pending verification state', function () {
    $user = User::factory()->create([
        'pending_verification_provider' => 'chess_com',
        'pending_verification_username' => 'alice',
        'pending_verification_code' => 'stakly-ABCDEFGHJK',
        'pending_verification_expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($user)
        ->get('/settings/linked-accounts')
        ->assertInertia(fn ($page) => $page
            ->where('pending.provider', 'chess_com')
            ->where('pending.username', 'alice')
            ->where('pending.code', 'stakly-ABCDEFGHJK')
        );
});

// ─── request (POST) ─────────────────────────────────────────────────────

test('store generates a code and stores pending state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/linked-accounts', [
            'provider' => 'chess_com',
            'username' => 'alice',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/linked-accounts');

    $user->refresh();
    expect($user->pending_verification_provider)->toBe('chess_com');
    expect($user->pending_verification_username)->toBe('alice');
    expect($user->pending_verification_code)->toStartWith('stakly-');
});

test('store rejects invalid provider', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/linked-accounts', [
            'provider' => 'fortnite',
            'username' => 'alice',
        ])
        ->assertSessionHasErrors('provider');
});

test('store rejects username with invalid format', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/linked-accounts', [
            'provider' => 'chess_com',
            'username' => 'has spaces!',
        ])
        ->assertSessionHasErrors('username');
});

test('store rejects when username is already linked by another user', function () {
    User::factory()->create([
        'chess_com_username' => 'taken',
        'chess_com_verified_at' => now(),
    ]);

    $secondUser = User::factory()->create();

    $this->actingAs($secondUser)
        ->post('/settings/linked-accounts', [
            'provider' => 'chess_com',
            'username' => 'taken',
        ])
        ->assertSessionHasErrors('username');
});

// ─── verify (POST) ──────────────────────────────────────────────────────

test('verify happy path: marks user verified + clears pending', function () {
    $user = User::factory()->create([
        'pending_verification_provider' => 'chess_com',
        'pending_verification_username' => 'alice',
        'pending_verification_code' => 'stakly-ABCDEFGHJK',
        'pending_verification_expires_at' => now()->addMinutes(15),
    ]);

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            'location' => 'stakly-ABCDEFGHJK',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post('/settings/linked-accounts/verify')
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/linked-accounts');

    $user->refresh();
    expect($user->chess_com_username)->toBe('alice');
    expect($user->chess_com_verified_at)->not->toBeNull();
    expect($user->pending_verification_provider)->toBeNull();
});

test('verify with no pending state redirects without crashing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/linked-accounts/verify')
        ->assertSessionHasErrors('provider');
});

test('verify is throttled at 6 requests per minute', function () {
    // The verify route uses Laravel's `throttle:6,1` middleware. We hit it 7
    // times back-to-back; the 7th must 429.
    $user = User::factory()->create();

    Http::fake();
    RateLimiter::clear('api'); // safety in case other tests bled state

    foreach (range(1, 6) as $i) {
        $this->actingAs($user)->post('/settings/linked-accounts/verify');
    }

    $this->actingAs($user)
        ->post('/settings/linked-accounts/verify')
        ->assertStatus(429);
});

// ─── unlink (DELETE) ────────────────────────────────────────────────────

test('unlink clears verified columns for the named provider only', function () {
    $user = User::factory()->create([
        'chess_com_username' => 'alice',
        'chess_com_verified_at' => now(),
        'lichess_username' => 'alice_lichess',
        'lichess_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete('/settings/linked-accounts/chess_com')
        ->assertRedirect('/settings/linked-accounts');

    $user->refresh();
    expect($user->chess_com_username)->toBeNull();
    expect($user->chess_com_verified_at)->toBeNull();
    // Lichess survives.
    expect($user->lichess_username)->toBe('alice_lichess');
    expect($user->lichess_verified_at)->not->toBeNull();
});

test('unlink with unknown provider 404s via enum route binding', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/linked-accounts/fortnite')
        ->assertNotFound();
});

// ─── cancelPending (DELETE /pending) ─────────────────────────────────────

test('cancel pending nulls all pending columns', function () {
    $user = User::factory()->create([
        'pending_verification_provider' => 'chess_com',
        'pending_verification_username' => 'mistyped',
        'pending_verification_code' => 'stakly-ABCDEFGHJK',
        'pending_verification_expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($user)
        ->delete('/settings/linked-accounts/pending')
        ->assertRedirect('/settings/linked-accounts');

    $user->refresh();
    expect($user->pending_verification_provider)->toBeNull();
    expect($user->pending_verification_username)->toBeNull();
    expect($user->pending_verification_code)->toBeNull();
    expect($user->pending_verification_expires_at)->toBeNull();
});

test('cancel pending leaves verified linked accounts untouched', function () {
    $user = User::factory()->create([
        'chess_com_username' => 'alice',
        'chess_com_verified_at' => now()->subDay(),
        'pending_verification_provider' => 'lichess',
        'pending_verification_username' => 'mistyped',
        'pending_verification_code' => 'stakly-LMNOPQRSTUV',
        'pending_verification_expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($user)
        ->delete('/settings/linked-accounts/pending')
        ->assertRedirect('/settings/linked-accounts');

    $user->refresh();
    // Verified chess.com link survives.
    expect($user->chess_com_username)->toBe('alice');
    expect($user->chess_com_verified_at)->not->toBeNull();
    // Pending Lichess verification cleared.
    expect($user->pending_verification_provider)->toBeNull();
});

test('cancel pending is a noop when no pending state exists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/linked-accounts/pending')
        ->assertRedirect('/settings/linked-accounts');

    // Nothing to assert beyond "didn't crash and redirects cleanly."
    expect($user->fresh()->pending_verification_provider)->toBeNull();
});
