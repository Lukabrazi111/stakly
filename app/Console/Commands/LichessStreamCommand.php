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
 * M16 Phase 4 — long-lived consumer of Lichess's
 * `POST /api/stream/games-by-users` NDJSON stream. The endpoint emits a
 * game-object line whenever a game STARTS or ENDS between any two users
 * in the subscribed set, in real time. When a game ends we dispatch
 * `AutoFetchLichessGameJob` which does the canonical fetch + posts the
 * card + invokes `SettleFromCardAction`.
 *
 * Architecture:
 *
 *   1. On boot (and every 30s thereafter): pluck the distinct Lichess
 *      usernames from all currently-Pending matches whose listing.platform
 *      is Lichess. Empty list → sleep 30s + recheck.
 *   2. POST the comma-separated user list to Lichess (no auth — the
 *      endpoint is public per the Lichess OpenAPI spec). Up to 300 users
 *      per stream (Lichess hard cap).
 *   3. Read the NDJSON response line-by-line via curl's WRITEFUNCTION
 *      callback. Each line is a complete game object (`{id, status,
 *      statusName, players: {white: {userId}, black: {userId}}, winner}`).
 *      Skip `started` / `created` events; on end-of-game events look up
 *      the matching Pending GameMatch and dispatch.
 *   4. While reading, check elapsed time vs the 30s refresh interval.
 *      If the local user list has drifted from the subscribed set,
 *      return `-1` from the callback to abort curl cleanly + reconnect.
 *   5. On connection drop / network error: exponential backoff with
 *      jitter, capped at 60s. Reset backoff on successful connect.
 *
 * Defensive properties:
 *   - Idempotency: the dispatched `AutoFetchLichessGameJob` is
 *     `ShouldBeUnique` keyed on `match.id`, so duplicate dispatches
 *     (cron + stream firing on the same game-end) collapse to one
 *     in-flight job.
 *   - Stream is an OPTIMIZATION, not a hard dependency. If this daemon
 *     dies, the Phase 2 cron at 5-min cadence still catches the match
 *     within minutes; no frozen-match scenario.
 *
 * Run as a `compose.yaml` sidecar with `restart: unless-stopped` so a
 * crash auto-restarts. SIGTERM from `docker compose stop` cleanly
 * terminates the PHP process; the curl handle releases on process exit.
 */
class LichessStreamCommand extends Command
{
    protected $signature = 'stakly:lichess-stream';

    protected $description = 'Long-lived Lichess game-end stream consumer (M16 Phase 4).';

    /**
     * Lichess hard cap on usernames per stream (per the OpenAPI spec).
     * Subscribing more would 400 the request.
     */
    private const MAX_USERS_PER_STREAM = 300;

    /**
     * Early-warning threshold for adding multi-stream support. When the
     * subscribed user count crosses this on a connect attempt, the daemon
     * logs a `warn` so the operator sees it in container logs. Sized to
     * give a ~100-username (~50-match) buffer before the hard cap — long
     * enough to research + ship the multi-stream change before settlement
     * starts degrading to cron-only for overflow users.
     *
     * Trigger to act on the warning: sustained crossings over any 24-hour
     * window (one-off spikes are fine — the cron backstop handles them).
     */
    private const MULTI_STREAM_TRIGGER_USERS = 200;

    /**
     * Recheck the local Pending-Lichess user list every N seconds. The
     * check fires inside the curl write callback — if no chunks arrive
     * for longer than this, the low-speed timeout kicks first.
     */
    private const USER_LIST_REFRESH_SECONDS = 30;

    /**
     * curl low-speed safety net. If less than 1 byte/sec averaged over
     * this many seconds, abort and reconnect. Lichess typically sends
     * keepalive newlines every few seconds; 60s catches genuine hangs
     * (network partition, Lichess restarting) without aborting healthy
     * idle periods.
     */
    private const LOW_SPEED_TIMEOUT_SECONDS = 60;

    /**
     * Connection setup timeout — should be more than enough for a healthy
     * connection. The long-lived read uses CURLOPT_TIMEOUT = 0 (no overall
     * cap) so this only governs the initial TCP/TLS handshake.
     */
    private const CONNECT_TIMEOUT_SECONDS = 30;

