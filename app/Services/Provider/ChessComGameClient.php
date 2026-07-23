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
 * Read-only client for chess.com's Published Data API game endpoints.
 *
 * Differences from `LichessGameClient` (important to know):
 *
 *   - **No direct game-by-id endpoint.** chess.com only exposes per-user
 *     monthly archives (`/pub/player/{username}/games/{YYYY}/{MM}`). To
 *     resolve a single game by URL we fetch a candidate player's archive
 *     and filter by URL. To find recent games between two players we
 *     fetch one player's archive and filter by opponent.
 *
 *   - **Month-boundary**: a game played at 23:55 on the last day of a
 *     month ends in the NEXT month's archive if it spilled past midnight
 *     UTC. Auto-fetch / paste paths query the month containing `$since`
 *     plus the current month when those differ.
 *
 *   - **Eventual consistency**: games show up in the archive a few
 *     seconds after end-time. The calling job retries with backoff if
 *     the search returns empty (typical 5-15s lag).
 *
 *   - **User-Agent is required by chess.com convention**. Set via
 *     `config('stakly.chess_com_user_agent')` so the contact email lives
 *     in env, not in code.
 *
 * Error semantics mirror `LichessGameClient` — see that class's docblock.
 *
 * `Http::fake()`-able from tests.
 */
class ChessComGameClient
{
    private const BASE_URL = 'https://api.chess.com';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Fetch a single game by URL via a known player's archive. Returns
     * `null` if the game isn't in the player's current-or-previous month
     * archive (covers month-boundary case).
     *
     * `$forUsername` should be a snapshotted player from the match —
     * either side works since both players' archives carry the same
     * game record.
     */
    public function fetchGame(string $gameUrl, string $forUsername): ?ChessComGameResult
    {
        $normalisedUrl = strtolower($gameUrl);
        $now = CarbonImmutable::now();
        $monthsToTry = [$now, $now->subMonthNoOverflow()];

        foreach ($monthsToTry as $month) {
            $games = $this->fetchMonthArchive($forUsername, $month->year, $month->month);

            foreach ($games as $game) {
                if (strtolower((string) ($game['url'] ?? '')) === $normalisedUrl) {
                    return self::parseGame($game);
                }
            }
        }

        return null;
    }

    /**
     * Search one player's archive for games against another player since
     * `$since`. Returns an array (possibly empty) of `ChessComGameResult`.
     *
     * Queries the archive month containing `$since` PLUS the current
     * month, dedups by game id. Same symmetric-query property as Lichess
     * — order of `$userA` / `$userB` doesn't affect the result set, we
     * just pick `$userA`'s archive arbitrarily.
     *
     * @return list<ChessComGameResult>
     */
    public function searchGamesBetween(
        string $userA,
        string $userB,
        CarbonInterface $since,
    ): array {
        $sinceImmutable = CarbonImmutable::instance($since);
        $now = CarbonImmutable::now();

        $monthsToQuery = [[$now->year, $now->month]];
        if ($sinceImmutable->year !== $now->year || $sinceImmutable->month !== $now->month) {
            $monthsToQuery[] = [$sinceImmutable->year, $sinceImmutable->month];
        }

        $userBLower = strtolower($userB);
        $matched = [];
        $seenIds = [];

        foreach ($monthsToQuery as [$year, $month]) {
            $games = $this->fetchMonthArchive($userA, $year, $month);

            foreach ($games as $game) {
                if (! $this->gameInvolves($game, $userBLower)) {
                    continue;
                }

                $endTime = (int) ($game['end_time'] ?? 0);
                if ($endTime > 0 && CarbonImmutable::createFromTimestamp($endTime)->lt($sinceImmutable)) {
                    continue;
                }

                $parsed = self::parseGame($game);

                if (in_array($parsed->id, $seenIds, true)) {
                    continue;
                }

                $seenIds[] = $parsed->id;
                $matched[] = $parsed;
            }
        }

        return $matched;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchMonthArchive(string $username, int $year, int $month): array
    {
        $monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $url = sprintf('%s/pub/player/%s/games/%d/%s', self::BASE_URL, $username, $year, $monthPadded);

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('stakly.chess_com_user_agent', 'Stakly/1.0'),
                'Accept' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new TransientProviderError(
                "chess.com unreachable for archive '{$username}/{$year}/{$monthPadded}': {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            // Either the user doesn't exist, or they have no games for
            // this month. Both surface as "no games" — caller's empty-
            // candidates branch handles it.
            return [];
        }

        if (! $response->successful()) {
            throw self::classifyResponseError(
                $response,
                "for archive '{$username}/{$year}/{$monthPadded}'",
            );
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['games']) || ! is_array($data['games'])) {
            // Empty archives sometimes return `{}` instead of `{"games": []}`.
            return [];
        }

        return array_values(array_filter(
            $data['games'],
            fn ($game) => is_array($game),
        ));
    }

