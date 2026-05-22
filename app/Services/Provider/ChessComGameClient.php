<?php

namespace App\Services\Provider;

use App\Services\Provider\Exceptions\ProviderUnavailableException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
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
            try {
                $games = $this->fetchMonthArchive($forUsername, $month->year, $month->month);
            } catch (ProviderUnavailableException $e) {
                throw $e;
            }

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
            throw new ProviderUnavailableException(
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
            throw new ProviderUnavailableException(
                "chess.com returned status {$response->status()} for archive '{$username}/{$year}/{$monthPadded}'.",
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
            createdAt: CarbonImmutable::createFromTimestamp((int) ($data['start_time'] ?? $data['end_time'] ?? 0)),
            endedAt: CarbonImmutable::createFromTimestamp((int) ($data['end_time'] ?? 0)),
        );
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
