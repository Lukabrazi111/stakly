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
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\FaceitGameClient;
use App\Services\Provider\FaceitMatchResult;
use App\Services\Provider\FaceitRosterPlayer;
use App\Services\Provider\ProviderCircuitBreaker;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * Posts an auto-fetched FACEIT match card for a Pending CS2 match, then settles
 * via `SettleFromCardAction`. Sibling to `AutoFetchChessComGameJob` /
 * `AutoFetchLichessGameJob`; same retry + circuit-breaker shape.
 *
 * Match-finding strategy (M15 P4 Slice 2):
 *   - Query creator's FACEIT `/players/{guid}/history` first.
 *   - For each candidate match_id, call `fetchMatch()` to get rosters.
 *   - Verify creator + taker GUIDs are on OPPOSING factions before posting.
 *   - Fall back to taker's history if creator's yields nothing.
 *
 * Anti-cheat gate: only settles matches where every player on both rosters
 * has `anticheat_required === true`. AC-incomplete matches are a terminal
 * `AcIncomplete` outcome (no card, no retry) — admin sees the audit row
 * and the match falls to ManualReview via the existing timeout path.
 *
 * Retry policy (mirrors chess):
 *   - no_match: explicit `release()` per `RETRY_DELAYS` ([5, 15, 45]s).
 *   - HTTP transient error: re-throw + Laravel `backoff()` ([5, 15, 30]s).
 *   - `PermanentProviderError` → audit row + `$this->fail($e)`, no retry.
 *   - `retryUntil()` caps the chain at the match-confirmation timeout.
 *   - `AcIncomplete` → terminal, no retry (the answer doesn't change).
 */
class AutoFetchFaceitGameJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * M35 P3 — widened from 7 to 15 to absorb `RateLimited` middleware
     * releases. Each throttle release consumes an attempt without running
     * the handler; `retryUntil()` is the real safety net.
     */
    public int $tries = 15;

    public int $timeout = 30;

    public int $uniqueFor = 360;

    /**
     * Per-attempt delays for the no_match retry chain. HTTP transient errors
     * use `backoff()` instead.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS = [5, 15, 45];

    /**
     * How many of a player's most-recent matches we'll consider. Typical
     * match-confirmation polling needs the top 1–3; ten is comfortable
     * head-room before falling back to the other player's history.
     */
    private const CANDIDATE_LIMIT = 10;

    public function __construct(
        public GameMatch $match,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->match->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->match->created_at
            ->copy()
            ->addHours((int) config('stakly.match_confirmation_timeout_hours'));
    }

    /**
     * Self-throttle (M35 P3). `faceit-api` limiter is defined in
     * `AppServiceProvider::registerProviderRateLimiters()` and reads
     * `config('services.faceit.requests_per_minute')`. When the cap is
     * hit, this middleware releases the job back to the queue (consuming
     * one of the `$tries` budget) and retries after the limit window.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('faceit-api')];
    }

    public function handle(
        FaceitGameClient $client,
        PostSystemMessageAction $postSystem,
        SettleFromCardAction $settleFromCard,
        RecordAutoFetchAttemptAction $recordAttempt,
        ProviderCircuitBreaker $breaker,
    ): void {
        if ($this->alreadyPosted()) {
            $this->record($recordAttempt, AutoFetchOutcome::Skipped, [
                'outcome_reason' => 'already_posted',
            ]);

            return;
        }

        $this->match->loadMissing('providerSnapshots');

        $creatorGuid = $this->match->snapshotProviderUserId(
            GameMatch::SIDE_CREATOR,
            LinkedAccountProvider::Faceit,
        );
        $takerGuid = $this->match->snapshotProviderUserId(
            GameMatch::SIDE_TAKER,
            LinkedAccountProvider::Faceit,
        );

        if ($creatorGuid === null || $takerGuid === null) {
            $this->record($recordAttempt, AutoFetchOutcome::Skipped, [
                'outcome_reason' => 'snapshot_missing',
            ]);

            return;
        }

        $start = microtime(true);

        try {
            $faceitMatch = $this->findOpposingRosterMatch($client, $creatorGuid, $takerGuid);
        } catch (PermanentProviderError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => 'permanent',
            ]);
            $breaker->recordFailure(LinkedAccountProvider::Faceit);
            $this->fail($e);

            return;
        } catch (RateLimitedError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => $this->errorRetriesExhausted() ? 'retry_exhausted' : null,
            ]);
            $breaker->recordFailure(LinkedAccountProvider::Faceit);

            $retryAt = $e->retryAt();
            // `now()->getTimestamp()` (not PHP's `time()`) so Carbon's
            // `setTestNow` mocking carries through to tests.
            $nowTs = now()->getTimestamp();
            if ($retryAt !== null
                && $retryAt->getTimestamp() > $nowTs
                && ! $this->errorRetriesExhausted()
            ) {
                $this->release(max(1, $retryAt->getTimestamp() - $nowTs));

                return;
            }

            throw $e;
        } catch (ProviderError $e) {
            $this->record($recordAttempt, AutoFetchOutcome::Error, [
                'error_message' => $e->getMessage(),
                'latency_ms' => $this->elapsedMs($start),
                'outcome_reason' => $this->errorRetriesExhausted() ? 'retry_exhausted' : null,
            ]);
            $breaker->recordFailure(LinkedAccountProvider::Faceit);
            throw $e;
        }

        $breaker->recordSuccess(LinkedAccountProvider::Faceit);
        $latencyMs = $this->elapsedMs($start);

        if ($faceitMatch === null) {
            $reason = $this->noMatchRetriesExhausted() ? 'retry_exhausted' : null;
            $this->record($recordAttempt, AutoFetchOutcome::NoMatch, [
                'candidates_count' => 0,
                'latency_ms' => $latencyMs,
                'outcome_reason' => $reason,
            ]);

            $this->retryNoMatchIfBudgetRemains();

            return;
        }

        if (! $faceitMatch->isAntiCheatComplete()) {
            // Terminal outcome — admin sees the audit row, match falls to
            // ManualReview via the existing confirmation-timeout path.
            $this->record($recordAttempt, AutoFetchOutcome::AcIncomplete, [
                'candidates_count' => 1,
                'latency_ms' => $latencyMs,
                'outcome_reason' => 'ac_incomplete',
            ]);

            return;
        }

        if ($faceitMatch->winnerFaction === null) {
            // FINISHED + AC-complete + no winner shouldn't happen in CS2 (ties
            // play to overtime), but defensive — surface as ambiguous rather
            // than posting a malformed card.
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => 1,
                'latency_ms' => $latencyMs,
                'outcome_reason' => 'no_winner',
            ]);

            return;
        }

        $winnerSide = $this->resolveWinnerSide($faceitMatch, $creatorGuid, $takerGuid);

        if ($winnerSide === null) {
            // `findOpposingRosterMatch` already verified both GUIDs are on
            // opposing rosters, so this branch shouldn't fire. Defensive.
            $this->record($recordAttempt, AutoFetchOutcome::Ambiguous, [
                'candidates_count' => 1,
                'latency_ms' => $latencyMs,
                'outcome_reason' => 'winner_unresolvable',
            ]);

            return;
        }

        $winnerUserId = $winnerSide === GameMatch::SIDE_CREATOR
            ? $this->match->listing->user_id
            : $this->match->taker_user_id;

        $winnerUsername = $this->match->snapshotUsername($winnerSide, LinkedAccountProvider::Faceit);

        $card = $this->postCard($postSystem, $faceitMatch, $winnerUserId, $winnerUsername);

        $this->record($recordAttempt, AutoFetchOutcome::Matched, [
            'winner_username' => $winnerUsername,
            'candidates_count' => 1,
            'latency_ms' => $latencyMs,
        ]);

        $settleFromCard->handle($this->match, $card);
    }

    /**
     * Search creator's recent FACEIT matches; if no candidate has the taker
     * on the opposing faction, fall back to taker's history. Returns the
     * first match where both GUIDs are on OPPOSING rosters (the only valid
     * shape for this Stakly match), or null.
     */
    private function findOpposingRosterMatch(
        FaceitGameClient $client,
        string $creatorGuid,
        string $takerGuid,
    ): ?FaceitMatchResult {
        foreach ([$creatorGuid, $takerGuid] as $seedGuid) {
            $matchIds = $client->searchPlayerMatches(
                playerId: $seedGuid,
                since: $this->match->created_at,
                game: 'cs2',
                limit: self::CANDIDATE_LIMIT,
            );

            foreach ($matchIds as $matchId) {
                $candidate = $client->fetchMatch($matchId);

                if ($candidate === null) {
                    continue;
                }

                if ($this->isOpposingRosterPair($candidate, $creatorGuid, $takerGuid)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * True when creator + taker are on opposing factions. Guards against
     * a match where both Stakly users happened to play on the same team
     * (5v5 with friends), which doesn't represent THIS Stakly match.
     */
    private function isOpposingRosterPair(
        FaceitMatchResult $match,
        string $creatorGuid,
        string $takerGuid,
    ): bool {
        $creatorIn1 = $this->guidInRoster($match->faction1Roster, $creatorGuid);
        $creatorIn2 = $this->guidInRoster($match->faction2Roster, $creatorGuid);
        $takerIn1 = $this->guidInRoster($match->faction1Roster, $takerGuid);
        $takerIn2 = $this->guidInRoster($match->faction2Roster, $takerGuid);

        return ($creatorIn1 && $takerIn2) || ($creatorIn2 && $takerIn1);
    }

    private function resolveWinnerSide(
        FaceitMatchResult $match,
        string $creatorGuid,
        string $takerGuid,
    ): ?string {
        $winnerRoster = $match->winnerRoster();

        if ($this->guidInRoster($winnerRoster, $creatorGuid)) {
            return GameMatch::SIDE_CREATOR;
        }

        if ($this->guidInRoster($winnerRoster, $takerGuid)) {
            return GameMatch::SIDE_TAKER;
        }

        return null;
    }

    /**
     * @param  list<FaceitRosterPlayer>  $roster
     */
    private function guidInRoster(array $roster, string $guid): bool
    {
        foreach ($roster as $player) {
            if ($player->playerId === $guid) {
                return true;
            }
        }

        return false;
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function noMatchRetriesExhausted(): bool
    {
        return $this->attempts() >= count(self::RETRY_DELAYS) + 1;
    }

    private function errorRetriesExhausted(): bool
    {
        return $this->attempts() >= $this->tries;
    }

    private function retryNoMatchIfBudgetRemains(): void
    {
        if ($this->noMatchRetriesExhausted()) {
            return;
        }

        $this->release(self::RETRY_DELAYS[$this->attempts() - 1]);
    }

    /**
     * @return array<string, mixed> card payload — passed to SettleFromCardAction so
     *                              the settle decision uses the same data the players see.
     */
    private function postCard(
        PostSystemMessageAction $postSystem,
        FaceitMatchResult $match,
        int $winnerUserId,
        ?string $winnerUsername,
    ): array {
        $card = $this->buildEntry($match, $winnerUserId, $winnerUsername);

        $postSystem->handle(
            $this->match,
            __('Verified FACEIT match record.'),
            [$card],
        );

        return $card;
    }

    private function alreadyPosted(): bool
    {
        return Message::query()
            ->where('match_id', $this->match->id)
            ->where('type', MessageType::System)
            ->whereJsonContains('attachments_json', [['source' => 'auto_fetch', 'provider' => 'faceit']])
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(
        FaceitMatchResult $match,
        int $winnerUserId,
        ?string $winnerUsername,
    ): array {
        return [
            'type' => 'game_card',
            'provider' => 'faceit',
            'source' => 'auto_fetch',
            'match_id' => $match->id,
            'url' => "https://www.faceit.com/en/{$match->game}/room/{$match->id}",
            'verified' => true,
            'game' => $match->game,
            'competition_type' => $match->competitionType,
            'status' => $match->status,
            'winner_faction' => $match->winnerFaction,
            'winner_username' => $winnerUsername,
            'winner_user_id' => $winnerUserId,
            'ac_complete' => true,
            'started_at' => $match->startedAt?->toIso8601String(),
            'finished_at' => $match->finishedAt?->toIso8601String(),
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
            provider: LinkedAccountProvider::Faceit,
            outcome: $outcome,
            extras: [
                'attempt_number' => $this->attempts(),
                ...$extras,
            ],
        );
    }
}
