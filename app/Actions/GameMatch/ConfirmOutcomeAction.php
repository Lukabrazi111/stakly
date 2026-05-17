<?php

namespace App\Actions\GameMatch;

use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a player's outcome confirmation and resolves the match if both
 * players have now confirmed.
 *
 * Players can change their confirmation freely while `Pending` — the
 * "lock" is implicit via match status (once both confirm, the match
 * resolves to `Settled` / `Disputed` and `GameMatchPolicy::confirm`
 * blocks further changes).
 *
 * Returns a sentinel string the controller maps to a flash toast:
 *   - `'too-late'`              → status flipped to non-Pending between policy gate + lock
 *   - `'no-change'`             → same outcome as before, no-op
 *   - `'recorded'`              → first/changed confirmation, waiting for opponent
 *   - `'settled'`               → both agreed on winner (mirror Won/Lost), settled
 *   - `'settled-as-draw'`       → both agreed it was a draw, refund-only
 *   - `'settled-by-api'`        → players disagreed → API arbitrated, winner emerged
 *   - `'settled-by-api-draw'`   → players disagreed → API ruled draw, refund-only
 *   - `'manual-review'`         → API couldn't determine; money locked for admin
 *   - `'disputed'`              → fallback (shouldn't fire with current mock driver)
 *
 * Auto-dispute path (both players claim same Won/Lost OR one Drawn + the
 * other not) runs `ResolveDisputeAction` OUTSIDE the confirm transaction.
 * The mock driver is synchronous + fast today; when M8 swaps in real
 * chess.com / Lichess adapters this becomes a queued job and the page
 * polls for the final state.
 */
class ConfirmOutcomeAction
{
    public function __construct(
        private readonly SettleMatchAction $settle,
        private readonly SettleDrawMatchAction $settleDraw,
        private readonly ResolveDisputeAction $resolveDispute,
    ) {}

    public function handle(User $user, GameMatch $match, MatchOutcome $newOutcome): string
    {
        $resolution = DB::transaction(function () use ($match, $user, $newOutcome) {
            $locked = GameMatch::query()
                ->with(['listing.user', 'taker'])
                ->lockForUpdate()
                ->findOrFail($match->id);

            // Race-check: opponent may have just confirmed and resolved the
            // match between our policy gate and this lock.
            if ($locked->status !== MatchStatus::Pending) {
                return 'too-late';
            }

            if ($this->isSameOutcome($locked, $user, $newOutcome)) {
                return 'no-change';
            }

            $this->recordOutcome($locked, $user, $newOutcome);

            if ($this->bothConfirmed($locked)) {
                return $this->resolveBothConfirmed($locked);
            }

            return 'recorded';
        });

        // Auto-dispute path → trigger API resolution OUTSIDE the confirm
        // transaction. Keeps the call structure flat (no nested savepoints)
        // and lets `resolveDispute` run its own row lock cleanly.
        if ($resolution === 'disputed') {
            $this->resolveDispute->handle($match->fresh());
            $resolution = $this->postDisputeResolutionSentinel($match->fresh());
        }

        return $resolution;
    }

    private function isSameOutcome(GameMatch $match, User $user, MatchOutcome $newOutcome): bool
    {
        $current = $this->isCreator($match, $user)
            ? $match->creator_confirmed_outcome
            : $match->taker_confirmed_outcome;

        return $current === $newOutcome;
    }

    private function recordOutcome(GameMatch $match, User $user, MatchOutcome $newOutcome): void
    {
        $column = $this->isCreator($match, $user)
            ? 'creator_confirmed_outcome'
            : 'taker_confirmed_outcome';

        $match->{$column} = $newOutcome;
        $match->save();
    }

    private function bothConfirmed(GameMatch $match): bool
    {
        return $match->creator_confirmed_outcome !== null
            && $match->taker_confirmed_outcome !== null;
    }

    /**
     * Three resolution paths once both players have confirmed:
     *   - Both Drawn       → settle as draw (refund both, no fee).
     *   - Mirror Won/Lost  → settle, winner takes pot − fee.
     *   - Anything else    → flip to Disputed for API arbitration.
     */
    private function resolveBothConfirmed(GameMatch $match): string
    {
        $creatorOutcome = $match->creator_confirmed_outcome;
        $takerOutcome = $match->taker_confirmed_outcome;

        if ($creatorOutcome === MatchOutcome::Drawn && $takerOutcome === MatchOutcome::Drawn) {
            $this->settleDraw->handle($match);

            return 'settled-as-draw';
        }

        $isMirrorWonLost = ($creatorOutcome === MatchOutcome::Won && $takerOutcome === MatchOutcome::Lost)
            || ($creatorOutcome === MatchOutcome::Lost && $takerOutcome === MatchOutcome::Won);

        if ($isMirrorWonLost) {
            $winner = $creatorOutcome === MatchOutcome::Won
                ? $match->listing->user
                : $match->taker;

            $this->settle->handle($match, $winner);

            return 'settled';
        }

        // Real disagreement — game-API arbitrates.
        $match->update([
            'status' => MatchStatus::Disputed,
            'dispute_opened_at' => now(),
        ]);

        return 'disputed';
    }

    /**
     * Translate a post-`resolveDispute` match status into the toast sentinel.
     * A `Settled` match is distinguished by whether a `winner_user_id` was
     * set — when null, the API ruled it a draw and both stakes were refunded.
     */
    private function postDisputeResolutionSentinel(GameMatch $match): string
    {
        return match ($match->status) {
            MatchStatus::Settled => $match->winner_user_id === null
                ? 'settled-by-api-draw'
                : 'settled-by-api',
            MatchStatus::ManualReview => 'manual-review',
            default => 'disputed',
        };
    }

    private function isCreator(GameMatch $match, User $user): bool
    {
        return $match->listing->user_id === $user->id;
    }
}
