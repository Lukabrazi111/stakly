<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for FACEIT's Data API (M15 Phase 4 Slice 1).
 *
 * Auth: server-side API key from `config('services.faceit.api_key')` via
 * `Authorization: Bearer {key}`. OAuth user tokens issued by
 * `App\Services\Provider\FaceitProvider` (Phase 2) cannot read the Data API —
 * Phase 0 research confirmed the Data API returns 403 against OAuth tokens
 * regardless of scope. The server-side key is the only path.
 *
 * Sibling of `LichessGameClient` / `ChessComGameClient` — same 10s timeout,
 * same `ProviderError` classification, same `Http::fake()`-able shape.
 *
 * `fetchMatch()` is the polling-path lookup used by `AutoFetchFaceitGameJob`
 * (Slice 2) once a candidate match_id is identified. The per-player
 * `anticheat_required` flag on each roster entry is what gates settlement —
 * Stakly only settles matches where every player on both rosters has FACEIT
 * AC required (Phase 0 verdict; `FaceitMatchResult::isAntiCheatComplete()`).
 *
 * Returns `null` when the API key isn't configured — graceful dev path
 * mirroring `FaceitProfileClient`. Auto-fetch attempts in that case skip
 * cleanly instead of failing every job.
 */
class FaceitGameClient
{
    private const BASE_URL = 'https://open.faceit.com';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Fetch a single match by FACEIT `match_id`. Returns `null` on 404 or
     * when no API key is configured.
     */
    public function fetchMatch(string $matchId): ?FaceitMatchResult
    {
        $apiKey = config('services.faceit.api_key');

        if (empty($apiKey)) {
            return null;
        }

        $url = self::BASE_URL.'/data/v4/matches/'.$matchId;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new TransientProviderError(
                "FACEIT unreachable for match '{$matchId}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw self::classifyResponseError($response, "for match '{$matchId}'");
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['match_id'])) {
            throw new PermanentProviderError(
                "FACEIT returned malformed JSON for match '{$matchId}'.",
            );
        }

        return self::parseMatch($data);
    }

    /**
     * List recent match IDs played by `$playerId` since `$since`. Returns
     * slim match references (just IDs); use `fetchMatch()` per ID for full
     * details (rosters + anti-cheat flags). Capped at `$limit` (default 10,
     * FACEIT's max is 100) — typical match-confirmation polling needs ~5.
     *
     * Server-side filter via `from` Unix timestamp. Job's roster cross-check
     * catches any matches outside the window if FACEIT's filter is unreliable.
     *
     * Returns `[]` on 404 or when the API key isn't configured.
     *
     * @return list<string>
     */
    public function searchPlayerMatches(
        string $playerId,
        CarbonInterface $since,
        string $game = 'cs2',
        int $limit = 10,
    ): array {
        $apiKey = config('services.faceit.api_key');

        if (empty($apiKey)) {
            return [];
        }

        $url = self::BASE_URL.'/data/v4/players/'.$playerId.'/history';

        try {
            $response = Http::withToken($apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url, [
                    'game' => $game,
                    'from' => $since->getTimestamp(),
                    'limit' => $limit,
                ]);
        } catch (ConnectionException $e) {
            throw new TransientProviderError(
                "FACEIT unreachable searching matches for player '{$playerId}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw self::classifyResponseError($response, "searching matches for player '{$playerId}'");
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['items']) || ! is_array($data['items'])) {
            throw new PermanentProviderError(
                "FACEIT returned malformed JSON searching matches for player '{$playerId}'.",
            );
        }

        $matchIds = [];

        foreach ($data['items'] as $item) {
            if (is_array($item) && isset($item['match_id']) && is_string($item['match_id'])) {
                $matchIds[] = $item['match_id'];
            }
        }

        return $matchIds;
    }

    /**
     * 429 → `RateLimitedError` with `retryAt` populated from `Retry-After`
     * or `X-RateLimit-Reset`. 5xx → `TransientProviderError`. 4xx-other →
     * `PermanentProviderError`. Mirrors the chess clients exactly.
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

    /**
     * @param  array<string, mixed>  $data
     */
    private static function parseMatch(array $data): FaceitMatchResult
    {
        $teams = is_array($data['teams'] ?? null) ? $data['teams'] : [];

        return new FaceitMatchResult(
            id: (string) $data['match_id'],
            game: (string) ($data['game'] ?? 'unknown'),
            region: isset($data['region']) ? (string) $data['region'] : null,
            competitionType: (string) ($data['competition_type'] ?? 'unknown'),
            status: (string) ($data['status'] ?? 'unknown'),
            winnerFaction: isset($data['results']['winner'])
                ? (string) $data['results']['winner']
                : null,
            faction1Roster: self::parseRoster($teams['faction1']['roster'] ?? []),
            faction2Roster: self::parseRoster($teams['faction2']['roster'] ?? []),
            startedAt: isset($data['started_at'])
                ? CarbonImmutable::createFromTimestamp((int) $data['started_at'])
                : null,
            finishedAt: isset($data['finished_at'])
                ? CarbonImmutable::createFromTimestamp((int) $data['finished_at'])
                : null,
        );
    }

    /**
     * @param  array<int, mixed>  $roster
     * @return list<FaceitRosterPlayer>
     */
    private static function parseRoster(array $roster): array
    {
        $players = [];

        foreach ($roster as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $players[] = new FaceitRosterPlayer(
                playerId: (string) ($entry['player_id'] ?? ''),
                nickname: (string) ($entry['nickname'] ?? ''),
                anticheatRequired: (bool) ($entry['anticheat_required'] ?? false),
            );
        }

        return $players;
    }
}
