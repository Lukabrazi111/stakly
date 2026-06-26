<?php

namespace App\Services\Provider;

use App\Enums\LinkedAccountProvider;
use App\Enums\TimeControl;
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
        $data = $this->getJson("https://api.chess.com/pub/player/{$username}", $username);

        return new ProfileFetchResult(
            username: $data['username'] ?? $username,
            bioFieldValue: $data['location'] ?? null,
        );
    }

    /**
     * Per-time-control ratings from the SEPARATE `/stats` endpoint (M41 P3b) —
     * chess.com puts ratings on `GET /pub/player/{username}/stats`, NOT the
     * profile endpoint the bio verify uses, so this is a second call.
     * `chess_{bullet,blitz,rapid}.last.{rating,rd}` map to our time controls
     * (chess.com has no online classical — and we don't offer it). A category
     * key is absent when the player has never played it → no rating returned.
     * chess.com exposes no provisional flag, so we derive it from a high Glicko
     * deviation (`rd` over `services.chess_com.provisional_rd_threshold`).
     *
     * @throws ProfileNotFoundException
     */
    public function fetchRatings(string $username): ChessRatings
    {
        $data = $this->getJson(
            "https://api.chess.com/pub/player/{$username}/stats",
            $username,
            recordHealth: false,
        );
        $threshold = (int) config('services.chess_com.provisional_rd_threshold', 110);

        $ratings = [];

        foreach (TimeControl::cases() as $timeControl) {
            $last = $data["chess_{$timeControl->value}"]['last'] ?? null;

            if (! is_array($last) || ! isset($last['rating'])) {
                continue;
            }

            $rd = isset($last['rd']) ? (int) $last['rd'] : null;

            $ratings[] = new ChessTimeControlRating(
                timeControl: $timeControl,
                rating: (int) $last['rating'],
                rd: $rd,
                isProvisional: $rd !== null && $rd > $threshold,
            );
        }

        return new ChessRatings($ratings);
    }

    /**
     * Shared GET + breaker/error mapping for chess.com's public read endpoints
     * (profile + stats live at different URLs but classify identically).
     * Returns the decoded JSON body (always an array — a non-object 200 body
     * degrades to `[]` so callers reading `$data['key'] ?? null` keep working).
     *
     * `$recordHealth` gates whether this call feeds the per-provider circuit
     * breaker. The bio-verify path records (true); the high-volume rating path
     * passes false so a rating-API blip can't trip — or dilute — the breaker
     * that gates real chess SETTLEMENT (`DispatchAutoFetchAction`). The rating
     * job still READS `isOpen()` to skip when the provider is already unhealthy.
     *
     * @return array<string, mixed>
     *
     * @throws ProfileNotFoundException
     */
    private function getJson(string $url, string $username, bool $recordHealth = true): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => config('stakly.chess_com_user_agent', 'Stakly/1.0'),
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $e) {
            if ($recordHealth) {
                $this->breaker->recordFailure(LinkedAccountProvider::ChessCom);
            }

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
            if ($recordHealth) {
                $this->breaker->recordSuccess(LinkedAccountProvider::ChessCom);
            }

            throw new ProfileNotFoundException("chess.com username '{$username}' not found.");
        }

        if (! $response->successful()) {
            if ($recordHealth) {
                $this->breaker->recordFailure(LinkedAccountProvider::ChessCom);
            }

            throw self::classifyResponseError($response, "for username '{$username}'");
        }

        if ($recordHealth) {
            $this->breaker->recordSuccess(LinkedAccountProvider::ChessCom);
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
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
