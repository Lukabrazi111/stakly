<?php

use App\Actions\LinkedAccount\VerifyLinkedAccountAction;
use App\Models\User;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| VerifyLinkedAccountAction (M8 Phase 1)
|--------------------------------------------------------------------------
|
| Fetches the user's profile from the provider, matches the pending code
| against the target field (chess.com `location`, Lichess `profile.bio`),
| and marks the account verified. Sentinel returns drive controller toasts.
|
*/

function pendingUserForChessCom(string $code = 'stakly-ABCDEFGHJK', string $username = 'alice'): User
{
    return User::factory()->create([
        'pending_verification_provider' => 'chess_com',
        'pending_verification_username' => $username,
        'pending_verification_code' => $code,
        'pending_verification_expires_at' => now()->addMinutes(15),
    ]);
}

function pendingUserForLichess(string $code = 'stakly-LMNOPQRSTUV', string $username = 'alice'): User
{
    return User::factory()->create([
        'pending_verification_provider' => 'lichess',
        'pending_verification_username' => $username,
        'pending_verification_code' => $code,
        'pending_verification_expires_at' => now()->addMinutes(15),
    ]);
}

// ─── chess.com happy path ────────────────────────────────────────────────

test('chess.com verified happy path: marks user, clears pending', function () {
    $user = pendingUserForChessCom();

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            'location' => 'My country — stakly-ABCDEFGHJK',
        ], 200),
    ]);

    $result = app(VerifyLinkedAccountAction::class)->handle($user);

    expect($result)->toBe('verified');

    $user->refresh();
    expect($user->chess_com_username)->toBe('alice');
    expect($user->chess_com_verified_at)->not->toBeNull();
    expect($user->pending_verification_provider)->toBeNull();
    expect($user->pending_verification_code)->toBeNull();
});

test('chess.com canonical username from response is persisted (not the user-typed one)', function () {
    $user = pendingUserForChessCom(username: 'alice');

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            // chess.com returns canonical casing — we follow it.
            'username' => 'Alice',
            'location' => 'stakly-ABCDEFGHJK',
        ], 200),
    ]);

    app(VerifyLinkedAccountAction::class)->handle($user);

    // We lowercase the canonical for our column.
    expect($user->fresh()->chess_com_username)->toBe('alice');
});

test('chess.com code matching is case-insensitive', function () {
    $user = pendingUserForChessCom(code: 'stakly-ABCDEFGHJK');

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            'location' => 'wrote it lowercase: STAKLY-abcdefghjk',
        ], 200),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('verified');
});

// ─── Lichess happy path ──────────────────────────────────────────────────

test('Lichess verified happy path: reads profile.bio', function () {
    $user = pendingUserForLichess();

    Http::fake([
        'lichess.org/api/user/alice' => Http::response([
            'username' => 'alice',
            'profile' => ['bio' => 'My bio — stakly-LMNOPQRSTUV'],
        ], 200),
    ]);

    $result = app(VerifyLinkedAccountAction::class)->handle($user);

    expect($result)->toBe('verified');
    expect($user->fresh()->lichess_username)->toBe('alice');
});

test('Lichess handles missing profile object gracefully', function () {
    $user = pendingUserForLichess();

    Http::fake([
        'lichess.org/api/user/alice' => Http::response([
            'username' => 'alice',
            // No `profile` key at all — Lichess omits it when empty.
        ], 200),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('code-not-found');
});

// ─── Failure sentinels ───────────────────────────────────────────────────

test('returns expired when pending TTL is past', function () {
    $user = pendingUserForChessCom();
    $user->update(['pending_verification_expires_at' => now()->subMinute()]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('expired');
});

test('returns profile-not-found when provider returns 404', function () {
    $user = pendingUserForChessCom(username: 'nobody');

    Http::fake([
        'api.chess.com/pub/player/nobody' => Http::response([], 404),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('profile-not-found');
});

test('returns code-not-found when bio field omits the code', function () {
    $user = pendingUserForChessCom(code: 'stakly-NOTPRESENT');

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            'location' => 'My country only',
        ], 200),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('code-not-found');
});

test('returns code-not-found when bio field is empty / missing', function () {
    $user = pendingUserForChessCom();

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            // No location field — chess.com omits when empty.
        ], 200),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('code-not-found');
});

test('returns username-claimed when another user wins the UNIQUE race', function () {
    User::factory()->create([
        'chess_com_username' => 'alice',
        'chess_com_verified_at' => now(),
    ]);

    $user = pendingUserForChessCom();

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            'location' => 'stakly-ABCDEFGHJK',
        ], 200),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('username-claimed');
});

test('bubbles ProviderUnavailableException when provider 500s', function () {
    $user = pendingUserForChessCom();

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([], 503),
    ]);

    expect(fn () => app(VerifyLinkedAccountAction::class)->handle($user))
        ->toThrow(ProviderUnavailableException::class);
});

test('throws ValidationException when no pending verification exists', function () {
    $user = User::factory()->create(); // No pending fields set.

    expect(fn () => app(VerifyLinkedAccountAction::class)->handle($user))
        ->toThrow(ValidationException::class);
});

test('chess.com client sends User-Agent header from config', function () {
    config()->set('stakly.chess_com_user_agent', 'StaklyTest/1.0 (test@stakly.test)');

    $user = pendingUserForChessCom();

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            'location' => 'stakly-ABCDEFGHJK',
        ], 200),
    ]);

    app(VerifyLinkedAccountAction::class)->handle($user);

    Http::assertSent(function ($request) {
        return $request->hasHeader('User-Agent', 'StaklyTest/1.0 (test@stakly.test)');
    });
});
