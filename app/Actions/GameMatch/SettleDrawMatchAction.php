<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Settles a match as a draw — refunds every escrowed stake via
 * `Wallet::release`, no platform fee, no winner. Called from:
 *   - `SettleFromCardAction` (M16) when an auto-fetched game card has
 *     `winner_color = null`.
 *   - `ResolveDisputeAction` when the game-API returns `GameApiConfidence::Drawn`.
 *
 * 1v1 — refunds creator + taker (legacy refs `match-draw-creator/taker`).
 * Team play (M34 P6) — fan-out across every live `lobby_participants` row
 * with `stake_held_at IS NOT NULL` (per-user ref `match-draw:{id}:{user_id}`).
 *
 * Idempotent: short-circuits on `Settled`; per-user references are unique
 * so re-entry returns existing rows untouched. Same `ManualReview` rejection
 * guard as `SettleMatchAction`.
 */
class SettleDrawMatchAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(GameMatch $match): void
    {
        DB::transaction(function () use ($match) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status === MatchStatus::Settled) {
                return;
            }

            $this->assertSettleableStatus($locked);

            $locked->load(['listing.user', 'taker']);

            $this->refundEveryStake($locked);
            $this->markSettledAsDraw($locked);

            $this->postSystem->handle(
                $locked,
                __('Match ended as a draw. Stakes refunded to every player.'),
            );
        });
    }

    private function assertSettleableStatus(GameMatch $match): void
    {
        $allowed = [MatchStatus::Pending, MatchStatus::Disputed, MatchStatus::ManualReview];

        if (! in_array($match->status, $allowed, true)) {
            throw new InvalidArgumentException(
                "Cannot settle match {$match->id} as draw: status is {$match->status->value}, expected Pending, Disputed, or ManualReview."
            );
        }
    }

    private function refundEveryStake(GameMatch $match): void
    {
        if ($match->listing->isTeamPlay()) {
            $this->refundTeamStakes($match);

            return;
        }

        $this->refund1v1Stakes($match);
    }

    private function refund1v1Stakes(GameMatch $match): void
    {
        $stake = (string) $match->listing->stake_amount;

        Wallet::release(
            user: $match->listing->user,
            amount: $stake,
            listing: $match->listing,
            reference: "match-draw-creator:{$match->id}",
            description: 'Draw — creator stake refunded.',
        );

        Wallet::release(
            user: $match->taker,
            amount: $stake,
            listing: $match->listing,
            reference: "match-draw-taker:{$match->id}",
            description: 'Draw — taker stake refunded.',
        );
    }

    /**
     * Per-user idempotency ref `match-draw:{match_id}:{user_id}` — survives
     * partial-failure retries without double-refunding. Mirrors the
     * `AcceptCancellationAction` team-refund pattern.
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
                reference: "match-draw:{$match->id}:{$participant->user_id}",
                description: 'Draw — team stake refunded.',
            );
        }
    }

    private function markSettledAsDraw(GameMatch $match): void
    {
        $match->update([
            'status' => MatchStatus::Settled,
            // winner_user_id stays null — the marker for "draw".
            'settled_at' => now(),
        ]);
    }
}
