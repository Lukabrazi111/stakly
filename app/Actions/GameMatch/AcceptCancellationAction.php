<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Notifications\CancellationAcceptedNotification;
use App\Services\MatchParticipants;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Step 2 of the mutual cancellation flow: the opponent accepts the pending request.
 * Match flips Pending → Cancelled, every escrowed stake is refunded, listing flips
 * Taken → Cancelled.
 *
 * 1v1 — refunds creator + taker.
 * Team play (M34 P6) — fan-out across every live `lobby_participants` row that
 * holds escrow (`kicked_at IS NULL AND stake_held_at IS NOT NULL`). Wallet's
 * row-lock + negative-balance throw guards conservation; tests assert
 * end-to-end balance restoration.
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

            $this->refundEveryStake($locked);
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

            // Notify every participant except the accepter (they did it).
            // For 1v1 that's the original requester only; for team play
            // it's the requester + their 4 team-mates + the accepter's 4
            // team-mates — everyone needs the refund-done signal.
            $recipients = MatchParticipants::allExcept($fresh, $accepter);

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new CancellationAcceptedNotification($fresh, $accepter));
            }
        }

        return $result;
    }

    private function refundEveryStake(GameMatch $match): void
    {
        if ($match->listing->isTeamPlay()) {
            $this->refundTeamStakes($match);

            return;
        }

        $this->refund1v1Stakes($match);
    }

    /**
     * Cancellation-specific reference strings keep refunds idempotent independently of any
     * other refund path that might touch the same match (Wallet idempotency is the safety net).
     */
    private function refund1v1Stakes(GameMatch $match): void
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

    /**
     * Team-match fan-out — refunds every live lobby participant that has
     * escrow held. Kicked rows are skipped (their stake was released at
     * kick time per `KickParticipantAction`); soft-joined-but-not-Ready
     * rows are skipped (no escrow to release).
     *
     * Per-user idempotency ref `cancel-refund:{match_id}:{user_id}`
     * — survives partial-failure retries without double-refunding.
     */
    private function refundTeamStakes(GameMatch $match): void
    {
        $stake = (string) $match->listing->stake_amount;

        $participants = LobbyParticipant::query()
            ->where('listing_id', $match->listing_id)
            ->live()
            ->whereNotNull('stake_held_at')
            ->with('user')
            ->get();

        foreach ($participants as $participant) {
            Wallet::release(
                user: $participant->user,
                amount: $stake,
                listing: $match->listing,
                reference: "cancel-refund:{$match->id}:{$participant->user_id}",
                description: 'Mutual cancellation — team stake refunded.',
            );
        }
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
