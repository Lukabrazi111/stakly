<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Settles a match as a draw — refunds both players' stakes via
 * `Wallet::release`, no platform fee, no winner. Called from:
 *   - `SettleFromCardAction` (M16) when an auto-fetched game card has
 *     `winner_color = null`.
 *   - `ResolveDisputeAction` when the game-API returns `GameApiConfidence::Drawn`.
 *
 * Conservation per match: `-A_stake + -B_stake + +A_release + +B_release = 0`.
 *
 * Idempotent: short-circuits on `Settled`. Same `ManualReview` rejection
 * guard as `SettleMatchAction` — admin tools own that state.
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

            $this->refundBothStakes($locked);
            $this->markSettledAsDraw($locked);

            $this->postSystem->handle(
                $locked,
                __('Match ended as a draw. Stakes refunded to both players.'),
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

    private function refundBothStakes(GameMatch $match): void
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

    private function markSettledAsDraw(GameMatch $match): void
    {
        $match->update([
            'status' => MatchStatus::Settled,
            // winner_user_id stays null — the marker for "draw".
            'settled_at' => now(),
        ]);
    }
}
