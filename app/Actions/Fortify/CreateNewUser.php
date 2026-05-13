<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use RuntimeException;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Usernames that should never be assigned at registration. Covers known
     * system handles (admin/support/etc.) and route-segment names a future
     * `/users/{x}` URL might one day conflict with.
     */
    private const RESERVED_USERNAMES = [
        'admin', 'administrator', 'staff', 'support', 'help',
        'stakly', 'platform', 'system', 'root', 'null',
        'listings', 'settings', 'login', 'register', 'logout',
        'wallet', 'match', 'matches', 'api', 'users', 'user',
    ];

    /**
     * Base length budget. Total username must fit the column's varchar(30);
     * the last-resort suffix `-{Str::random(6)}` is 7 chars, so base ≤ 23.
     */
    private const MAX_BASE_LENGTH = 23;

    /**
     * Numeric suffix attempts before falling back to a random suffix.
     */
    private const MAX_NUMERIC_ATTEMPTS = 10;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return $this->createWithUniqueUsername($input);
    }

    /**
     * Create the user with a collision-safe username derived from `name`.
     * The database UNIQUE constraint on `username` is the authority — this
     * method just retries with the next suffix on violation.
     *
     * @param  array<string, string>  $input
     */
    private function createWithUniqueUsername(array $input): User
    {
        $base = $this->deriveBaseUsername($input['name']);

        // Reserved base → skip attempt 0 (the bare base) and start at "-1".
        $startAttempt = $this->isReserved($base) ? 1 : 0;

        for ($attempt = $startAttempt; $attempt < self::MAX_NUMERIC_ATTEMPTS; $attempt++) {
            $username = $attempt === 0 ? $base : "{$base}-{$attempt}";

            if ($user = $this->tryInsertUser($input, $username)) {
                return $user;
            }
        }

        // Last resort: 6-char lowercase-alphanumeric suffix. Reached only
        // after 10 consecutive same-base collisions — pathological.
        $lastResort = "{$base}-".Str::lower(Str::random(6));

        if ($user = $this->tryInsertUser($input, $lastResort)) {
            return $user;
        }

        throw new RuntimeException(
            "Could not generate a unique username for base '{$base}' after all attempts."
        );
    }

    /**
     * Attempt one INSERT inside a `DB::transaction` wrap. When the caller is
     * already inside a transaction (Fortify isn't, but tests using
     * `RefreshDatabase` are), Laravel creates a SAVEPOINT instead of a fresh
     * BEGIN — so a unique-violation rolls back just this attempt, leaving the
     * outer transaction intact for the next retry.
     *
     * @param  array<string, string>  $input
     */
    private function tryInsertUser(array $input, string $username): ?User
    {
        try {
            return DB::transaction(fn () => User::create([
                'name' => $input['name'],
                'username' => $username,
                'email' => $input['email'],
                'password' => $input['password'],
            ]));
        } catch (QueryException $e) {
            if (! $this->isUsernameCollision($e)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Slug `name` into a username base. Handles edge cases:
     *   - Empty / sub-3-char slug (non-Latin names, only special chars) → 'user'.
     *   - Over-budget slug → truncated to MAX_BASE_LENGTH.
     *   - Leading/trailing hyphens (slug or truncation byproducts) → trimmed.
     */
    private function deriveBaseUsername(string $name): string
    {
        $slug = trim(Str::slug($name), '-');

        if (strlen($slug) < 3) {
            return 'user';
        }

        $truncated = rtrim(mb_substr($slug, 0, self::MAX_BASE_LENGTH), '-');

        // Defensive: truncation could in theory collapse to under 3 chars if
        // the original input was bizarre. Fall back to 'user' in that case.
        return strlen($truncated) < 3 ? 'user' : $truncated;
    }

    private function isReserved(string $username): bool
    {
        return in_array($username, self::RESERVED_USERNAMES, true);
    }

    /**
     * Detect a unique-violation specifically on the `username` column. Two
     * layers so we never silently swallow an unrelated SQL error:
     *   1. SQLSTATE must be a unique-violation code (23505 PG, 23000 MySQL/SQLite)
     *   2. Error message must mention `username`
     */
    private function isUsernameCollision(QueryException $e): bool
    {
        $isUniqueViolation = in_array((string) $e->getCode(), ['23505', '23000'], true);

        return $isUniqueViolation && str_contains($e->getMessage(), 'username');
    }
}
