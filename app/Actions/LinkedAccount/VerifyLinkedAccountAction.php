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
 * Step 2 of the bio-code linking flow: read pending state, fetch the external profile,
 * match the code, mark verified. Username persisted is the *canonical* form returned by
 * the provider — required for snapshot-on-match-creation to match game records exactly.
 *
 * Returns one of: `'verified'`, `'expired'`, `'profile-not-found'`, `'code-not-found'`,
 * `'username-claimed'` (TOCTOU race during commit). `ProviderUnavailableException` bubbles up.
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

        // Case-insensitive — users paste with whitespace, line breaks, or different casing.
        return stripos($bioFieldValue, $code) !== false;
    }

    /**
     * Not strictly transactional — if the delete fails after insert, the stale pending
     * row gets cleaned up next time `RequestLinkVerificationAction` runs (upsert on user_id).
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
