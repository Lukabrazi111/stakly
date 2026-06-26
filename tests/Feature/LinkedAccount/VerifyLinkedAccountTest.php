<?php

use App\Actions\LinkedAccount\VerifyLinkedAccountAction;
use App\Enums\LinkedAccountProvider;
use App\Models\PendingVerification;
use App\Models\User;
use App\Services\Provider\Exceptions\TransientProviderError;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

// M41 P3b — a successful verify now dispatches the chess rating-capture job.
// These tests assert the verify outcome, not the capture, so fake the queue
// (capture is covered in ChessRatingCaptureTest). Without this the sync queue
// would run the job inline and make a real provider call.
beforeEach(fn () => Queue::fake());

/*
|--------------------------------------------------------------------------
| VerifyLinkedAccountAction (M8 Phase 1, refactored M18 Phase 3)
|--------------------------------------------------------------------------
|
| Fetches the user's profile from the provider, matches the pending code
| against the target field (chess.com `location`, Lichess `profile.bio`),
| and inserts a `linked_accounts` row + deletes the pending row.
|
*/

function pendingUserForChessCom(string $code = 'stakly-ABCDEFGHJK', string $username = 'alice'): User
{
    $user = User::factory()->create();
    PendingVerification::create([
        'user_id' => $user->id,
        'provider' => LinkedAccountProvider::ChessCom->value,
        'username' => $username,
        'code' => $code,
        'expires_at' => now()->addMinutes(15),
    ]);

    return $user;
}

function pendingUserForLichess(string $code = 'stakly-LMNOPQRSTUV', string $username = 'alice'): User
{
    $user = User::factory()->create();
    PendingVerification::create([
        'user_id' => $user->id,
        'provider' => LinkedAccountProvider::Lichess->value,
        'username' => $username,
        'code' => $code,
        'expires_at' => now()->addMinutes(15),
    ]);

    return $user;
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
    expect($user->pendingVerification)->toBeNull();
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

    // We lowercase the canonical for our row.
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
    $user->pendingVerification->update(['expires_at' => now()->subMinute()]);

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
    User::factory()->withChessCom('alice')->create();

    $user = pendingUserForChessCom();

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([
            'username' => 'alice',
            'location' => 'stakly-ABCDEFGHJK',
        ], 200),
    ]);

    expect(app(VerifyLinkedAccountAction::class)->handle($user))->toBe('username-claimed');
});

test('bubbles TransientProviderError when provider 500s', function () {
    $user = pendingUserForChessCom();

    Http::fake([
        'api.chess.com/pub/player/alice' => Http::response([], 503),
    ]);

    expect(fn () => app(VerifyLinkedAccountAction::class)->handle($user))
        ->toThrow(TransientProviderError::class);
});

test('throws ValidationException when no pending verification exists', function () {
    $user = User::factory()->create(); // No pending row.

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
