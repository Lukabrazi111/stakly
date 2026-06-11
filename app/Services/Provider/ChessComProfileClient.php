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
 * Read-only client for chess.com's Published Data API.
 *
 * Endpoint: `GET https://api.chess.com/pub/player/{username}` (no auth).
 * chess.com asks API consumers to send a User-Agent that identifies who
 * we are — set via `config('stakly.chess_com_user_agent')` so the contact
 * email lives in env, not in code.
 *
 * For M8 Phase 1 bio-code verification, the target field is `location`
 * (free-text user-editable field, less disruptive than clobbering the
 * `name` display name). chess.com omits the `location` key from the
 * response entirely when it's empty — we surface that as `null`.
 *
 * Error semantics (M35 P1 — brought up to `ChessComGameClient` parity):
 *   - 404                       → `ProfileNotFoundException` (terminal, separate hierarchy)
 *   - 429                       → `RateLimitedError` with `retryAt` from `RateLimitHeaderParser`
 *   - 5xx / connect / timeout   → `TransientProviderError` (retry-eligible)
 *   - 4xx-other                 → `PermanentProviderError` (terminal)
 *
 * Circuit breaker (M35 P1): every HTTP call records success / failure on
 * the shared `ProviderCircuitBreaker` keyed on `LinkedAccountProvider::ChessCom`.
 * The breaker is consulted by callers (e.g. `DispatchAutoFetchAction`) to
 * gate further outbound calls when the provider is unhealthy.
 *
 * Used by `VerifyLinkedAccountAction`. `Http::fake()`-able from tests.
 */
class ChessComProfileClient implements ProfileClient
{
    public function __construct(
        private readonly ProviderCircuitBreaker $breaker,
    ) {}

    public function fetchProfile(string $username): ProfileFetchResult
    {
        $url = "https://api.chess.com/pub/player/{$username}";

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('stakly.chess_com_user_agent', 'Stakly/1.0'),
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $e) {
            $this->breaker->recordFailure(LinkedAccountProvider::ChessCom);

            throw new TransientProviderError(
                "chess.com unreachable for username '{$username}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            // 404 is a "user doesn't exist" answer from the API, not a
            // provider-health failure. Don't trip the breaker — record
            // success and let the caller distinguish via the dedicated
            // exception type.
            $this->breaker->recordSuccess(LinkedAccountProvider::ChessCom);

            throw new ProfileNotFoundException("chess.com username '{$username}' not found.");
        }

        if (! $response->successful()) {
            $this->breaker->recordFailure(LinkedAccountProvider::ChessCom);

            throw self::classifyResponseError($response, "for username '{$username}'");
        }

        $this->breaker->recordSuccess(LinkedAccountProvider::ChessCom);

        $data = $response->json();

        return new ProfileFetchResult(
            username: $data['username'] ?? $username,
            bioFieldValue: $data['location'] ?? null,
        );
    }

    /**
     * Mirror of `ChessComGameClient::classifyResponseError` so the two clients
     * map provider statuses identically — keeps the retry / breaker / rendering
     * surface uniform.
     */
    private static function classifyResponseError(Response $response, string $context): ProviderError
    {
        $status = $response->status();

        return match (true) {
            $status === 429 => new RateLimitedError(
                "chess.com returned 429 (rate-limited) {$context}.",
                retryAt: RateLimitHeaderParser::parseRetryAt($response),
            ),
            $status >= 500 => new TransientProviderError(
                "chess.com returned status {$status} {$context}.",
            ),
            default => new PermanentProviderError(
                "chess.com returned status {$status} {$context}.",
            ),
        };
    }
}
