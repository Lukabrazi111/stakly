<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
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
     * Total username must fit varchar(30); last-resort suffix `-{Str::random(6)}` is 7 chars, so base ≤ 23.
     */
    private const MAX_BASE_LENGTH = 23;

    private const MAX_NUMERIC_ATTEMPTS = 10;

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ], $this->profileMessages())->validate();

        $user = $this->createWithUniqueUsername($input);

        // Provision the deposit address through the active gateway driver
        // (mock locally, real custodial provider once wired) instead of minting
        // a mock here — keeps the mock-vs-real seam in one config-driven place.
        // Resolved from the container so the no-arg `new CreateNewUser` used in
        // tests and by Fortify keeps working.
        app(PaymentGateway::class)->ensureDepositAccount($user);

        return $user;
    }

    /**
     * The database UNIQUE constraint on `username` is the authority — retry with the next suffix on violation.
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

        // Last resort after 10 consecutive same-base collisions.
        $lastResort = "{$base}-".Str::lower(Str::random(6));

        if ($user = $this->tryInsertUser($input, $lastResort)) {
            return $user;
        }

        throw new RuntimeException(
            "Could not generate a unique username for base '{$base}' after all attempts."
        );
    }

    /**
     * Wrap in `DB::transaction` so a unique-violation under `RefreshDatabase` rolls back via SAVEPOINT,
     * leaving the outer test transaction intact for the next retry.
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
            if ($this->isUsernameCollision($e)) {
                return null;
            }

            throw $e;
        }
    }

    private function deriveBaseUsername(string $name): string
    {
        $slug = trim(Str::slug($name), '-');

        if (strlen($slug) < 3) {
            return 'user';
        }

        $truncated = rtrim(mb_substr($slug, 0, self::MAX_BASE_LENGTH), '-');

        return strlen($truncated) < 3 ? 'user' : $truncated;
    }

    private function isReserved(string $username): bool
    {
        return in_array($username, User::RESERVED_USERNAMES, true);
    }

    /**
     * Two-layer check (SQLSTATE + column name in message) so we never swallow an unrelated SQL error.
     */
    private function isUsernameCollision(QueryException $e): bool
    {
        $isUniqueViolation = in_array((string) $e->getCode(), ['23505', '23000'], true);

        return $isUniqueViolation && str_contains($e->getMessage(), 'username');
    }
}
