<?php

namespace App\Jobs;

use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;
use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\ChessComGameResult;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts an auto-fetched chess.com game card for a Pending match, then
 * immediately settles via `SettleFromCardAction` (M16 — API is the only
 * outcome source, no player Won/Lost/Drawn confirms).
 *
 * Mirror of `AutoFetchLichessGameJob` adapted to chess.com's
 * archive-based API + eventual-consistency lag.
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
 *     + `SettleFromCardAction` resolve the matching snapshot side.
 */
class AutoFetchChessComGameJob implements ShouldBeUnique, ShouldQueueAfterCommit
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
     * Cap how long the unique lock can persist regardless of job state.
     * The release-on-empty retry chain can keep the job "in flight" for
     * up to 65s; this gives a comfortable buffer past that without
     * stranding the lock if the queue worker dies mid-retry.
     */
    public int $uniqueFor = 120;

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

    /**
     * One in-flight job per match — M16 Phase 2 trigger sites (page-visit,
     * chat-send, cron, stream) all funnel through `DispatchAutoFetchAction`
     * and any of them might fire while a previous job is still running.
     * The unique lock dedupes those races so we don't spam the provider API.
     * Lock releases on job success / failure / final-retry-exhausted.
     */
    public function uniqueId(): string
    {
        return (string) $this->match->id;
    }

    public function handle(
        ChessComGameClient $client,
        PostSystemMessageAction $postSystem,
        SettleFromCardAction $settleFromCard,
    ): void {
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

        $completed = array_values(array_filter(
            $games,
            // Decisive winner OR a draw (agreed / repetition / 50move / etc.).
            // Aborted / half-played excluded — not a real result to settle.
            fn (ChessComGameResult $g) => $g->isDecisive() || $g->isDraw(),
        ));

        if (count($completed) === 0) {
            $this->retryIfBudgetRemains();

            return;
        }

        if (count($completed) !== 1) {
            return;
        }

        $card = $this->postCard($postSystem, $completed[0]);

        // M16 — card IS the settlement trigger. SettleFromCardAction
        // row-locks the match, no-ops if not Pending (idempotent re-runs),
        // branches on winner_color to SettleMatchAction (winner) or
        // SettleDrawMatchAction (draw).
        $settleFromCard->handle($this->match, $card);
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

    /**
     * @return array<string, mixed> the card payload posted to chat — passed
     *                              to SettleFromCardAction so the settle
     *                              decision uses the same data the players see.
     */
    private function postCard(PostSystemMessageAction $postSystem, ChessComGameResult $game): array
    {
        $card = $this->buildEntry($game);

        $postSystem->handle(
            $this->match,
            __('Verified chess.com game record.'),
            [$card],
        );

        return $card;
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
