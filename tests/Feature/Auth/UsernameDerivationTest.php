<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;

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

// ─── Name validation: rejects non-Latin / numeric / symbol-only inputs ────

test('disallowed name characters are rejected at validation', function (string $name) {
    expect(fn () => deriveUsernameVia($name, 'reject@example.com'))
        ->toThrow(ValidationException::class);

    // The name was rejected before any user row was written.
    expect(User::query()->where('email', 'reject@example.com')->exists())->toBeFalse();
})->with([
    'pure emojis' => '🎉🎉🎉',
    'pure punctuation symbols' => '!!!',
    'pure numbers' => '12345',
    'pure dots (no letter)' => '...',
    'pure dashes (no letter)' => '---',
    'numbers mixed into a name' => 'John2',
    'accented letters (Latin script but with marks)' => 'François',
    'cyrillic' => 'Дмитрий',
    'cjk' => '李明',
    'arabic' => 'محمد',
]);

// ─── Name validation: standard name punctuation is accepted ───────────────

test('names with allowed punctuation pass validation and derive cleanly', function (string $name, string $expected, string $email) {
    $user = deriveUsernameVia($name, $email);

    expect($user->username)->toBe($expected);
})->with([
    'apostrophe (O\'Brien)' => ['Mary O\'Brien', 'mary-obrien', 'apo@example.com'],
    'hyphen (Anne-Marie)' => ['Anne-Marie', 'anne-marie', 'hyp@example.com'],
    'period (Dr. Smith)' => ['Dr. Smith', 'dr-smith', 'per@example.com'],
    'mixed punctuation' => ['Dr. O\'Brien-Jones', 'dr-obrien-jones', 'mix@example.com'],
]);

// ─── Final shape: every accepted username matches the canonical regex ────

test('every accepted username matches /^[a-z0-9-]+$/ and fits the 30-char cap', function () {
    deriveUsernameVia('Mary Jane', 'mary@example.com');
    deriveUsernameVia('Aleksander Konstantinovich Vladimirovich', 'long@example.com');
    deriveUsernameVia('Admin', 'admin@example.com');
    deriveUsernameVia('Mary O\'Brien', 'obrien@example.com');

    foreach (User::query()->pluck('username')->all() as $username) {
        expect($username)->toMatch('/^[a-z0-9-]+$/')
            ->and(strlen($username))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(30);
    }
});
