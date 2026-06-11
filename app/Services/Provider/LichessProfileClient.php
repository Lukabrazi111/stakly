<?php

namespace App\Services\Provider;

use App\Enums\LinkedAccountProvider;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\ProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
 * Error semantics (M35 P2 — brought up to `LichessGameClient` parity):
 *   - 404                       → `ProfileNotFoundException` (terminal, separate hierarchy)
 *   - 429                       → `RateLimitedError` with `retryAt` from `RateLimitHeaderParser`
 *   - 5xx / connect / timeout   → `TransientProviderError` (retry-eligible)
 *   - 4xx-other                 → `PermanentProviderError` (terminal)
 *
 * Circuit breaker (M35 P2): every HTTP call records success / failure on
 * the shared `ProviderCircuitBreaker` keyed on `LinkedAccountProvider::Lichess`.
 *
 * Used by `VerifyLinkedAccountAction`. `Http::fake()`-able from tests.
 */
class LichessProfileClient implements ProfileClient
{
    public function __construct(
        private readonly ProviderCircuitBreaker $breaker,
    ) {}

    public function fetchProfile(string $username): ProfileFetchResult
    {
        $url = "https://lichess.org/api/user/{$username}";

        try {
            $response = Http::withHeaders($this->lichessHeaders())
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $e) {
            $this->breaker->recordFailure(LinkedAccountProvider::Lichess);

            throw new TransientProviderError(
                "Lichess unreachable for username '{$username}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            // 404 is a "user doesn't exist" answer, not a provider-health
            // failure. Count as breaker success — bad-username burst
            // shouldn't trip the breaker.
            $this->breaker->recordSuccess(LinkedAccountProvider::Lichess);

            throw new ProfileNotFoundException("Lichess username '{$username}' not found.");
        }

        if (! $response->successful()) {
            $this->breaker->recordFailure(LinkedAccountProvider::Lichess);

            throw self::classifyResponseError($response, "for username '{$username}'");
        }

        $this->breaker->recordSuccess(LinkedAccountProvider::Lichess);

        $data = $response->json();

        return new ProfileFetchResult(
            username: $data['username'] ?? $username,
            bioFieldValue: $data['profile']['bio'] ?? null,
        );
    }

    /**
     * Mirror of `LichessGameClient::classifyResponseError` so the two clients
     * map provider statuses identically — keeps the retry / breaker / rendering
     * surface uniform.
     */
    private static function classifyResponseError(Response $response, string $context): ProviderError
    {
        $status = $response->status();

        return match (true) {
            $status === 429 => new RateLimitedError(
                "Lichess returned 429 (rate-limited) {$context}.",
                retryAt: RateLimitHeaderParser::parseRetryAt($response),
            ),
            $status >= 500 => new TransientProviderError(
                "Lichess returned status {$status} {$context}.",
            ),
            default => new PermanentProviderError(
                "Lichess returned status {$status} {$context}.",
            ),
        };
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
