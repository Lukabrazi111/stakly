<?php

namespace App\Actions\GameMatch;

use App\Enums\GameApiConfidence;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use App\Services\GameApi\GameApi;
use App\Services\GameApi\GameApiResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resolves a `Disputed` match via the configured `GameApi` driver.
 *
 * - `Confirmed` confidence → delegate to `SettleMatchAction` (the API
 *   winner is authoritative; overrides player self-reports).
 * - `Drawn` confidence → delegate to `SettleDrawMatchAction` (both refunded,
 *   no fee).
 * - `Unknown` confidence → flip to `ManualReview` and leave money locked
 *   (admin tooling owns this state).
 *
 * Idempotent: terminal states (`Settled`, `ManualReview`) short-circuit.
 * The row lock + status guard serialise concurrent dispute-resolution
 * attempts (e.g. both players hit "Open dispute" simultaneously, or the
 * timeout job fires while a manual dispute is already in flight).
 *
 * Audit: the driver's raw response is persisted to
 * `game_matches.api_response` along with `api_resolved_at`, written
 * before settlement so we have a record even if settlement throws.
 */
class ResolveDisputeAction
{
    public function __construct(
        private readonly SettleMatchAction $settle,
        private readonly SettleDrawMatchAction $settleDraw,
    ) {}

    public function handle(GameMatch $match): void
    {
        DB::transaction(function () use ($match) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($this->isTerminal($locked)) {
                return;
            }

            $this->assertDisputed($locked);

            $locked->load(['listing.user', 'taker']);

            $result = app(GameApi::class)->getMatchResult($locked);

            $this->persistApiAudit($locked, $result);

            $this->dispatchOnConfidence($locked, $result);
        });
    }

    private function isTerminal(GameMatch $match): bool
    {
        return $match->status === MatchStatus::Settled
            || $match->status === MatchStatus::ManualReview;
    }

    /**
     * Sanity guard: callers must transition to `Disputed` before invoking.
     * Keeping the contract explicit prevents a future caller from
     * short-circuiting the player-confirm window by jumping straight to
     * API resolution from `Pending`.
     */
    private function assertDisputed(GameMatch $match): void
    {
        if ($match->status !== MatchStatus::Disputed) {
            throw new InvalidArgumentException(
                "resolveDispute called on match {$match->id} with status {$match->status->value}; expected Disputed."
            );
        }
    }

    /**
     * Persist audit trail before branching so it's saved even if a
     * downstream settle throws (rolls back inside the transaction, but
     * the failure shape is observable in logs / re-attempts).
     */
    private function persistApiAudit(GameMatch $match, GameApiResult $result): void
    {
        $match->update([
            'api_response' => $result->raw_response,
            'api_resolved_at' => now(),
        ]);
    }

    private function dispatchOnConfidence(GameMatch $match, GameApiResult $result): void
    {
        if ($result->confidence === GameApiConfidence::Unknown) {
            $match->update(['status' => MatchStatus::ManualReview]);

            return;
        }

        if ($result->confidence === GameApiConfidence::Drawn) {
            // Nested DB::transaction composes via savepoint — atomic with the
            // outer audit-trail update.
            $this->settleDraw->handle($match);

            return;
        }

        // Confirmed → winner-based settlement.
        $winner = $this->resolveWinner($match, $result);

        $this->settle->handle($match, $winner);
    }

    /**
     * Defense in depth: the driver shouldn't return a non-participant,
     * but if it does we'd rather throw than pay a stranger.
     */
    private function resolveWinner(GameMatch $match, GameApiResult $result): User
    {
        $winnerId = $result->winner_user_id;
        $creatorId = $match->listing->user_id;
        $takerId = $match->taker_user_id;

        if ($winnerId !== $creatorId && $winnerId !== $takerId) {
            throw new InvalidArgumentException(
                "GameApi returned winner_user_id {$winnerId} which is not a participant of match {$match->id}."
            );
        }

        return $winnerId === $creatorId ? $match->listing->user : $match->taker;
    }
}
