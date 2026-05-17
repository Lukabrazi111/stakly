<?php

namespace App\Services;

use App\Enums\GameApiConfidence;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use App\Services\GameApi\GameApi;
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

    /**
     * Settle a match as a draw — refund both players' stakes via `Wallet::release`,
     * no platform fee, no winner. Used when both players agree it was a draw
     * (`GameMatchController::resolveBothConfirmed`) or when the game-API
     * returns `GameApiConfidence::Drawn` (via `resolveDispute` below).
     *
     * Conservation per match: `-A_stake + -B_stake + +A_release + +B_release = 0`.
     *
     * Idempotent: short-circuits if the match is already `Settled`. The two
     * `Wallet::release` calls are also idempotent via their `match-draw-*`
     * references — repeat invocations with the same match id are safe.
     */
    public static function settleDraw(GameMatch $match): void
    {
        DB::transaction(function () use ($match) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status === MatchStatus::Settled) {
                return;
            }

            $locked->load(['listing.user', 'taker']);

            $stake = (string) $locked->listing->stake_amount;

            Wallet::release(
                user: $locked->listing->user,
                amount: $stake,
                listing: $locked->listing,
                reference: "match-draw-creator:{$locked->id}",
                description: 'Draw — creator stake refunded.',
            );

            Wallet::release(
                user: $locked->taker,
                amount: $stake,
                listing: $locked->listing,
                reference: "match-draw-taker:{$locked->id}",
                description: 'Draw — taker stake refunded.',
            );

            $locked->update([
                'status' => MatchStatus::Settled,
                // winner_user_id stays null — that's the marker for "draw".
                'settled_at' => now(),
            ]);
        });
    }

    /**
     * Resolve a Disputed match via the configured `GameApi` driver.
     *
     * Confidence === Confirmed → settle in favour of the API winner (delegates
     * to `settle()` so payout / fee logic isn't duplicated). The API winner
     * is authoritative — it overrides whatever players self-reported.
     *
     * Confidence === Unknown → flip status to ManualReview and leave money
     * locked. v1 has no admin tooling; ManualReview matches sit until an
     * admin tools milestone lands. The placeholder is tracked in milestones.md.
     *
     * Idempotent: terminal states (Settled, ManualReview) short-circuit. The
     * row lock + status guard serialise concurrent dispute-resolution
     * attempts (e.g. both players hit "Open dispute" simultaneously, or the
     * timeout job fires while a manual dispute is already in flight).
     *
     * Audit: the driver's raw response is persisted to `game_matches.api_response`
     * along with `api_resolved_at`, written before settlement so we have a
     * record even if settlement throws.
     *
     * Throws InvalidArgumentException if called on a non-Disputed match (sanity
     * check — callers must transition to Disputed before invoking) or if the
     * driver returns a winner who isn't a participant (defense in depth).
     */
    public static function resolveDispute(GameMatch $match): void
    {
        DB::transaction(function () use ($match) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            // Idempotency: terminal states are no-ops. Covers double-clicks,
            // a manual dispute racing the timeout job, etc.
            if ($locked->status === MatchStatus::Settled
                || $locked->status === MatchStatus::ManualReview) {
                return;
            }

            // Sanity guard. Callers must flip status to Disputed before
            // invoking this — keeping that contract explicit prevents a future
            // caller from accidentally short-circuiting the player-confirm
            // window by jumping straight to API resolution from Pending.
            if ($locked->status !== MatchStatus::Disputed) {
                throw new InvalidArgumentException(
                    "resolveDispute called on match {$locked->id} with status {$locked->status->value}; expected Disputed."
                );
            }

            $locked->load(['listing.user', 'taker']);

            $result = app(GameApi::class)->getMatchResult($locked);

            // Persist audit trail before branching so it's saved even if
            // settlement throws (rolls back inside the transaction, but the
            // shape of failures is observable in logs / re-attempts).
            $locked->update([
                'api_response' => $result->raw_response,
                'api_resolved_at' => now(),
            ]);

            if ($result->confidence === GameApiConfidence::Unknown) {
                $locked->update(['status' => MatchStatus::ManualReview]);

                return;
            }

            if ($result->confidence === GameApiConfidence::Drawn) {
                // API ruled it a draw — refund both stakes, no winner, no
                // platform fee. `settleDraw` runs inside its own DB::transaction
                // which Laravel composes via savepoint; the outer audit-trail
                // update commits atomically with the refund.
                self::settleDraw($locked);

                return;
            }

            $winnerId = $result->winner_user_id;
            $creatorId = $locked->listing->user_id;
            $takerId = $locked->taker_user_id;

            // Defense in depth: the driver shouldn't return a non-participant,
            // but if it does we'd rather throw than pay a stranger.
            if ($winnerId !== $creatorId && $winnerId !== $takerId) {
                throw new InvalidArgumentException(
                    "GameApi returned winner_user_id {$winnerId} which is not a participant of match {$locked->id}."
                );
            }

            $winner = $winnerId === $creatorId
                ? $locked->listing->user
                : $locked->taker;

            // Delegate to settle(). Nested transaction is handled by Laravel
            // savepoints. settle() will see status=Disputed (not Settled),
            // post the payout + fee, and flip to Settled — atomically with
            // our outer audit-trail update.
            self::settle($locked, $winner);
        });
    }
}
