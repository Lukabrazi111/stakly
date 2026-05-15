<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Settles a match by paying the winner and crediting the platform fee, all
 * inside one DB transaction with a row lock on the match. Idempotent —
 * short-circuits if the match is already in `Settled` state.
 *
 * Money math is BCMath strings throughout (scale 6 to match the wallet
 * ledger). Fee rate is read from `config('stakly.platform_fee_rate')` —
 * a string like `'0.10'` for 10%.
 *
 * Conservation invariant per match (asserted by tests):
 *   -creator_stake + -taker_stake + (pot - fee) + fee = 0
 *
 * That is: the two players' holds (debits, posted at create / take) plus
 * the winner's payout (credit) plus the platform fee (credit) sum to zero.
 * Money is redistributed, never created or destroyed.
 *
 * Loser's original `EscrowHold` stays as the permanent debit — there is no
 * release for them, by design (locked decision in milestones.md M6).
 */
class MatchSettlement
{
    private const SCALE = 6;

    /**
     * Settle a match by paying $winner. If the match is already Settled,
     * returns immediately without writing — repeat calls are no-ops.
     *
     * Throws InvalidArgumentException if $winner is not a participant.
     */
    public static function settle(GameMatch $match, User $winner): void
    {
        DB::transaction(function () use ($match, $winner) {
            // Re-fetch under lock to serialize concurrent settlement attempts.
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            // Idempotency: if already settled, no-op. The Wallet::payout /
            // Wallet::fee references would also be no-ops on retry, but
            // short-circuiting here saves the row lock + writes.
            if ($locked->status === MatchStatus::Settled) {
                return;
            }

            $locked->load('listing');

            // Defense in depth: validate the winner is a participant. The
            // controller's resolution logic already picks the right user,
            // but a future caller might not.
            $creatorId = $locked->listing->user_id;
            $takerId = $locked->taker_user_id;

            if ($winner->id !== $creatorId && $winner->id !== $takerId) {
                throw new InvalidArgumentException(
                    "User {$winner->id} is not a participant of match {$locked->id}."
                );
            }

            $stake = (string) $locked->listing->stake_amount;
            $pot = bcmul($stake, '2', self::SCALE);
            $feeRate = (string) config('stakly.platform_fee_rate');
            $fee = bcmul($pot, $feeRate, self::SCALE);
            $winnerPayout = bcsub($pot, $fee, self::SCALE);

            Wallet::payout(
                winner: $winner,
                amount: $winnerPayout,
                listing: $locked->listing,
                reference: "match-payout:{$locked->id}",
                description: 'Match payout to winner.',
            );

            Wallet::fee(
                amount: $fee,
                listing: $locked->listing,
                reference: "match-fee:{$locked->id}",
                description: 'Platform fee on match settlement.',
            );

            $locked->update([
                'status' => MatchStatus::Settled,
                'winner_user_id' => $winner->id,
                'settled_at' => now(),
            ]);
        });
    }
}
