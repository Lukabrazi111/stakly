<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\ProviderUnavailableException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for Lichess's game export endpoints. Used by M8 Phase 4
 * smart-link enrichment in two flows:
 *
 *   - `fetchGame()` powers the **paste path** — `FetchLichessGameMetadataJob`
 *     resolves a user-pasted Lichess URL to a game record and posts a
 *     verified card if the snapshotted usernames match.
 *
 *   - `searchGamesBetween()` powers the **auto-fetch path** — on the first
 *     player confirm, `AutoFetchLichessGameJob` queries for games played
 *     between the two snapshotted usernames in the match window. If exactly
 *     one decisive game exists, it posts a system-message card.
 *
 * Lichess rate-limits unauthenticated calls at 20 req/sec/IP — well above
 * Stakly's per-match enrichment cadence. A recognisable User-Agent makes it
 * easy for Lichess to contact us if our traffic ever misbehaves.
 *
 * Error semantics:
 *   - `fetchGame` 404                → `null` (URL pointed at a game that
 *                                     doesn't exist — caller renders nothing)
 *   - `searchGamesBetween` 404       → `[]` (e.g. user deactivated their
 *                                     Lichess account — auto-fetch skips)
 *   - any 4xx-other / 5xx / 429 / connect error / malformed JSON
 *                                    → `ProviderUnavailableException`
 *                                     (transient — calling job swallows so a
 *                                     missing card isn't a failed-jobs entry)
 *
 * `Http::fake()`-able from tests.
 */
class LichessGameClient
{
    private const BASE_URL = 'https://lichess.org';

    private const USER_AGENT = 'Stakly/1.0 (+chat enrichment)';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Fetch a single game by Lichess game ID (8-char short or 12-char full).
     */
    public function fetchGame(string $gameId): ?LichessGameResult
    {
        $url = self::BASE_URL.'/game/export/'.$gameId;

        try {
            $response = Http::withHeaders($this->lichessHeaders('application/json'))
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url, self::minimalPayloadParams());
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                "Lichess unreachable for game '{$gameId}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new ProviderUnavailableException(
                "Lichess returned status {$response->status()} for game '{$gameId}'.",
            );
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['id'])) {
            throw new ProviderUnavailableException(
                "Lichess returned malformed JSON for game '{$gameId}'.",
            );
        }

        return self::parseGame($data);
    }

    /**
     * Search for games between two users since a given timestamp. Symmetric:
     * order of `$userA` / `$userB` doesn't change the result set — Lichess
     * applies the `vs` filter server-side either way. We pick `$userA` as the
     * path username arbitrarily.
     *
     * @return list<LichessGameResult>
     */
    public function searchGamesBetween(
        string $userA,
        string $userB,
        CarbonInterface $since,
        int $max = 10,
    ): array {
        $url = self::BASE_URL.'/api/games/user/'.$userA;

        try {
            // ndjson is Lichess's documented streaming format for the
            // bulk-games endpoint. We parse line-by-line so a partial
            // response still yields valid games.
            $response = Http::withHeaders($this->lichessHeaders('application/x-ndjson'))
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url, [
                    'vs' => $userB,
                    'since' => $since->getTimestampMs(),
                    'max' => $max,
                    ...self::minimalPayloadParams(),
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailableException(
                "Lichess unreachable searching games {$userA} vs {$userB}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw new ProviderUnavailableException(
                "Lichess returned status {$response->status()} searching games {$userA} vs {$userB}.",
            );
        }

        return self::parseNdjson($response);
    }

    /**
     * Query-string flags that strip every payload field we don't render in
     * chat cards. Lichess defaults include the full PGN + clocks + opening
     * book + literate prose, which are large and useless to us.
     *
     * @return array<string, string>
     */
    private static function minimalPayloadParams(): array
    {
        return [
            'moves' => 'false',
            'pgnInJson' => 'false',
            'tags' => 'false',
            'clocks' => 'false',
            'evals' => 'false',
            'opening' => 'false',
            'literate' => 'false',
        ];
    }

    /**
     * Base headers plus an optional `Authorization: Bearer` for the
     * StaklyBot account (M25). When `services.lichess.token` is unset we
     * omit the header entirely so the wire shape matches the pre-M25
     * anonymous path — keeps existing `Http::fake()` assertions valid.
     *
     * @return array<string, string>
     */
    private function lichessHeaders(string $accept): array
    {
        $headers = [
            'Accept' => $accept,
            'User-Agent' => self::USER_AGENT,
        ];

        $token = config('services.lichess.token');

        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }

    /**
     * @return list<LichessGameResult>
     */
    private static function parseNdjson(Response $response): array
    {
        $body = trim($response->body());

        if ($body === '') {
            return [];
        }

        $games = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);

            if (! is_array($data) || ! isset($data['id'])) {
                continue;
            }

            $games[] = self::parseGame($data);
        }

        return $games;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function parseGame(array $data): LichessGameResult
    {
        return new LichessGameResult(
            id: (string) $data['id'],
            whiteUsername: self::extractUsername($data, 'white'),
            blackUsername: self::extractUsername($data, 'black'),
            winnerColor: isset($data['winner']) ? (string) $data['winner'] : null,
            status: (string) ($data['status'] ?? 'unknown'),
            speed: (string) ($data['speed'] ?? 'unknown'),
            variant: (string) ($data['variant'] ?? 'standard'),
            rated: (bool) ($data['rated'] ?? false),
            createdAt: CarbonImmutable::createFromTimestampMs((int) ($data['createdAt'] ?? 0)),
            lastMoveAt: CarbonImmutable::createFromTimestampMs((int) ($data['lastMoveAt'] ?? 0)),
        );
    }

    /**
     * Lichess returns `players.{color}.user.name` for human players. Bots,
     * Stockfish, and anonymous play omit the `user` block — we return ''
     * in those cases so the cross-check against snapshotted usernames
     * naturally fails (no anchor for a verified card).
     *
     * @param  array<string, mixed>  $data
     */
    private static function extractUsername(array $data, string $color): string
    {
        return (string) ($data['players'][$color]['user']['name'] ?? '');
    }
}
