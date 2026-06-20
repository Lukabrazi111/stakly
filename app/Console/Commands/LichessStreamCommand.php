<?php

namespace App\Console\Commands;

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Long-lived consumer of Lichess's `POST /api/stream/games-by-users` NDJSON stream.
 * Dispatches `AutoFetchLichessGameJob` on each game-end event for the subscribed users.
 *
 * Stream is an OPTIMIZATION, not a hard dependency — if the daemon dies, the 5-min
 * cron backstop catches matches within minutes. Run as a `compose.yaml` sidecar
 * with `restart: unless-stopped` so crashes auto-restart.
 */
class LichessStreamCommand extends Command
{
    protected $signature = 'stakly:lichess-stream';

    protected $description = 'Long-lived Lichess game-end stream consumer.';

    /**
     * Lichess hard cap on usernames per stream — subscribing more would 400 the request.
     */
    private const MAX_USERS_PER_STREAM = 300;

    /**
     * Early-warning band below the hard cap. Sustained crossings over a 24h window
     * mean it's time to ship a multi-stream split before settlement degrades to cron-only.
     */
    private const MULTI_STREAM_TRIGGER_USERS = 200;

    private const USER_LIST_REFRESH_SECONDS = 30;

    /**
     * Lichess typically sends keepalive newlines every few seconds; 60s catches genuine
     * hangs (network partition, Lichess restarting) without aborting healthy idle periods.
     */
    private const LOW_SPEED_TIMEOUT_SECONDS = 60;

    /**
     * Initial TCP/TLS handshake only — the long-lived read uses CURLOPT_TIMEOUT = 0.
     */
    private const CONNECT_TIMEOUT_SECONDS = 30;

    private const IDLE_SLEEP_SECONDS = 30;

    /**
     * Jitter prevents thundering-herd reconnects if Lichess restarts.
     */
    private const INITIAL_BACKOFF_SECONDS = 2.0;

    private const MAX_BACKOFF_SECONDS = 60.0;

    private string $lineBuffer = '';

    /** @var list<string> lowercased usernames currently subscribed */
    private array $subscribedUsernames = [];

    private float $lastUserListCheck = 0.0;

    public function handle(): int
    {
        $backoff = self::INITIAL_BACKOFF_SECONDS;

        while (true) {
            $usernames = $this->loadPendingLichessUsernames();

            if (count($usernames) === 0) {
                $this->info('No Pending Lichess matches — sleeping.');
                sleep(self::IDLE_SLEEP_SECONDS);

                continue;
            }

            if (count($usernames) > self::MAX_USERS_PER_STREAM) {
                $this->warn(sprintf(
                    'Pending Lichess usernames (%d) exceed Lichess cap (%d) — truncating; some matches will only settle via cron.',
                    count($usernames),
                    self::MAX_USERS_PER_STREAM,
                ));
                $usernames = array_slice($usernames, 0, self::MAX_USERS_PER_STREAM);
            } elseif (count($usernames) >= self::MULTI_STREAM_TRIGGER_USERS) {
                $this->warn(sprintf(
                    'Pending Lichess usernames (%d) approaching cap (%d) — plan multi-stream split.',
                    count($usernames),
                    self::MAX_USERS_PER_STREAM,
                ));
            }

            $this->subscribedUsernames = $usernames;
            $this->lastUserListCheck = microtime(true);

            $this->info(sprintf('Connecting with %d Lichess usernames.', count($usernames)));

            try {
                $this->openStream($usernames);
                // Clean abort (user list changed) → reconnect with new list, reset backoff.
                $backoff = self::INITIAL_BACKOFF_SECONDS;
            } catch (Throwable $e) {
                $this->warn('Stream error: '.$e->getMessage());
                $sleep = min(self::MAX_BACKOFF_SECONDS, $backoff)
                    + random_int(0, 1000) / 1000.0;
                $this->info(sprintf('Reconnecting in %.2fs.', $sleep));
                usleep((int) ($sleep * 1_000_000));
                $backoff = min(self::MAX_BACKOFF_SECONDS, $backoff * 2);
            }
        }
    }

    /**
     * Blocks inside curl_exec until write callback returns -1 (clean abort), low-speed
     * timeout fires, the connection drops, or Lichess closes the stream.
     *
     * @param  list<string>  $usernames
     */
    private function openStream(array $usernames): void
    {
        $this->lineBuffer = '';

        $ch = curl_init('https://lichess.org/api/stream/games-by-users?withCurrentGames=true');
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => implode(',', $usernames),
            CURLOPT_HTTPHEADER => $this->buildStreamHeaders(),
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIMEOUT_SECONDS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => fn ($curl, $chunk) => $this->handleChunk($chunk),
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        // CURLE_ABORTED_BY_CALLBACK is our clean reconnect signal (user list changed) — not an error.
        if ($errno !== 0 && $errno !== CURLE_ABORTED_BY_CALLBACK) {
            throw new RuntimeException("curl errno {$errno}: {$error}");
        }

        if ($httpCode !== 0 && $httpCode !== 200) {
            throw new RuntimeException("Unexpected HTTP status {$httpCode} from Lichess stream");
        }

        if ($ok === false && $errno === 0) {
            // curl_exec returned false without an errno — treat as connection drop.
            throw new RuntimeException('curl_exec returned false without errno');
        }
    }

