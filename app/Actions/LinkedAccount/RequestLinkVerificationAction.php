<?php

namespace App\Actions\LinkedAccount;

use App\Enums\LinkedAccountProvider;
use App\Models\LinkedAccount;
use App\Models\PendingVerification;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Step 1 of the bio-code linking flow: generate a code, upsert a
 * `PendingVerification` row for the user, return the code for display.
 *
 * Throws `ValidationException` on three rejectable conditions:
 *   - username doesn't match the provider's allowed format
 *   - another Stakly user already has this username verified
 *   - this user is already verified for this provider (must unlink first)
 *
 * Starting a verification upserts on (user_id) — a user who started a
 * chess.com flow and then changes their mind to Lichess overwrites the
 * pending row cleanly, no stuck state. Code TTL is configurable via
 * `stakly.link_verification_ttl_minutes` (default 15).
 */
class RequestLinkVerificationAction
{
    public function handle(User $user, LinkedAccountProvider $provider, string $username): string
    {
        $username = $this->normalize($username);

        $this->assertValidFormat($provider, $username);
        $this->assertUserNotAlreadyVerified($provider, $user);
        $this->assertUsernameNotClaimed($provider, $username, $user);

        $code = $this->generateCode();
        $ttlMinutes = (int) config('stakly.link_verification_ttl_minutes', 15);

        // Upsert on user_id — the UNIQUE constraint on
        // `pending_verifications.user_id` ensures at most one in-flight
        // verification per user. `updateOrCreate` translates cleanly to
        // an INSERT … ON CONFLICT under the hood.
        PendingVerification::updateOrCreate(
            ['user_id' => $user->id],
            [
                'provider' => $provider->value,
                'username' => $username,
                'code' => $code,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ],
        );

        return $code;
    }

    private function normalize(string $username): string
    {
        return strtolower(trim($username));
    }

    private function assertValidFormat(LinkedAccountProvider $provider, string $username): void
    {
        if (! preg_match($provider->usernamePattern(), $username)) {
            throw ValidationException::withMessages([
                'username' => "That isn't a valid {$provider->displayName()} username.",
            ]);
        }
    }

    private function assertUserNotAlreadyVerified(LinkedAccountProvider $provider, User $user): void
    {
        $alreadyVerified = $user->linkedAccounts()
            ->where('provider', $provider->value)
            ->exists();

        if ($alreadyVerified) {
            throw ValidationException::withMessages([
                'provider' => "You've already linked a {$provider->displayName()} account. Unlink it first to link a different one.",
            ]);
        }
    }

    private function assertUsernameNotClaimed(LinkedAccountProvider $provider, string $username, User $user): void
    {
        $claimed = LinkedAccount::query()
            ->where('provider', $provider->value)
            ->where('username', $username)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($claimed) {
            throw ValidationException::withMessages([
                'username' => "This {$provider->displayName()} account is already linked to another Stakly user.",
            ]);
        }
    }

    private function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        $len = strlen($alphabet);
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, $len - 1)];
        }

        return "stakly-{$code}";
    }
}
