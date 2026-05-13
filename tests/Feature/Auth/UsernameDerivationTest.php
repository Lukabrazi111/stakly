<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;

/**
 * Direct invocations of the registration action. Bypasses the HTTP layer so
 * each test stays independent of Fortify's post-register auto-login (which
 * would otherwise pollute session state between sequential register calls).
 */
function deriveUsernameVia(string $name, string $email): User
{
    return (new CreateNewUser)->create([
        'name' => $name,
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
}

// ─── Happy path: slug from name ───────────────────────────────────────────

test('derives username from name via Str::slug', function () {
    $user = deriveUsernameVia('John Smith', 'john@example.com');

    expect($user->username)->toBe('john-smith');
});

// ─── Collision: suffix loop appends -1, -2, ... ───────────────────────────

test('duplicate names cycle through -1, -2, ... suffixes', function () {
    $first = deriveUsernameVia('John Smith', 'john1@example.com');
    $second = deriveUsernameVia('John Smith', 'john2@example.com');
    $third = deriveUsernameVia('John Smith', 'john3@example.com');

    expect([$first->username, $second->username, $third->username])
        ->toBe(['john-smith', 'john-smith-1', 'john-smith-2']);
});

// ─── Empty-slug fallback ──────────────────────────────────────────────────

test('emoji-only names fall back to the `user` base — first user gets `user-1`', function () {
    // Str::slug transliterates Cyrillic / Latin-accented chars to ASCII, so
    // those don't produce empty slugs. Only pure-symbol inputs (emojis,
    // punctuation) reliably collapse to empty. `user` is reserved, so the
    // first empty-slug registrant gets `user-1`, not the bare `user`.
    $user = deriveUsernameVia('🎉🎉🎉', 'emoji@example.com');

    expect($user->username)->toBe('user-1');
});

test('special-char-only names also fall back to user-1', function () {
    $user = deriveUsernameVia('!!!', 'specials@example.com');

    expect($user->username)->toBe('user-1');
});

test('multiple empty-slug registrations cycle through user-1, user-2, ...', function () {
    $first = deriveUsernameVia('!!!', 'specials1@example.com');
    $second = deriveUsernameVia('***', 'specials2@example.com');
    $third = deriveUsernameVia('🎉🎉🎉', 'emoji@example.com');

    expect([$first->username, $second->username, $third->username])
        ->toBe(['user-1', 'user-2', 'user-3']);
});

// ─── Length cap: long names truncate cleanly to ≤ 30 chars ────────────────

test('overlong names truncate to MAX_BASE_LENGTH (23 chars)', function () {
    // Slug 'aleksander-konstantinovich-vladimirovich' is 40 chars; truncated
    // to 23 it's 'aleksander-konstantinov' (no trailing hyphen).
    $user = deriveUsernameVia('Aleksander Konstantinovich Vladimirovich', 'long@example.com');

    expect($user->username)->toBe('aleksander-konstantinov')
        ->and(strlen($user->username))->toBe(23);
});

// ─── Reserved names: cannot register as 'admin', 'support', etc. ──────────

test('a reserved base is pushed into the collision loop and gets -1', function () {
    $user = deriveUsernameVia('Admin', 'admin@example.com');

    expect($user->username)->toBe('admin-1');
});

test('reserved-name collisions still increment cleanly', function () {
    $first = deriveUsernameVia('Admin', 'admin1@example.com');
    $second = deriveUsernameVia('Admin', 'admin2@example.com');

    expect([$first->username, $second->username])->toBe(['admin-1', 'admin-2']);
});

// ─── Final shape: every derived username matches the canonical regex ─────

test('every derived username matches /^[a-z0-9-]+$/ and fits the 30-char cap', function () {
    deriveUsernameVia('Mary Jane', 'mary@example.com');
    deriveUsernameVia('!!!', 'specials@example.com');
    deriveUsernameVia('Aleksander Konstantinovich Vladimirovich', 'long@example.com');
    deriveUsernameVia('Admin', 'admin@example.com');

    foreach (User::query()->pluck('username')->all() as $username) {
        expect($username)->toMatch('/^[a-z0-9-]+$/')
            ->and(strlen($username))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(30);
    }
});
