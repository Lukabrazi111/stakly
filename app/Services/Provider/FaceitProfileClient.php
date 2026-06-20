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
 * Read-only client for FACEIT's Data API (M15 Phase 2).
 *
 * Endpoint: `GET https://open.faceit.com/data/v4/players/{player_id}`.
 * Auth: server-side API key from `config('services.faceit.api_key')` via
 * `Authorization: Bearer {key}` header. FACEIT's OAuth user tokens are
 * scoped to identity (openid) and do NOT grant Data API access — those
 * requests come back 403. The Phase 0 research initially claimed OAuth
 * tokens worked here; production behaviour proved otherwise.
 *
 * For Phase 2 we only need ELO + skill level for CS2 — the response also
 * carries other game blocks (`games.dota2`, `games.csgo`, etc.) but
 * they're not surfaced into the `FaceitProfile` value object until the
 * adapter for that game lands.
 *
 * Error semantics (M35 P3 — brought up to `FaceitGameClient` parity):
 *   - 404                       → `ProfileNotFoundException` (terminal, separate hierarchy)
 *   - 429                       → `RateLimitedError` with `retryAt` from `RateLimitHeaderParser`
 *   - 5xx / connect / timeout   → `TransientProviderError` (retry-eligible)
 *   - 4xx-other                 → `PermanentProviderError` (terminal)
 *
 * Circuit breaker (M35 P3): every HTTP call records success / failure on
 * the shared `ProviderCircuitBreaker` keyed on `LinkedAccountProvider::Faceit`.
 *
 * Returns `null` if no API key is configured — useful in dev when the
 * key hasn't been set yet; the link still creates the LinkedAccount
 * row, just with `skill_rating = null`. The breaker is NOT touched in
 * this path (we never made an API call to assess provider health).
 *
 * Used by `LinkFaceitAccountAction`. `Http::fake()`-able from tests.
 */
class FaceitProfileClient
{
    public function __construct(
        private readonly ProviderCircuitBreaker $breaker,
    ) {}

    public function fetchPlayer(string $playerId): ?FaceitProfile
    {
        $apiKey = config('services.faceit.api_key');

        if (empty($apiKey)) {
            return null;
        }

        $url = "https://open.faceit.com/data/v4/players/{$playerId}";

        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $e) {
            $this->breaker->recordFailure(LinkedAccountProvider::Faceit);

            throw new TransientProviderError(
                "FACEIT unreachable for player '{$playerId}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            // 404 is a "player doesn't exist" answer, not a provider-health
            // failure. Count as breaker success — bad-id burst shouldn't
            // trip the breaker.
            $this->breaker->recordSuccess(LinkedAccountProvider::Faceit);

            throw new ProfileNotFoundException("FACEIT player '{$playerId}' not found.");
        }

        if (! $response->successful()) {
            $this->breaker->recordFailure(LinkedAccountProvider::Faceit);

            throw self::classifyResponseError($response, "for player '{$playerId}'");
        }

        $this->breaker->recordSuccess(LinkedAccountProvider::Faceit);

        $data = $response->json();

        return new FaceitProfile(
            playerId: $data['player_id'] ?? $playerId,
            nickname: $data['nickname'] ?? '',
            cs2Elo: $data['games']['cs2']['faceit_elo'] ?? null,
            cs2SkillLevel: $data['games']['cs2']['skill_level'] ?? null,
        );
    }

    /**
     * Mirror of `FaceitGameClient::classifyResponseError` so the two clients
     * map provider statuses identically — keeps the retry / breaker / rendering
     * surface uniform.
     */
    private static function classifyResponseError(Response $response, string $context): ProviderError
    {
        $status = $response->status();

        return match (true) {
            $status === 429 => new RateLimitedError(
                "FACEIT returned 429 (rate-limited) {$context}.",
                retryAt: RateLimitHeaderParser::parseRetryAt($response),
            ),
            $status >= 500 => new TransientProviderError(
                "FACEIT returned status {$status} {$context}.",
            ),
            default => new PermanentProviderError(
                "FACEIT returned status {$status} {$context}.",
            ),
        };
    }
}
