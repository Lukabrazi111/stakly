<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves a single timed-out `Pending` match per the Phase 7 rules.
 * Designed to be called from the iteration loop in
 * `App\Console\Commands\MatchesResolveTimeouts`.
 *
 * Resolution rules (locked in milestones.md Phase 7):
 *   - One `Won` + silent opponent → confirmer wins (honor claim).
 *   - One `Lost` + silent opponent → opponent wins (claim still honored:
 *     confirmer told us the opponent won, so they did).
 *   - One `Drawn` + silent opponent → game-API arbitrates (single Drawn
 *     can't unilaterally declare a draw).
 *   - Neither confirmed → game-API arbitrates.
 *   - Both confirmed but still `Pending` → defensive log + skip. The
 *     synchronous resolver in `ConfirmOutcomeAction` should have caught
 *     this; if it didn't we want the anomaly logged, not auto-resolved.
 *
 * Returns one of:
 *   - `'settled'`  → directly settled via `SettleMatchAction`.
 *   - `'disputed'` → flipped to Disputed + `ResolveDisputeAction` ran.
 *   - `'skipped'`  → no longer eligible (race, anomaly, etc.).
 *
 * `ResolveDisputeAction` runs OUTSIDE the row-locked transaction
 * (mirrors `ConfirmOutcomeAction`) — keeps the row lock free of API
 * latency once real adapters land in M8.
 */
class ResolveMatchTimeoutAction
{
    public function __construct(
        private readonly SettleMatchAction $settle,
        private readonly ResolveDisputeAction $resolveDispute,
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(int $matchId, DateTimeInterface $deadline): string
    {
        $action = DB::transaction(function () use ($matchId, $deadline) {
            $match = GameMatch::query()->lockForUpdate()->find($matchId);

            if (! $this->isStillEligible($match, $deadline)) {
                return 'skip';
            }

            $match->load(['listing.user', 'taker']);

            if ($this->isBothConfirmedAnomaly($match)) {
                $this->reportAnomaly($match);

                return 'skip';
            }

            return $this->resolveByConfirmations($match);
        });

        if ($action === 'dispute') {
            $this->resolveDispute->handle(GameMatch::query()->findOrFail($matchId));

            return 'disputed';
        }

        return $action === 'settle' ? 'settled' : 'skipped';
    }

    /**
     * Re-check inside the lock: a synchronous confirm may have just
     * resolved this match between our SELECT and the lock.
     */
    private function isStillEligible(?GameMatch $match, DateTimeInterface $deadline): bool
    {
        return $match !== null
            && $match->status === MatchStatus::Pending
            && $match->created_at->lte($deadline);
    }

    private function isBothConfirmedAnomaly(GameMatch $match): bool
    {
        return $match->creator_confirmed_outcome !== null
            && $match->taker_confirmed_outcome !== null;
    }

    private function reportAnomaly(GameMatch $match): void
    {
        report(new RuntimeException(
            "ResolveMatchTimeoutAction: match #{$match->id} has both confirmations set but status is Pending. Synchronous resolver should have handled this."
        ));
    }

    /**
     * Branches per the locked Phase 7 rules. Returns:
     *   - 'settle'  — single Won/Lost confirmer; we'll call SettleMatchAction.
     *   - 'dispute' — single Drawn or neither confirmed; status flipped to
     *                 Disputed, caller dispatches `ResolveDisputeAction`
     *                 outside the transaction.
     */
    private function resolveByConfirmations(GameMatch $match): string
    {
        $this->postSystem->handle(
            $match,
            __('4-hour confirmation window expired. Resolving the match now.'),
        );

        $singleConfirmedOutcome = $match->creator_confirmed_outcome ?? $match->taker_confirmed_outcome;

        if ($singleConfirmedOutcome === null || $singleConfirmedOutcome === MatchOutcome::Drawn) {
            $match->update([
                'status' => MatchStatus::Disputed,
                'dispute_opened_at' => now(),
            ]);

            return 'dispute';
        }

        $winner = $this->winnerByHonoredClaim($match, $singleConfirmedOutcome);

        $this->settle->handle($match, $winner);

        return 'settle';
    }

    /**
     * Honor the confirmer's claim:
     *   - `Won`  + silent → confirmer wins.
     *   - `Lost` + silent → opponent wins (confirmer told us the opponent won).
     */
    private function winnerByHonoredClaim(GameMatch $match, MatchOutcome $confirmedOutcome): User
    {
        $confirmerIsCreator = $match->creator_confirmed_outcome !== null;
        $confirmer = $confirmerIsCreator ? $match->listing->user : $match->taker;
        $opponent = $confirmerIsCreator ? $match->taker : $match->listing->user;

        return $confirmedOutcome === MatchOutcome::Won ? $confirmer : $opponent;
    }
}
