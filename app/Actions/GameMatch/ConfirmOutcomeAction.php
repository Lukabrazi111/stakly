<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Jobs\AutoFetchChessComGameJob;
use App\Jobs\AutoFetchLichessGameJob;
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
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(User $user, GameMatch $match, MatchOutcome $newOutcome): string
    {
        $wasFirstConfirm = false;

        $resolution = DB::transaction(function () use ($match, $user, $newOutcome, &$wasFirstConfirm) {
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

            // Capture the zero→one transition BEFORE writing the outcome.
            // True iff both confirmation columns are null at lock time —
            // this user's write is about to make them one-and-null. M8
            // Phase 4 auto-fetch triggers off this specific transition so
            // we don't re-query Lichess on every confirm change.
            $wasFirstConfirm = $locked->creator_confirmed_outcome === null
                && $locked->taker_confirmed_outcome === null;

            $this->recordOutcome($locked, $user, $newOutcome);

            $this->postSystem->handle(
                $locked,
                __(':name confirmed: :outcome.', [
                    'name' => $user->name,
                    'outcome' => $this->labelFor($newOutcome),
                ]),
            );

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

        // Phase 4 auto-fetch — fires once per match on the zero→one confirm
        // transition. Picks the right provider's job based on
        // `listing.platform` (M8 Phase 5 Slice B): chess.com listings get
        // the chess.com auto-fetch job, Lichess listings get the Lichess
        // one. Dispatched OUTSIDE the transaction so the system "X confirmed"
        // message is durable before the worker runs and the job's
        // idempotency check sees a consistent view of the chat. Jobs are
        // also `ShouldQueueAfterCommit` as belt-and-suspenders.
        if ($wasFirstConfirm) {
            $fresh = $match->fresh(['providerSnapshots', 'listing']);

            $this->dispatchAutoFetch($fresh);
        }

        return $resolution;
    }

    /**
     * Pick the auto-fetch job for the listing's platform. Both jobs
     * require the relevant provider's snapshot to be present on BOTH
     * sides — otherwise we can't anchor a verified card, so skip silently.
     */
    private function dispatchAutoFetch(GameMatch $match): void
    {
        $platform = $match->listing->platform;

        $creatorSnap = $match->snapshotUsername(GameMatch::SIDE_CREATOR, $platform);
        $takerSnap = $match->snapshotUsername(GameMatch::SIDE_TAKER, $platform);

        if ($creatorSnap === null || $takerSnap === null) {
            return;
        }

        match ($platform) {
            LinkedAccountProvider::Lichess => AutoFetchLichessGameJob::dispatch($match),
            LinkedAccountProvider::ChessCom => AutoFetchChessComGameJob::dispatch($match),
        };
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

        // Real disagreement — game-API arbitrates. Narrate the auto-dispute
        // explicitly so the chat doesn't jump silently from "Bob confirmed:
        // Won" straight to "Match settled. {name} wins". Posting the system
        // message before the status flip keeps it ordered ahead of any
        // settlement message ResolveDisputeAction → SettleMatchAction will
        // emit a moment later (when the mock arbitrates synchronously inside
        // the same request).
        $this->postSystem->handle(
            $match,
            __('Players\' confirmations conflict. Resolving via the game record.'),
        );

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

    /**
     * Human-readable label for a `MatchOutcome` — used in system message
     * copy. Title-case rather than the raw enum value so the message reads
     * "Alice confirmed: Won." not "Alice confirmed: won."
     */
    private function labelFor(MatchOutcome $outcome): string
    {
        return match ($outcome) {
            MatchOutcome::Won => 'Won',
            MatchOutcome::Lost => 'Lost',
            MatchOutcome::Drawn => 'Drawn',
        };
    }
}
