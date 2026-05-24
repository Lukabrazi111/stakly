<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Settles a match by paying the winner and crediting the platform fee, all
 * inside one `DB::transaction` with a row lock on the match.
 *
 * Money math is BCMath strings throughout (scale 6 to match the wallet
 * ledger). Fee rate is read from `config('stakly.platform_fee_rate')` —
 * a string like `'0.10'` for 10%.
 *
 * Conservation invariant per match (asserted by tests):
 *   `-creator_stake + -taker_stake + (pot - fee) + fee = 0`
 *
 * Idempotent: short-circuits on `Settled` (re-entry safe). Throws
 * `InvalidArgumentException` on `ManualReview` or any unexpected status
 * (Phase 7.3 guard) — admin tools must take a different code path.
 *
 * Loser's original `EscrowHold` stays as the permanent debit — there is
 * no release for them, by design (locked decision in milestones.md M6).
 */
class SettleMatchAction
{
    private const SCALE = 6;

    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(GameMatch $match, User $winner): void
    {
        DB::transaction(function () use ($match, $winner) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status === MatchStatus::Settled) {
                return;
            }

            $this->assertSettleableStatus($locked);

            $locked->load('listing');

            $this->assertWinnerIsParticipant($locked, $winner);

            [$winnerPayout, $fee] = $this->computeAmounts((string) $locked->listing->stake_amount);

            $this->postLedgerEntries($locked, $winner, $winnerPayout, $fee);
            $this->markSettled($locked, $winner);

            $this->postSystem->handle(
                $locked,
                __('Match settled. :name wins $:payout USDT.', [
                    'name' => $winner->name,
                    'payout' => number_format((float) $winnerPayout, 2, '.', ''),
                ]),
            );
        });
    }

    /**
     * Settle runs mid-resolution: Pending (both confirm), Disputed (game-API
     * arbitration), or ManualReview (admin clicked Settle to Creator/Taker
     * in the Filament panel — M12 Phase 2). Cancelled / Settled / Open are
     * rejected — those are terminal or pre-match states.
     */
    private function assertSettleableStatus(GameMatch $match): void
    {
        $allowed = [MatchStatus::Pending, MatchStatus::Disputed, MatchStatus::ManualReview];

        if (! in_array($match->status, $allowed, true)) {
            throw new InvalidArgumentException(
                "Cannot settle match {$match->id}: status is {$match->status->value}, expected Pending, Disputed, or ManualReview."
            );
        }
    }

    /**
     * Defense in depth — the calling Action already picks the right user,
     * but a future caller might not.
     */
    private function assertWinnerIsParticipant(GameMatch $match, User $winner): void
    {
        $creatorId = $match->listing->user_id;
        $takerId = $match->taker_user_id;

        if ($winner->id !== $creatorId && $winner->id !== $takerId) {
            throw new InvalidArgumentException(
                "User {$winner->id} is not a participant of match {$match->id}."
            );
        }
    }

    /**
     * @return array{0: string, 1: string} [winnerPayout, fee] — both BCMath strings.
     */
    private function computeAmounts(string $stake): array
    {
        $pot = bcmul($stake, '2', self::SCALE);
        $feeRate = (string) config('stakly.platform_fee_rate');
        $fee = bcmul($pot, $feeRate, self::SCALE);
        $winnerPayout = bcsub($pot, $fee, self::SCALE);

        return [$winnerPayout, $fee];
    }

    private function postLedgerEntries(GameMatch $match, User $winner, string $winnerPayout, string $fee): void
    {
        Wallet::payout(
            winner: $winner,
            amount: $winnerPayout,
            listing: $match->listing,
            reference: "match-payout:{$match->id}",
            description: 'Match payout to winner.',
        );

        Wallet::fee(
            amount: $fee,
            listing: $match->listing,
            reference: "match-fee:{$match->id}",
            description: 'Platform fee on match settlement.',
        );
    }

    private function markSettled(GameMatch $match, User $winner): void
    {
        $match->update([
            'status' => MatchStatus::Settled,
            'winner_user_id' => $winner->id,
            'settled_at' => now(),
        ]);
    }
}
