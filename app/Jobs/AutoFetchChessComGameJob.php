<?php

namespace App\Jobs;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;
use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\ChessComGameResult;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts an auto-fetched chess.com game card on the first player confirm
 * (M8 Phase 4b auto-fetch path). Mirror of `AutoFetchLichessGameJob`
 * adapted to chess.com's archive-based API + eventual-consistency lag.
 *
 * Key delta from the Lichess version:
 *
 *   - **Retry on empty.** chess.com's monthly archive lags 5-15s after
 *     game-end. If the first attempt finds zero candidates, release back
 *     to the queue with backoff (5s / 15s / 45s — 65s total wait across
 *     three attempts). After that, treat as "no game" and skip silently.
 *
 *   - Provider-specific snapshot lookup: reads
 *     `match.{side}_chess_com_username` (via `snapshotUsername()`) instead
 *     of the Lichess columns.
 *
 *   - Posted card uses `provider: 'chess_com'` so the frontend renderer
 *     + arbitration driver pick the right code path.
 */
class AutoFetchChessComGameJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 4 attempts = original + 3 retries. Backoff cadence below — total
     * upper bound is ~65s, which comfortably outlasts typical chess.com
     * archive lag (5-15s).
     */
    public int $tries = 4;

    public int $timeout = 30;

    /**
     * Delay (seconds) before the next attempt when the search returns no
     * candidates yet. Index `$this->attempts() - 1` so the first retry
     * waits 5s, second 15s, third 45s.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS = [5, 15, 45];

    public function __construct(
        public GameMatch $match,
    ) {}

    public function handle(ChessComGameClient $client, PostSystemMessageAction $postSystem): void
    {
        if ($this->alreadyPosted()) {
            return;
        }

        $this->match->loadMissing('providerSnapshots');

        $creatorUsername = $this->match->snapshotUsername(
            GameMatch::SIDE_CREATOR,
            LinkedAccountProvider::ChessCom,
        );
        $takerUsername = $this->match->snapshotUsername(
            GameMatch::SIDE_TAKER,
            LinkedAccountProvider::ChessCom,
        );

        if ($creatorUsername === null || $takerUsername === null) {
            return;
        }

        $games = $this->searchSafely($client, $creatorUsername, $takerUsername);

        if ($games === null) {
            return;
        }

        $decisive = array_values(array_filter(
            $games,
            fn (ChessComGameResult $g) => $g->isDecisive(),
        ));

        if (count($decisive) === 0) {
            $this->retryIfBudgetRemains();

            return;
        }

        if (count($decisive) !== 1) {
            return;
        }

        $this->postCard($postSystem, $decisive[0]);
    }

    /**
     * @return list<ChessComGameResult>|null null on provider failure (logged
     *                                       + swallowed) so caller aborts.
     */
    private function searchSafely(
        ChessComGameClient $client,
        string $creatorUsername,
        string $takerUsername,
    ): ?array {
        try {
            return $client->searchGamesBetween(
                $creatorUsername,
                $takerUsername,
                $this->match->created_at,
            );
        } catch (ProviderUnavailableException $e) {
            Log::info('chess.com auto-fetch search failed (provider unavailable)', [
                'match_id' => $this->match->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('chess.com auto-fetch search failed (unexpected)', [
                'match_id' => $this->match->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * If the search returned no candidates but the archive may still be
     * catching up, release the job for another attempt. After exhausting
     * retries, fall through to silent skip.
     */
    private function retryIfBudgetRemains(): void
    {
        $attempt = $this->attempts();

        if ($attempt >= count(self::RETRY_DELAYS) + 1) {
            return;
        }

        // `attempts()` is 1-indexed; RETRY_DELAYS is 0-indexed. After
        // attempt 1 we want delay 5s (index 0), after attempt 2 → 15s, etc.
        $this->release(self::RETRY_DELAYS[$attempt - 1]);
    }

    private function postCard(PostSystemMessageAction $postSystem, ChessComGameResult $game): void
    {
        $postSystem->handle(
            $this->match,
            __('Verified chess.com game record.'),
            [$this->buildEntry($game)],
        );
    }

    private function alreadyPosted(): bool
    {
        return Message::query()
            ->where('match_id', $this->match->id)
            ->where('type', MessageType::System)
            ->whereJsonContains('attachments_json', [['source' => 'auto_fetch', 'provider' => 'chess_com']])
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(ChessComGameResult $game): array
    {
        return [
            'type' => 'game_card',
            'provider' => 'chess_com',
            'source' => 'auto_fetch',
            'game_id' => $game->id,
            'url' => $game->url,
            'verified' => true,
            'white_username' => $game->whiteUsername,
            'black_username' => $game->blackUsername,
            'winner_color' => $game->winnerColor,
            'winner_username' => $game->winnerUsername(),
            'status' => $game->status,
            'speed' => $game->speed,
            'variant' => $game->variant,
            'rated' => $game->rated,
            'played_at' => $game->endedAt->toIso8601String(),
        ];
    }
}
