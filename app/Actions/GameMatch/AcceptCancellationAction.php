<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use App\Notifications\CancellationAcceptedNotification;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 of the mutual cancellation flow: the opponent accepts the pending request.
 * Match flips Pending → Cancelled, both stakes refunded, listing flips Taken → Cancelled.
 *
 * Conservation per cancelled match: `-A_stake + -B_stake + +A_release + +B_release = 0`.
 *
 * Returns: `'cancelled'`, `'already_cancelled'` (idempotent re-call), `'race_lost'`
 * (no longer Pending), `'request_missing'` (no open request), or `'self_accept_forbidden'`
 * (requester tried to self-accept — policy gates upstream; this guards bypass-policy callers).
 */
class AcceptCancellationAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(User $accepter, GameMatch $match): string
    {
        $result = DB::transaction(function () use ($match, $accepter) {
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

        if ($result === 'cancelled') {
            $fresh = $match->fresh(['listing.user', 'taker']);
            $requester = User::find($fresh->cancellation_requested_by);

            if ($requester !== null) {
                $requester->notify(new CancellationAcceptedNotification($fresh, $accepter));
            }
        }

        return $result;
    }

    /**
     * Cancellation-specific reference strings keep refunds idempotent independently of any
     * other refund path that might touch the same match (Wallet idempotency is the safety net).
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
     * Flip Taken → Cancelled (not back to Open) — UNIQUE on `game_matches.listing_id`
     * means one listing → at most one match ever; reopening would let a second match
     * land on the historical record.
     */
    private function markListingCancelled(GameMatch $match): void
    {
        $match->listing->update([
            'status' => ListingStatus::Cancelled,
        ]);
    }
}
