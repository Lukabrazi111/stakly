<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 of the M10 mutual cancellation flow: the *other* participant
 * accepts the pending request. Match flips Pending → Cancelled, both
 * stakes refunded via `Wallet::release`, listing flips Taken → Cancelled.
 * Symmetric with `SettleDrawMatchAction`'s refund-both pattern — no
 * platform fee, no winner, no record impact.
 *
 * Returns:
 *   - `'cancelled'`              — match cancelled, refunds posted.
 *   - `'already_cancelled'`      — idempotent re-call after a prior accept
 *                                  already flipped the match (covers double-
 *                                  click / retry). No-op, no second refund
 *                                  (Wallet idempotency keys would short-
 *                                  circuit anyway).
 *   - `'race_lost'`              — match was no longer Pending by the time
 *                                  our lock acquired (settled / disputed /
 *                                  another resolution path ran first).
 *   - `'request_missing'`        — no open request to accept. Controller
 *                                  toast: "There's no open request."
 *   - `'self_accept_forbidden'`  — defensive: the requester themselves
 *                                  tried to accept their own request.
 *                                  Policy gates this upstream; the Action
 *                                  guards it again so a bypass-policy
 *                                  caller (artisan, future webhook) can't
 *                                  self-resolve.
 *
 * Conservation per cancelled match:
 *   `-A_stake + -B_stake + +A_release + +B_release = 0`
 *
 * Audit invariant: `confirmed_outcome` columns are NOT cleared. If Alice
 * had clicked Won before the cancel, the historical record of her claim
 * survives — useful for forensics if a dispute about the cancellation
 * itself arises later. The terminal `Cancelled` status prevents these
 * columns from being acted on (status guard in `confirm` policy).
 */
class AcceptCancellationAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(User $accepter, GameMatch $match): string
    {
        return DB::transaction(function () use ($match, $accepter) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status === MatchStatus::Cancelled) {
                return 'already_cancelled';
            }

            if ($locked->status !== MatchStatus::Pending) {
                return 'race_lost';
            }

            if ($locked->cancellation_requested_at === null) {
                return 'request_missing';
            }

            if ($locked->cancellation_requested_by === $accepter->id) {
                return 'self_accept_forbidden';
            }

            $locked->load('listing.user', 'taker');

            $this->refundBothStakes($locked);
            $this->markMatchCancelled($locked);
            $this->markListingCancelled($locked);

            $this->postSystem->handle(
                $locked,
                __(':name accepted. Match cancelled, stakes refunded.', [
                    'name' => $accepter->name,
                ]),
            );

            return 'cancelled';
        });
    }

    /**
     * Same refund shape as `SettleDrawMatchAction::refundBothStakes`. The
     * cancellation-specific reference strings keep this idempotent
     * independently of any other refund path that might touch the same
     * match (defense in depth — there shouldn't be one, but the Wallet
     * idempotency contract is the safety net).
     */
    private function refundBothStakes(GameMatch $match): void
    {
        $stake = (string) $match->listing->stake_amount;

        Wallet::release(
            user: $match->listing->user,
            amount: $stake,
            listing: $match->listing,
            reference: "cancel-refund-creator:{$match->id}",
            description: 'Mutual cancellation — creator stake refunded.',
        );

        Wallet::release(
            user: $match->taker,
            amount: $stake,
            listing: $match->listing,
            reference: "cancel-refund-taker:{$match->id}",
            description: 'Mutual cancellation — taker stake refunded.',
        );
    }

    private function markMatchCancelled(GameMatch $match): void
    {
        $match->update([
            'status' => MatchStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Listing flips Taken → Cancelled. Creator can create a fresh listing
     * if they want to keep playing — re-opening this listing isn't on the
     * table (UNIQUE constraint on `game_matches.listing_id` means one
     * listing → at most one match ever; reopening would let a second
     * match land on the historical record).
     */
    private function markListingCancelled(GameMatch $match): void
    {
        $match->listing->update([
            'status' => ListingStatus::Cancelled,
        ]);
    }
}