    /**
     * Sleep duration when no Pending Lichess matches exist. Short enough
     * that a fresh match picks up the daemon within ~half a minute.
     */
    private const IDLE_SLEEP_SECONDS = 30;

    /**
     * Reconnect backoff bounds. Doubles on each consecutive failure;
     * jitter prevents thundering-herd reconnects if Lichess restarts.
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
                // Early-warning band — we're inside the buffer below the
                // hard cap. If this fires sustained over a 24h window, time
                // to research + ship a multi-stream split (open N parallel
                // streams partitioned by username) before users start
                // hitting the cron-only fallback path.
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
                // Successful connect: clean abort (user list changed) →
                // immediately reconnect with new list, reset backoff.
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
     * Open the streaming connection. Blocks inside curl_exec until the
     * write callback returns -1 (clean abort), the low-speed timeout
     * fires, the connection drops, or Lichess closes the stream.
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

        // CURLE_ABORTED_BY_CALLBACK (errno 42) is our clean reconnect
        // signal (user list changed) — not an error.
        if ($errno !== 0 && $errno !== CURLE_ABORTED_BY_CALLBACK) {
            throw new RuntimeException("curl errno {$errno}: {$error}");
        }

        if ($httpCode !== 0 && $httpCode !== 200) {
            throw new RuntimeException("Unexpected HTTP status {$httpCode} from Lichess stream");
        }

        if ($ok === false && $errno === 0) {
            // Unusual: curl_exec returned false without an errno. Treat
            // as connection drop and let the caller backoff + retry.
            throw new RuntimeException('curl_exec returned false without errno');
        }
    }

    /**
     * Per-chunk callback. Append to the line buffer, dispatch complete
     * lines, and check whether we should abort for a user-list refresh.
     *
     * Return value: number of bytes consumed (always strlen($chunk))
     * on continue, or `-1` to signal curl to abort the transfer. The
     * caller treats -1 as a clean reconnect signal (not an error).
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
            // Truncate the same way the outer loop does so the comparison
            // doesn't always-differ when we're over the cap.
            $current = array_slice($current, 0, self::MAX_USERS_PER_STREAM);

            if ($current !== $this->subscribedUsernames) {
                $this->info('User list changed — aborting stream for reconnect.');

                return -1;
            }
        }

        return strlen($chunk);
    }

    /**
     * Parse a Lichess game event + dispatch `AutoFetchLichessGameJob`
     * for the matching Pending GameMatch. Exposed (not private) so the
     * pure decision logic is testable in isolation without spinning up
     * the curl streaming loop.
     *
     * Skips:
     *   - `started` / `created` events (game just began — settle waits for end).
     *   - Events with missing player userIds (defensive — shouldn't fire).
     *   - Events with no matching Pending Lichess GameMatch (player pair
     *     could be playing a casual game off-Stakly, or a game from a
     *     finished match that we already settled).
     *
     * Returns the dispatched match (or null) so tests can assert on the
     * decision outcome.
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
     * Find a Pending GameMatch on a Lichess-platform listing whose
     * Lichess snapshots cover BOTH given usernames (order-independent,
     * case-insensitive). Returns null if no such match exists.
     *
     * A player can have multiple linked accounts; both could exist as
     * snapshots on the same match. The filter on `listings.platform`
     * ensures we only resolve to Lichess matches — a chess.com match
     * between two players who also happen to be linked on Lichess
     * should NOT settle from a Lichess game record.
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
     * Curl headers for the streaming connection. Always includes
     * `Content-Type: text/plain` (Lichess expects the comma-separated
     * username list as a plain body). When `services.lichess.token` is
     * set (M25), additionally sends `Authorization: Bearer` so the stream
     * is associated with the StaklyBot account — more headroom in Lichess's
     * authenticated stream bucket + a point-of-contact if anything looks
     * weird from their side.
     *
     * Exposed (not private) so the header-build logic is testable in
     * isolation without spinning up curl — mirrors the convention
     * `dispatchFromEvent` uses for its pure-logic surface.
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
     * Pluck the distinct lowercased Lichess usernames across all Pending
     * matches on Lichess-platform listings. Sorted for stable equality
     * checks against the subscribed set.
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
