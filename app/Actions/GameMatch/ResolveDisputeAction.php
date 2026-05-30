<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
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
 * Confidence → action: `Confirmed` settles to winner (API overrides player reports),
 * `Drawn` refunds both, `Unknown` flips to `ManualReview` and leaves money locked.
 *
 * Idempotent: terminal states (`Settled`, `ManualReview`) short-circuit under row lock.
 * The driver's raw response is persisted before settlement so we keep the record even if settlement throws.
 */
class ResolveDisputeAction
{
    public function __construct(
        private readonly SettleMatchAction $settle,
        private readonly SettleDrawMatchAction $settleDraw,
        private readonly PostSystemMessageAction $postSystem,
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
     * Callers must transition to `Disputed` first — prevents a future caller from
     * short-circuiting the player-confirm window by jumping straight from `Pending`.
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
     * Persist before branching so the failure shape is observable even if a downstream settle throws.
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
            $this->flipToManualReview($match);

            return;
        }

        if ($result->confidence === GameApiConfidence::Drawn) {
            // Nested DB::transaction composes via savepoint — atomic with the outer audit-trail update.
            $this->settleDraw->handle($match);

            return;
        }

        $winner = $this->resolveWinner($match, $result);

        $this->settle->handle($match, $winner);
    }

    /**
     * Two system messages: narration + `dispute_prompt`-marked evidence call-to-action.
     * The marker attachment lets `SystemBubble` render a warning variant without copy-matching.
     */
    private function flipToManualReview(GameMatch $match): void
    {
        $match->update(['status' => MatchStatus::ManualReview]);

        $this->postSystem->handle(
            $match,
            __('Game API could not determine a winner. Match flagged for admin review — your stakes stay in escrow until resolved.'),
        );

        $this->postSystem->handle(
            $match,
            __('Submit evidence in chat — screenshot, game URL, or PGN. An admin will review.'),
            [['type' => 'dispute_prompt']],
        );
    }

    /**
     * Defense in depth — driver shouldn't return a non-participant, but if it does, throw rather than pay a stranger.
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