    /**
     * Classify a non-2xx response into the right `ProviderError` subclass.
     * 429 → `RateLimitedError` with `retryAt` populated from `Retry-After`
     * or `X-RateLimit-Reset` (M14 Slice 2c). 5xx → `TransientProviderError`.
     * 4xx-other → `PermanentProviderError`.
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

    /**
     * @param  array<string, mixed>  $game
     */
    private function gameInvolves(array $game, string $lowerUsername): bool
    {
        $white = strtolower((string) ($game['white']['username'] ?? ''));
        $black = strtolower((string) ($game['black']['username'] ?? ''));

        return $white === $lowerUsername || $black === $lowerUsername;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function parseGame(array $data): ChessComGameResult
    {
        $whiteResult = strtolower((string) ($data['white']['result'] ?? ''));
        $blackResult = strtolower((string) ($data['black']['result'] ?? ''));

        $winnerColor = match (true) {
            $whiteResult === 'win' => 'white',
            $blackResult === 'win' => 'black',
            default => null,
        };

        // Per-side draw results land on the parsed `status`; for a
        // decisive game the status reflects HOW the loser lost (e.g.
        // `checkmated`, `resigned`, `timeout`).
        $status = $winnerColor === null
            ? ($whiteResult ?: 'unknown')
            : ($winnerColor === 'white' ? $blackResult : $whiteResult);

        return new ChessComGameResult(
            id: self::extractIdFromUrl((string) ($data['url'] ?? '')),
            url: (string) ($data['url'] ?? ''),
            whiteUsername: (string) ($data['white']['username'] ?? ''),
            blackUsername: (string) ($data['black']['username'] ?? ''),
            winnerColor: $winnerColor,
            status: $status,
            speed: (string) ($data['time_class'] ?? 'unknown'),
            variant: (string) ($data['rules'] ?? 'chess'),
            rated: (bool) ($data['rated'] ?? false),
            createdAt: CarbonImmutable::createFromTimestamp(self::parseStartTimestamp($data)),
            endedAt: CarbonImmutable::createFromTimestamp((int) ($data['end_time'] ?? 0)),
        );
    }

    /**
     * True game-start unix timestamp. Daily (correspondence) games expose a
     * top-level `start_time`; LIVE games (bullet/blitz/rapid — the only Stakly
     * time controls) do NOT — the real chess.com API returns `end_time` only.
     * Their start lives in the PGN as `[UTCDate]` + `[StartTime]` (both UTC).
     *
     * Parsing it is what makes the M46 P2 started-after-creation guard REAL on
     * chess.com: without it `createdAt` collapses to `end_time`, and the guard
     * (`createdAt >= match.created_at`) becomes a no-op — redundant with the
     * archive search's own `end_time >= since` filter — so a game already in
     * progress when the stake was placed could be auto-settled. Falls back to
     * `end_time` only when neither source is parseable (malformed data).
     *
     * @param  array<string, mixed>  $data
     */
    private static function parseStartTimestamp(array $data): int
    {
        $startTime = (int) ($data['start_time'] ?? 0);
        if ($startTime > 0) {
            return $startTime;
        }

        $pgn = (string) ($data['pgn'] ?? '');
        $date = self::pgnTag($pgn, 'UTCDate');
        $time = self::pgnTag($pgn, 'StartTime');

        if ($date !== null && $time !== null) {
            try {
                $parsed = CarbonImmutable::createFromFormat('Y.m.d H:i:s', "{$date} {$time}", 'UTC');
                if ($parsed instanceof CarbonInterface) {
                    return $parsed->getTimestamp();
                }
            } catch (\Throwable) {
                // Malformed PGN tags — fall through to the end_time floor.
            }
        }

        return (int) ($data['end_time'] ?? 0);
    }

    /**
     * Value of a single PGN header tag (e.g. `[StartTime "11:59:50"]`), or
     * null when the tag is absent.
     */
    private static function pgnTag(string $pgn, string $tag): ?string
    {
        if (preg_match('/\['.preg_quote($tag, '/').'\s+"([^"]*)"\]/', $pgn, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Trailing path segment of the canonical game URL is the numeric id.
     * Falls back to the full URL if parsing fails so the result still
     * carries a stable identifier for dedup.
     */
    private static function extractIdFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $segments = explode('/', rtrim($url, '/'));

        return end($segments) ?: $url;
    }
}