    /**
     * Return strlen($chunk) to continue, or -1 to signal a clean abort (user-list refresh).
     */
    private function handleChunk(string $chunk): int
    {
        $this->lineBuffer .= $chunk;

        while (($newline = strpos($this->lineBuffer, "\n")) !== false) {
            $line = substr($this->lineBuffer, 0, $newline);
            $this->lineBuffer = substr($this->lineBuffer, $newline + 1);

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $event = json_decode($trimmed, associative: true);
            if (is_array($event)) {
                $this->dispatchFromEvent($event);
            }
        }

        $elapsed = microtime(true) - $this->lastUserListCheck;
        if ($elapsed >= self::USER_LIST_REFRESH_SECONDS) {
            $this->lastUserListCheck = microtime(true);

            $current = $this->loadPendingLichessUsernames();
            // Truncate the same way the outer loop does so the comparison doesn't always-differ over the cap.
            $current = array_slice($current, 0, self::MAX_USERS_PER_STREAM);

            if ($current !== $this->subscribedUsernames) {
                $this->info('User list changed — aborting stream for reconnect.');

                return -1;
            }
        }

        return strlen($chunk);
    }

    /**
     * Dispatches `AutoFetchLichessGameJob` for the matching Pending GameMatch, or
     * returns null on skip (game just started, missing player IDs, no matching match).
     * Public for testability without spinning up the curl streaming loop.
     *
     * @param  array<string, mixed>  $event
     */
    public function dispatchFromEvent(array $event): ?GameMatch
    {
        $statusName = $event['statusName'] ?? null;
        if ($statusName === 'started' || $statusName === 'created') {
            return null;
        }

        $whiteId = $event['players']['white']['userId'] ?? null;
        $blackId = $event['players']['black']['userId'] ?? null;

        if (! is_string($whiteId) || $whiteId === '' || ! is_string($blackId) || $blackId === '') {
            return null;
        }

        $match = $this->findMatchForPair($whiteId, $blackId);
        if ($match === null) {
            return null;
        }

        AutoFetchLichessGameJob::dispatch($match);

        return $match;
    }

    /**
     * Filter on `listings.platform` ensures we only resolve to Lichess matches — a chess.com
     * match between two players who also happen to be linked on Lichess must NOT settle
     * from a Lichess game record.
     */
    private function findMatchForPair(string $userA, string $userB): ?GameMatch
    {
        $a = strtolower($userA);
        $b = strtolower($userB);

        return GameMatch::query()
            ->where('status', MatchStatus::Pending)
            ->whereHas('listing', fn ($q) => $q->where('platform', LinkedAccountProvider::Lichess->value))
            ->whereHas('providerSnapshots', fn ($q) => $q
                ->where('provider', LinkedAccountProvider::Lichess)
                ->whereRaw('LOWER(username) = ?', [$a]))
            ->whereHas('providerSnapshots', fn ($q) => $q
                ->where('provider', LinkedAccountProvider::Lichess)
                ->whereRaw('LOWER(username) = ?', [$b]))
            ->first();
    }

    /**
     * Always `Content-Type: text/plain` (Lichess expects the user list as plain body).
     * Adds `Authorization: Bearer` when configured for headroom in Lichess's authenticated stream bucket.
     * Public for testability.
     *
     * @return list<string>
     */
    public function buildStreamHeaders(): array
    {
        $headers = ['Content-Type: text/plain'];

        $token = config('services.lichess.token');

        if (is_string($token) && $token !== '') {
            $headers[] = "Authorization: Bearer {$token}";
        }

        return $headers;
    }

    /**
     * Sorted for stable equality checks against the subscribed set.
     *
     * @return list<string>
     */
    private function loadPendingLichessUsernames(): array
    {
        $usernames = DB::table('match_provider_snapshots')
            ->join('game_matches', 'match_provider_snapshots.match_id', '=', 'game_matches.id')
            ->join('listings', 'game_matches.listing_id', '=', 'listings.id')
            ->where('match_provider_snapshots.provider', LinkedAccountProvider::Lichess->value)
            ->where('game_matches.status', MatchStatus::Pending->value)
            ->where('listings.platform', LinkedAccountProvider::Lichess->value)
            ->pluck('match_provider_snapshots.username')
            ->map(fn ($u) => strtolower($u))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $usernames;
    }
}
