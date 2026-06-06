<?php

namespace App\Jobs;

use App\Actions\GameMatch\RecordAutoFetchAttemptAction;
use App\Actions\GameMatch\SettleFromCardAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\Message;
use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\ChessComGameResult;
use App\Services\Provider\Exceptions\ProviderError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Posts an auto-fetched chess.com game card for a Pending match, then settles via
 * `SettleFromCardAction`. Retries on empty candidates because chess.com's monthly
 * archive lags 5-15s after game-end (5s / 15s / 45s = ~65s total).
 *
 * Every attempt (including empty ones) writes a `match_auto_fetch_attempts` row so
 * the admin timeline shows the full retry chain rather than a single terminal row.
 */
class AutoFetchChessComGameJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    /**
     * Caps unique-lock lifetime past the ~65s retry chain so the lock doesn't strand
     * if the queue worker dies mid-retry.
     */
    public int $uniqueFor = 120;

    /**
     * @var list<int>
     */
    private const RETRY_DELAYS = [5, 15, 45];

    public function __construct(
        public GameMatch $match,
    ) {}

    /**
     * One in-flight job per match — dedupe races between page-visit, chat-send, cron, stream
     * trigger sites so we don't spam the provider API.
     */
    public function uniqueId(): string
    {
        return (string) $this->match->id;
    }

    public function handle(
        ChessComGameClient $client,
        PostSystemMessageAction $postSystem,
        SettleFromCardAction $settleFromCard,
        RecordAutoFetchAttemptAction $recordAttempt,
    ): void {
        if ($this->alreadyPosted()) {
            $this->record($recordAttempt, AutoFetchOutcome::Skipped, [
                'outcome_reason' => 'already_posted',
            ]);

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
            $this->record($recordAttempt, AutoFetchOutcome::Skipped, [
                'outcome_reason' => 'snapshot_missing',
            ]);

            return;
        }

        [$games, $errorMessage, $latencyMs] = $this->searchWithMetrics(
            $client,
            $creatorUsername,
            $takerUsername,
        );

        if ($games === null) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $errorMessage,
                'latency_ms' => $latencyMs,
            ]);

            return;
        }

        $completed = array_values(array_filter(
            $games,
            // Decisive or draw — aborted / half-played excluded (not a real result to settle).
            fn (ChessComGameResult $g) => $g->isDecisive() || $g->isDraw(),
        ));
        $count = count($completed);

        if ($count === 0) {
            // Record before releasing so each retry shows independently in the audit timeline.
            $reason = $this->isFinalAttempt() ? 'retry_exhausted' : null;
            $this->record($recordAttempt, AutoFetchOutcome::NoMatch, [
                'candidates_count' => 0,
                'latency_ms' => $latencyMs,
                'outcome_reason' => $reason,
            ]);

            $this->retryIfBudgetRemains();

            return;
        }

        if ($count > 1) {
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => $count,
                'latency_ms' => $latencyMs,
            ]);

            return;
        }

        $game = $completed[0];
        $card = $this->postCard($postSystem, $game);

        $this->record($recordAttempt, AutoFetchOutcome::Matched, [
            'winner_username' => $game->winnerUsername(),
            'candidates_count' => 1,
            'latency_ms' => $latencyMs,
        ]);

        // The card IS the settlement trigger. SettleFromCardAction row-locks the match,
        // no-ops if not Pending (idempotent), branches winner vs draw.
        $settleFromCard->handle($this->match, $card);
    }

    /**
     * Returns `[games, errorMessage, latencyMs]` — exactly one of `games` / `errorMessage` is non-null.
     *
     * @return array{0: list<ChessComGameResult>|null, 1: string|null, 2: int}
     */
    private function searchWithMetrics(
        ChessComGameClient $client,
        string $creatorUsername,
        string $takerUsername,
    ): array {
        $start = microtime(true);

        try {
            $games = $client->searchGamesBetween(
                $creatorUsername,
                $takerUsername,
                $this->match->created_at,
            );

            return [$games, null, $this->elapsedMs($start)];
        } catch (ProviderError $e) {
            return [null, $e->getMessage(), $this->elapsedMs($start)];
        } catch (Throwable $e) {
            return [null, $e->getMessage(), $this->elapsedMs($start)];
        }
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * Marks the terminal `NoMatch` row with `retry_exhausted` so PipelineHealth can distinguish
     * "still hoping the archive catches up" from "we gave up."
     */
    private function isFinalAttempt(): bool
    {
        return $this->attempts() >= count(self::RETRY_DELAYS) + 1;
    }

    private function retryIfBudgetRemains(): void
    {
        $attempt = $this->attempts();

        if ($attempt >= count(self::RETRY_DELAYS) + 1) {
            return;
        }

        // `attempts()` is 1-indexed; RETRY_DELAYS is 0-indexed.
        $this->release(self::RETRY_DELAYS[$attempt - 1]);
    }

    /**
     * @return array<string, mixed> card payload — passed to SettleFromCardAction so the
     *                              settle decision uses the same data the players see.
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

    /**
     * @param  array<string, mixed>  $extras
     */
    private function record(
        RecordAutoFetchAttemptAction $action,
        AutoFetchOutcome $outcome,
        array $extras = [],
    ): void {
        $action->handle(
            matchId: $this->match->id,
            provider: LinkedAccountProvider::ChessCom,
            outcome: $outcome,
            extras: [
                'attempt_number' => $this->attempts(),
                ...$extras,
            ],
        );
    }
}
