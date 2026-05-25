<?php

use App\Actions\LinkedAccount\RequestLinkVerificationAction;
use App\Enums\LinkedAccountProvider;
use App\Models\LinkedAccount;
use App\Models\PendingVerification;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| RequestLinkVerificationAction (M8 Phase 1, refactored M18 Phase 3)
|--------------------------------------------------------------------------
|
| Generates a bio-code and upserts a `pending_verifications` row for the
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

    $pending = $user->refresh()->pendingVerification;
    expect($pending)->not->toBeNull();
    expect($pending->provider)->toBe(LinkedAccountProvider::ChessCom);
    expect($pending->username)->toBe('alice');
    expect($pending->code)->toBe($code);
    expect($pending->expires_at->isFuture())->toBeTrue();
});

test('normalises username to lowercase + trimmed', function () {
    $user = User::factory()->create();

    app(RequestLinkVerificationAction::class)->handle(
        $user,
        LinkedAccountProvider::Lichess,
        '  AliceWonderland  ',
    );

    expect($user->fresh()->pendingVerification->username)->toBe('alicewonderland');
});

test('overwrites existing pending state on second request', function () {
    $user = User::factory()->create();
    $action = app(RequestLinkVerificationAction::class);

    $firstCode = $action->handle($user, LinkedAccountProvider::ChessCom, 'first');
    $secondCode = $action->handle($user, LinkedAccountProvider::Lichess, 'second');

    expect($firstCode)->not->toBe($secondCode);

    // UNIQUE(user_id) on pending_verifications means the second request
    // overwrites the first — never two rows in flight per user.
    expect(PendingVerification::query()->where('user_id', $user->id)->count())->toBe(1);

    $pending = $user->fresh()->pendingVerification;
    expect($pending->provider)->toBe(LinkedAccountProvider::Lichess);
    expect($pending->username)->toBe('second');
    expect($pending->code)->toBe($secondCode);
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
    $user = User::factory()->withChessCom('alice')->create();

    expect(fn () => app(RequestLinkVerificationAction::class)->handle(
        $user,
        LinkedAccountProvider::ChessCom,
        'newhandle',
    ))->toThrow(ValidationException::class);
});

test('lets user request different provider while one is already verified', function () {
    $user = User::factory()->withChessCom('alice')->create();

    $code = app(RequestLinkVerificationAction::class)->handle(
        $user,
        LinkedAccountProvider::Lichess,
        'alice_on_lichess',
    );

    expect($code)->toStartWith('stakly-');
    expect($user->fresh()->pendingVerification->provider)->toBe(LinkedAccountProvider::Lichess);
});

test('rejects when another user has verified the same external username', function () {
    User::factory()->withChessCom('taken')->create();

    $secondUser = User::factory()->create();

    expect(fn () => app(RequestLinkVerificationAction::class)->handle(
        $secondUser,
        LinkedAccountProvider::ChessCom,
        'taken',
    ))->toThrow(ValidationException::class);
});

test('allows username that another user only has pending (not yet verified)', function () {
    // Another user has the username pending but never finished verification —
    // their `pending_verifications` row exists, but no `linked_accounts` row.
    $contestor = User::factory()->create();
    PendingVerification::create([
        'user_id' => $contestor->id,
        'provider' => LinkedAccountProvider::ChessCom->value,
        'username' => 'contested',
        'code' => 'stakly-XXXXXXXXXX',
        'expires_at' => now()->addMinutes(15),
    ]);

    $newUser = User::factory()->create();

    $code = app(RequestLinkVerificationAction::class)->handle(
        $newUser,
        LinkedAccountProvider::ChessCom,
        'contested',
    );

    expect($code)->toStartWith('stakly-');
    // Pending row created for the new user — but the contestor's pending
    // row also still exists. UNIQUE(user_id) means one row per user, but
    // two users can both have pending rows for the same external username
    // (only verification commits resolve the race).
    expect(LinkedAccount::query()->where('username', 'contested')->count())->toBe(0);
});
