<?php

namespace App\Actions\LinkedAccount;

use App\Enums\LinkedAccountProvider;
use App\Models\User;
use App\Services\Provider\ChessComProfileClient;
use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\LichessProfileClient;
use App\Services\Provider\ProfileClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Step 2 of the bio-code linking flow: read pending state, fetch the
 * external profile, match the code, mark verified.
 *
 * Returns one of these string sentinels — the controller maps each to a
 * flash toast / form-error message:
 *
 *   'verified'           — happy path; `{provider}_verified_at` set, pending cleared
 *   'expired'            — pending code is past TTL; user must request a new one
 *   'profile-not-found'  — username 404'd on the provider (typo / never existed)
 *   'code-not-found'     — bio field on the provider doesn't contain our code
 *   'username-claimed'   — UNIQUE constraint hit during commit (TOCTOU race with
 *                          another user verifying the same external username)
 *
 * `ProviderUnavailableException` bubbles up — caller catches and shows
 * "chess.com / Lichess unreachable, try again" without touching pending state.
 *
 * The username persisted on success is the *canonical* form returned by the
 * provider (e.g. user typed "Alice" but chess.com canonical is "alice"). This
 * matters for the snapshot-on-match-creation pattern in M8 Phase 4 — we need
 * an exact match against the username that game records use.
 */
class VerifyLinkedAccountAction
{
    public function __construct(
        private readonly ChessComProfileClient $chessComClient,
        private readonly LichessProfileClient $lichessClient,
    ) {}

    public function handle(User $user): string
    {
        $provider = $this->resolvePendingProvider($user);

        if ($this->isExpired($user)) {
            return 'expired';
        }

        try {
            $result = $this->clientFor($provider)->fetchProfile($user->pending_verification_username);
        } catch (ProfileNotFoundException) {
            return 'profile-not-found';
        }

        if (! $this->bioContainsCode($result->bioFieldValue, $user->pending_verification_code)) {
            return 'code-not-found';
        }

        try {
            $this->markVerified($user, $provider, $result->username);
        } catch (UniqueConstraintViolationException) {
            return 'username-claimed';
        }

        return 'verified';
    }

    private function resolvePendingProvider(User $user): LinkedAccountProvider
    {
        $provider = $user->pending_verification_provider
            ? LinkedAccountProvider::tryFrom($user->pending_verification_provider)
            : null;

        if (! $provider || ! $user->pending_verification_code || ! $user->pending_verification_username) {
            throw ValidationException::withMessages([
                'provider' => 'No pending verification. Start the linking flow first.',
            ]);
        }

        return $provider;
    }

    private function isExpired(User $user): bool
    {
        return $user->pending_verification_expires_at === null
            || $user->pending_verification_expires_at->isPast();
    }

    private function clientFor(LinkedAccountProvider $provider): ProfileClient
    {
        return match ($provider) {
            LinkedAccountProvider::ChessCom => $this->chessComClient,
            LinkedAccountProvider::Lichess => $this->lichessClient,
        };
    }

    private function bioContainsCode(?string $bioFieldValue, string $code): bool
    {
        if ($bioFieldValue === null || $bioFieldValue === '') {
            return false;
        }

        // Case-insensitive containment — users sometimes paste with
        // surrounding whitespace, line breaks, or different casing.
        return stripos($bioFieldValue, $code) !== false;
    }

    private function markVerified(User $user, LinkedAccountProvider $provider, string $canonicalUsername): void
    {
        $usernameColumn = "{$provider->value}_username";
        $verifiedColumn = "{$provider->value}_verified_at";

        $user->forceFill([
            $usernameColumn => strtolower($canonicalUsername),
            $verifiedColumn => now(),
            'pending_verification_provider' => null,
            'pending_verification_username' => null,
            'pending_verification_code' => null,
            'pending_verification_expires_at' => null,
        ])->save();
    }
}
