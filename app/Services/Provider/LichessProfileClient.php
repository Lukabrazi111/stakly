<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for Lichess's public API.
 *
 * Endpoint: `GET https://lichess.org/api/user/{username}` (public read,
 * usable anonymously or authenticated). When `LICHESS_API_TOKEN` is set
 * (M25), we send `Authorization: Bearer {token}` so the request lands in
 * the authenticated rate-limit bucket and Lichess associates it with the
 * StaklyBot account rather than a bare IP.
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
            $response = Http::withHeaders($this->lichessHeaders())
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

    /**
     * Base headers plus an optional bearer token (M25). Returning the
     * Authorization key only when the token is set keeps the anonymous
     * fallback path identical to the pre-M25 wire shape — important so
     * `Http::fake()` assertions in pre-M25 tests don't have to anticipate
     * a header that may or may not be there.
     *
     * @return array<string, string>
     */
    private function lichessHeaders(): array
    {
        $headers = ['Accept' => 'application/json'];

        $token = config('services.lichess.token');

        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }
}
