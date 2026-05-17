<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for Lichess's public API.
 *
 * Endpoint: `GET https://lichess.org/api/user/{username}` (no auth for
 * public profile data). Lichess rate-limits at 20 req/sec/IP for
 * unauthenticated requests — well above our per-user verification flow.
 *
 * For M8 Phase 1 bio-code verification, the target field is `profile.bio`
 * (400-char user-editable bio). Lichess omits the entire `profile` object
 * when the user hasn't filled in anything, so we defensively chain the
 * lookup and surface missing/empty as `null`.
 *
 * Used by `VerifyLinkedAccountAction`. `Http::fake()`-able from tests.
 */
class LichessProfileClient implements ProfileClient
{
    public function fetchProfile(string $username): ProfileFetchResult
    {
        $url = "https://lichess.org/api/user/{$username}";

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                "Lichess unreachable for username '{$username}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            throw new ProfileNotFoundException("Lichess username '{$username}' not found.");
        }

        if (! $response->successful()) {
            throw new ProviderUnavailableException(
                "Lichess returned status {$response->status()} for username '{$username}'.",
            );
        }

        $data = $response->json();

        return new ProfileFetchResult(
            username: $data['username'] ?? $username,
            bioFieldValue: $data['profile']['bio'] ?? null,
        );
    }
}
