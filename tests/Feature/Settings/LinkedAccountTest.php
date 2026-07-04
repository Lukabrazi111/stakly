<?php

use App\Enums\LinkedAccountProvider;
use App\Models\PendingVerification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| LinkedAccountController (M8 Phase 1, refactored M18 Phase 3)
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
            // M15 P2 — three providers: chess.com + Lichess (bio-code) + FACEIT (oauth).
            ->has('providers', 3)
            ->where('providers.0.value', 'chess_com')
            ->where('providers.0.verificationType', 'bio_code')
            ->where('providers.1.value', 'lichess')
            ->where('providers.1.verificationType', 'bio_code')
            ->where('providers.2.value', 'faceit')
            ->where('providers.2.verificationType', 'oauth')
            ->where('providers.2.oauthRedirectUrl', fn ($url) => str_ends_with((string) $url, '/auth/faceit/redirect'))
            ->where('pending', null)
        );
});

test('settings page surfaces existing linked usernames', function () {
    $user = User::factory()->withChessCom('alice')->create();

    $this->actingAs($user)
        ->get('/settings/linked-accounts')
        ->assertInertia(fn ($page) => $page
            ->where('providers.0.username', 'alice')
            ->where('providers.0.verifiedAt', fn ($v) => $v !== null)
        );
});

test('settings page surfaces pending verification state', function () {
    $user = User::factory()->create();
    PendingVerification::create([
        'user_id' => $user->id,
        'provider' => LinkedAccountProvider::ChessCom->value,
        'username' => 'alice',
        'code' => 'stakly-ABCDEFGHJK',
        'expires_at' => now()->addMinutes(15),
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
        ->assertRedirect(route('linked-accounts.edit'));

    $pending = $user->fresh()->pendingVerification;
    expect($pending)->not->toBeNull();
    expect($pending->provider)->toBe(LinkedAccountProvider::ChessCom);
    expect($pending->username)->toBe('alice');
    expect($pending->code)->toStartWith('stakly-');
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
    User::factory()->withChessCom('taken')->create();

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
    // M41 P3b — verify dispatches the chess rating-capture job; fake the queue
    // so the sync runner doesn't make a real /stats call (capture is covered in
    // ChessRatingCaptureTest).
    Queue::fake();

    $user = User::factory()->create();
    PendingVerification::create([
        'user_id' => $user->id,
        'provider' => LinkedAccountProvider::ChessCom->value,
        'username' => 'alice',
        'code' => 'stakly-ABCDEFGHJK',
        'expires_at' => now()->addMinutes(15),
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
        ->assertRedirect(route('linked-accounts.edit'));

    $user->refresh();
    expect($user->chess_com_username)->toBe('alice');
    expect($user->chess_com_verified_at)->not->toBeNull();
    expect($user->pendingVerification)->toBeNull();
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

test('unlink clears the verified link for the named provider only', function () {
    $user = User::factory()
        ->withChessCom('alice')
        ->withLichess('alice_lichess')
        ->create();

    $this->actingAs($user)
        ->delete('/settings/linked-accounts/chess_com')
        ->assertRedirect(route('linked-accounts.edit'));

    $user->refresh()->load('linkedAccounts');
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

test('cancel pending deletes the pending row', function () {
    $user = User::factory()->create();
    PendingVerification::create([
        'user_id' => $user->id,
        'provider' => LinkedAccountProvider::ChessCom->value,
        'username' => 'mistyped',
        'code' => 'stakly-ABCDEFGHJK',
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($user)
        ->delete('/settings/linked-accounts/pending')
        ->assertRedirect(route('linked-accounts.edit'));

    expect($user->fresh()->pendingVerification)->toBeNull();
});

test('cancel pending leaves verified linked accounts untouched', function () {
    $user = User::factory()->withChessCom('alice')->create();
    PendingVerification::create([
        'user_id' => $user->id,
        'provider' => LinkedAccountProvider::Lichess->value,
        'username' => 'mistyped',
        'code' => 'stakly-LMNOPQRSTUV',
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->actingAs($user)
        ->delete('/settings/linked-accounts/pending')
        ->assertRedirect(route('linked-accounts.edit'));

    $user->refresh()->load('linkedAccounts');
    // Verified chess.com link survives.
    expect($user->chess_com_username)->toBe('alice');
    expect($user->chess_com_verified_at)->not->toBeNull();
    // Pending Lichess verification cleared.
    expect($user->pendingVerification)->toBeNull();
});

test('cancel pending is a noop when no pending state exists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/linked-accounts/pending')
        ->assertRedirect(route('linked-accounts.edit'));

    // Nothing to assert beyond "didn't crash and redirects cleanly."
    expect($user->fresh()->pendingVerification)->toBeNull();
});
