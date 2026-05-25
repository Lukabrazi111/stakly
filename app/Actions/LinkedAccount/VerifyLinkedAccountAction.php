<?php

namespace App\Actions\LinkedAccount;

use App\Enums\LinkedAccountProvider;
use App\Models\LinkedAccount;
use App\Models\PendingVerification;
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
 *   'verified'           — happy path; LinkedAccount row inserted, pending row deleted
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
        $pending = $this->resolvePending($user);

        if ($pending->isExpired()) {
            return 'expired';
        }

        try {
            $result = $this->clientFor($pending->provider)->fetchProfile($pending->username);
        } catch (ProfileNotFoundException) {
            return 'profile-not-found';
        }

        if (! $this->bioContainsCode($result->bioFieldValue, $pending->code)) {
            return 'code-not-found';
        }

        try {
            $this->markVerified($user, $pending, $result->username);
        } catch (UniqueConstraintViolationException) {
            return 'username-claimed';
        }

        return 'verified';
    }

    private function resolvePending(User $user): PendingVerification
    {
        $pending = $user->pendingVerification;

        if (! $pending) {
            throw ValidationException::withMessages([
                'provider' => 'No pending verification. Start the linking flow first.',
            ]);
        }

        return $pending;
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

    /**
     * Insert the verified link, delete the pending row. The two writes
     * aren't strictly transactional — if the delete fails after the
     * insert, the user has a verified link and a stale pending row. That
     * pending row will be cleaned up next time `RequestLinkVerificationAction`
     * runs (it upserts on user_id). Acceptable for now.
     */
    private function markVerified(User $user, PendingVerification $pending, string $canonicalUsername): void
    {
        LinkedAccount::create([
            'user_id' => $user->id,
            'provider' => $pending->provider->value,
            'username' => strtolower($canonicalUsername),
            'verified_at' => now(),
        ]);

        $pending->delete();
    }
}
