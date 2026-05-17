<?php

use App\Actions\LinkedAccount\RequestLinkVerificationAction;
use App\Enums\LinkedAccountProvider;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| RequestLinkVerificationAction (M8 Phase 1)
|--------------------------------------------------------------------------
|
| Generates a bio-code and stores it as pending verification state on the
| user. Validates username format + cross-user uniqueness + already-linked.
|
*/

test('generates a stakly-prefixed code and stores pending state', function () {
    $user = User::factory()->create();

    $code = app(RequestLinkVerificationAction::class)->handle(
        $user,
        LinkedAccountProvider::ChessCom,
        'alice',
    );

    expect($code)->toStartWith('stakly-');
    expect(strlen($code))->toBe(17); // 'stakly-' (7) + 10 alphanumeric

    $user->refresh();
    expect($user->pending_verification_provider)->toBe('chess_com');
    expect($user->pending_verification_username)->toBe('alice');
    expect($user->pending_verification_code)->toBe($code);
    expect($user->pending_verification_expires_at)->not->toBeNull();
    expect($user->pending_verification_expires_at->isFuture())->toBeTrue();
});

test('normalises username to lowercase + trimmed', function () {
    $user = User::factory()->create();

    app(RequestLinkVerificationAction::class)->handle(
        $user,
        LinkedAccountProvider::Lichess,
        '  AliceWonderland  ',
    );

    expect($user->fresh()->pending_verification_username)->toBe('alicewonderland');
});

test('overwrites existing pending state on second request', function () {
    $user = User::factory()->create();
    $action = app(RequestLinkVerificationAction::class);

    $firstCode = $action->handle($user, LinkedAccountProvider::ChessCom, 'first');
    $secondCode = $action->handle($user, LinkedAccountProvider::Lichess, 'second');

    expect($firstCode)->not->toBe($secondCode);

    $user->refresh();
    expect($user->pending_verification_provider)->toBe('lichess');
    expect($user->pending_verification_username)->toBe('second');
    expect($user->pending_verification_code)->toBe($secondCode);
});

test('rejects invalid chess.com username format', function () {
    $user = User::factory()->create();

    expect(fn () => app(RequestLinkVerificationAction::class)->handle(
        $user,
        LinkedAccountProvider::ChessCom,
        'ab', // too short — chess.com min is 3
    ))->toThrow(ValidationException::class);
});

test('rejects username with spaces or special characters', function () {
    $user = User::factory()->create();

    expect(fn () => app(RequestLinkVerificationAction::class)->handle(
        $user,
        LinkedAccountProvider::Lichess,
        'has spaces',
    ))->toThrow(ValidationException::class);
});

test('rejects when this user is already verified for the provider', function () {
    $user = User::factory()->create([
        'chess_com_username' => 'alice',
        'chess_com_verified_at' => now(),
    ]);

    expect(fn () => app(RequestLinkVerificationAction::class)->handle(
        $user,
        LinkedAccountProvider::ChessCom,
        'newhandle',
    ))->toThrow(ValidationException::class);
});

test('lets user request different provider while one is already verified', function () {
    $user = User::factory()->create([
        'chess_com_username' => 'alice',
        'chess_com_verified_at' => now(),
    ]);

    $code = app(RequestLinkVerificationAction::class)->handle(
        $user,
        LinkedAccountProvider::Lichess,
        'alice_on_lichess',
    );

    expect($code)->toStartWith('stakly-');
    expect($user->fresh()->pending_verification_provider)->toBe('lichess');
});

test('rejects when another user has verified the same external username', function () {
    User::factory()->create([
        'chess_com_username' => 'taken',
        'chess_com_verified_at' => now(),
    ]);

    $secondUser = User::factory()->create();

    expect(fn () => app(RequestLinkVerificationAction::class)->handle(
        $secondUser,
        LinkedAccountProvider::ChessCom,
        'taken',
    ))->toThrow(ValidationException::class);
});

test('allows username that another user only has pending (not yet verified)', function () {
    // Another user has the username pending but never finished verification.
    User::factory()->create([
        'chess_com_username' => null,
        'chess_com_verified_at' => null,
        'pending_verification_provider' => 'chess_com',
        'pending_verification_username' => 'contested',
        'pending_verification_code' => 'stakly-XXXXXXXXXX',
        'pending_verification_expires_at' => now()->addMinutes(15),
    ]);

    $newUser = User::factory()->create();

    $code = app(RequestLinkVerificationAction::class)->handle(
        $newUser,
        LinkedAccountProvider::ChessCom,
        'contested',
    );

    expect($code)->toStartWith('stakly-');
});
