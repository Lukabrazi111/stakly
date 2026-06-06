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
use App\Services\Provider\Exceptions\ProviderError;
use App\Services\Provider\LichessGameClient;
use App\Services\Provider\LichessGameResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Posts an auto-fetched Lichess game card for a Pending match, then settles via
 * `SettleFromCardAction`. Searches Lichess for games between the snapshotted usernames
 * since `match.created_at`; exactly one completed candidate triggers post + settle.
 * Zero or multiple candidates skip silently — wrong-game evidence is worse than none.
 *
 * Snapshot-cross-checked (not live-looked-up) so a mid-match unlink can't strip the anchor.
 * Idempotent via attachments_json scan (`alreadyPosted()`) — re-dispatch / queue-retry won't
 * double-post or double-settle.
 */
class AutoFetchLichessGameJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

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
        LichessGameClient $client,
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
            LinkedAccountProvider::Lichess,
        );
        $takerUsername = $this->match->snapshotUsername(
            GameMatch::SIDE_TAKER,
            LinkedAccountProvider::Lichess,
        );

        if ($creatorUsername === null || $takerUsername === null) {
            // Defensive — caller gates on this, but re-dispatch could land here out of context.
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

        $completed = $this->filterCompleted($games);
        $count = count($completed);

        if ($count === 0) {
            $this->record($recordAttempt, AutoFetchOutcome::NoMatch, [
                'candidates_count' => 0,
                'latency_ms' => $latencyMs,
            ]);

            return;
        }

        if ($count > 1) {
            // Manual paste covers the ambiguous case.
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => $count,
                'latency_ms' => $latencyMs,
            ]);

            return;
        }

        $game = $completed[0];
        $card = $this->postCard($postSystem, $game);

        // Audit row lands before settlement so the pipeline decision is recorded even if settle throws.
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
     * @return array{0: list<LichessGameResult>|null, 1: string|null, 2: int}
     */
    private function searchWithMetrics(
        LichessGameClient $client,
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
     * Decisive or draw only — aborted / half-played skipped (not a real result to settle).
     *
     * @param  list<LichessGameResult>  $games
     * @return list<LichessGameResult>
     */
    private function filterCompleted(array $games): array
    {
        return array_values(array_filter(
            $games,
            fn (LichessGameResult $g) => $g->isDecisive() || $g->isDraw(),
        ));
    }

    /**
     * @return array<string, mixed> card payload — passed to SettleFromCardAction so the
     *                              settle decision uses the same data the players see.
     */
    private function postCard(PostSystemMessageAction $postSystem, LichessGameResult $game): array
    {
        $card = $this->buildEntry($game);

        // Text is intentionally terse — the attached card carries the detail.
        $postSystem->handle(
            $this->match,
            __('Verified Lichess game record.'),
            [$card],
        );

        return $card;
    }

    /**
     * Postgres `@>` containment match on any system message in this match with
     * `source = auto_fetch` in attachments. Per-match scan is bounded by chat message count.
     */
    private function alreadyPosted(): bool
    {
        return Message::query()
            ->where('match_id', $this->match->id)
            ->where('type', MessageType::System)
            ->whereJsonContains('attachments_json', [['source' => 'auto_fetch']])
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(LichessGameResult $game): array
    {
        return [
            'type' => 'game_card',
            'provider' => 'lichess',
            'source' => 'auto_fetch',
            'game_id' => $game->id,
            'url' => 'https://lichess.org/'.$game->id,
            // Always verified — the search is username-anchored on both snapshots.
            'verified' => true,
            'white_username' => $game->whiteUsername,
            'black_username' => $game->blackUsername,
            'winner_color' => $game->winnerColor,
            'winner_username' => $game->winnerUsername(),
            'status' => $game->status,
            'speed' => $game->speed,
            'variant' => $game->variant,
            'rated' => $game->rated,
            'played_at' => $game->lastMoveAt->toIso8601String(),
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
            provider: LinkedAccountProvider::Lichess,
            outcome: $outcome,
            extras: $extras,
        );
    }
}
