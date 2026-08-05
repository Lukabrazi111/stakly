<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use App\Services\PayoutClearance;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * M34 Phase 4 — team-play sibling to `SettleMatchAction`. Settles a 5v5 (or
 * any team_size > 1) match by fanning out one `Wallet::payout()` per winner
 * and one `Wallet::fee()` for the platform cut — all inside one
 * `DB::transaction` with a row lock on the match.
 *
 * Money math (BCMath strings, scale 6):
 *   pot       = stake × team_size × 2
 *   fee       = pot × fee_rate
 *   winnings  = pot − fee
 *   perPlayer = floor(winnings / team_size)         (truncates at scale 6)
 *   remainder = winnings − (perPlayer × team_size)  (≤ team_size − 1 micro-USDT)
 *
 * The slot-0 winner gets `perPlayer + remainder`; everyone else gets
 * `perPlayer`. This preserves the ledger-conservation invariant exactly:
 *   sum(payouts) + fee = pot, always.
 *
 * Idempotent: per-player payout reference is `"match-payout:{id}:player-{uid}"`
 * — re-entry returns the existing wallet rows untouched.
 *
 * Losers' original `EscrowHold` rows stay as permanent debits, same as 1v1.
 */
class SettleTeamMatchAction
{
    private const SCALE = 6;

    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    /**
     * @param  list<User>  $winners  Ordered by slot_index ascending; slot 0
     *                               receives the division remainder.
     */
    public function handle(GameMatch $match, array $winners): void
    {
        DB::transaction(function () use ($match, $winners) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status === MatchStatus::Settled) {
                return;
            }

            $this->assertSettleableStatus($locked);
            $this->assertWinnerCount($locked, $winners);

            $locked->load(['listing.lobbyParticipants']);

            $this->assertWinnersAreParticipants($locked, $winners);

            $teamSize = $locked->listing->team_size;
            $stake = (string) $locked->listing->stake_amount;

            [$perPlayer, $remainder, $fee] = $this->computeAmounts($stake, $teamSize);

            $this->postLedgerEntries($locked, $winners, $perPlayer, $remainder, $fee);
            $this->markSettled($locked, $winners[0]);

            $totalPayout = bcadd(
                bcmul($perPlayer, (string) $teamSize, self::SCALE),
                $remainder,
                self::SCALE,
            );

            $this->postSystem->handle(
                $locked,
                __('Team match settled. :n winners share $:total USDT (~$:each each).', [
                    'n' => count($winners),
                    'total' => number_format((float) $totalPayout, 2, '.', ''),
                    'each' => number_format((float) $perPlayer, 2, '.', ''),
                ]),
            );

            // Defense-in-depth conservation assertion. Sum of credit legs
            // we just posted must equal the pot exactly. Catches any
            // future math regression at settle time, before money escapes.
            $expectedSum = bcadd($totalPayout, $fee, self::SCALE);
            $pot = bcmul($stake, (string) ($teamSize * 2), self::SCALE);
            if (bccomp($expectedSum, $pot, self::SCALE) !== 0) {
                throw new InvalidArgumentException(
                    "Settlement ledger mismatch on match {$locked->id}: payouts+fee={$expectedSum} != pot={$pot}"
                );
            }
        });
    }

    /**
     * Settle runs mid-resolution: Pending (auto-fetch), Disputed (arbitration),
     * or ManualReview (admin). Cancelled / Settled / Open are rejected.
     */
    private function assertSettleableStatus(GameMatch $match): void
    {
        $allowed = [MatchStatus::Pending, MatchStatus::Disputed, MatchStatus::ManualReview];

        if (! in_array($match->status, $allowed, true)) {
            throw new InvalidArgumentException(
                "Cannot settle team match {$match->id}: status is {$match->status->value}, expected Pending, Disputed, or ManualReview."
            );
        }
    }

    /**
     * @param  list<User>  $winners
     */
    private function assertWinnerCount(GameMatch $match, array $winners): void
    {
        $expected = $match->listing->team_size;
        $actual = count($winners);

        if ($actual !== $expected) {
            throw new InvalidArgumentException(
                "Team match {$match->id} expects {$expected} winners, got {$actual}."
            );
        }
    }

    /**
     * Defensive: every winner user_id must be a live participant of the
     * listing's lobby. Mirrors `SettleMatchAction::assertWinnerIsParticipant`
     * for the team case. Caller (`SettleFromCardAction`) already runs the
     * "all winners on the winning team's side" check; this is the deeper
     * "is this user even in this lobby?" guard.
     *
     * @param  list<User>  $winners
     */
    private function assertWinnersAreParticipants(GameMatch $match, array $winners): void
    {
        $liveUserIds = $match->listing->lobbyParticipants
            ->whereNull('kicked_at')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($winners as $winner) {
            if (! in_array($winner->id, $liveUserIds, true)) {
                throw new InvalidArgumentException(
                    "User {$winner->id} is not a live participant of team match {$match->id}."
                );
            }
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     *                                                [perPlayer, remainder, fee] — all BCMath strings at scale 6.
     */
    private function computeAmounts(string $stake, int $teamSize): array
    {
        $totalPlayers = (string) ($teamSize * 2);
        $pot = bcmul($stake, $totalPlayers, self::SCALE);
        $feeRate = (string) config('stakly.platform_fee_rate');
        $fee = bcmul($pot, $feeRate, self::SCALE);
        $winnings = bcsub($pot, $fee, self::SCALE);
        $perPlayer = bcdiv($winnings, (string) $teamSize, self::SCALE);
        $remainder = bcsub(
            $winnings,
            bcmul($perPlayer, (string) $teamSize, self::SCALE),
            self::SCALE,
        );

        return [$perPlayer, $remainder, $fee];
    }

    /**
     * @param  list<User>  $winners
     */
    private function postLedgerEntries(
        GameMatch $match,
        array $winners,
        string $perPlayer,
        string $remainder,
        string $fee,
    ): void {
        foreach ($winners as $index => $winner) {
            // Slot-0 winner gets the truncation remainder so the per-match
            // conservation invariant holds at micro-USDT precision.
            $amount = $index === 0
                ? bcadd($perPlayer, $remainder, self::SCALE)
                : $perPlayer;

            Wallet::payout(
                winner: $winner,
                amount: $amount,
                listing: $match->listing,
                reference: "match-payout:{$match->id}:player-{$winner->id}",
                description: 'Match payout to team winner.',
                // Resolved per player: risk signals are per-account, so two
                // team-mates on the same match can clear on different clocks.
                clearsAt: PayoutClearance::for($winner, $amount),
            );
        }

        Wallet::fee(
            amount: $fee,
            listing: $match->listing,
            reference: "match-fee:{$match->id}",
            description: 'Platform fee on team-match settlement.',
        );
    }

    /**
     * Stamps `winner_user_id` with the slot-0 winner so existing read paths
     * (e.g. match history listings expecting a single id) keep working. The
     * full winner set lives in the ledger via the per-player `match-payout`
     * reference IDs.
     */
    private function markSettled(GameMatch $match, User $slot0Winner): void
    {
        $match->update([
            'status' => MatchStatus::Settled,
            'winner_user_id' => $slot0Winner->id,
            'settled_at' => now(),
        ]);
    }
}
