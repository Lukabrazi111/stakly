<?php

namespace App\Actions\GameMatch\Admin;

use App\Actions\GameMatch\SettleMatchAction;
use App\Actions\GameMatch\SettleTeamMatchAction;
use App\Enums\MatchAdminResolutionAction;
use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\MatchAdminResolution;
use App\Models\User;
use App\Notifications\MatchSettledNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * M12 Phase 2 / M34 P6 — admin-side wrapper for match settlement. Writes a
 * `match_admin_resolutions` audit row in the same transaction as the
 * underlying Wallet payout, so the audit trail can never drift from the
 * money movement (transaction rolls back together on failure).
 *
 * 1v1 — calls `SettleMatchAction` with the single winner; notifies the
 * binary pair (creator + taker).
 * Team play — `$winner` indicates the winning *side*. The action derives
 * the full winning roster from `lobby_participants` (live + on the
 * winner's side, ordered by slot_index) and calls `SettleTeamMatchAction`
 * with the full list. Notifications fan out across all live participants
 * (winners + losers), mirroring the auto-fetch path's `notifyTeamWinLoss`.
 *
 * Called from the Filament `GameMatchResource` settle action buttons.
 */
class AdminSettleToWinnerAction
{
    public function __construct(
        private readonly SettleMatchAction $settle,
        private readonly SettleTeamMatchAction $settleTeam,
    ) {}

    public function handle(
        GameMatch $match,
        User $winner,
        User $admin,
        MatchAdminResolutionAction $action,
        string $reason,
    ): MatchAdminResolution {
        $resolution = DB::transaction(function () use ($match, $winner, $admin, $action, $reason) {
            $match->loadMissing('listing');

            if ($match->listing->isTeamPlay()) {
                $winningRoster = $this->resolveWinningRoster($match, $winner);
                $this->settleTeam->handle($match, $winningRoster);
            } else {
                $this->settle->handle($match, $winner);
            }

            return MatchAdminResolution::create([
                'match_id' => $match->id,
                'admin_user_id' => $admin->id,
                'action' => $action,
                'winner_user_id' => $winner->id,
                'reason' => $reason,
            ]);
        });

        $fresh = $match->fresh(['listing.user', 'taker', 'listing.lobbyParticipants.user']);
        $this->notifyPlayers($fresh, $winner);

        return $resolution;
    }

    /**
     * For a team match, resolve the full winning roster from the chosen
     * winner's side. Returns `User` models ordered by `slot_index` so the
     * slot-0 winner gets the BCMath truncation remainder per
     * `SettleTeamMatchAction`'s contract.
     *
     * @return list<User>
     */
    private function resolveWinningRoster(GameMatch $match, User $winner): array
    {
        $winnerSide = LobbyParticipant::query()
            ->where('listing_id', $match->listing_id)
            ->where('user_id', $winner->id)
            ->live()
            ->value('side');

        if ($winnerSide === null) {
            throw new InvalidArgumentException(
                "User {$winner->id} is not a live participant of team match {$match->id} — cannot derive winning roster."
            );
        }

        return LobbyParticipant::query()
            ->where('listing_id', $match->listing_id)
            ->where('side', $winnerSide)
            ->live()
            ->with('user')
            ->orderBy('slot_index')
            ->get()
            ->pluck('user')
            ->values()
            ->all();
    }

    private function notifyPlayers(GameMatch $match, User $winner): void
    {
        if ($match->listing->isTeamPlay()) {
            $this->notifyTeamPlayers($match, $winner);

            return;
        }

        $payout = SettleMatchAction::computeWinnerPayout((string) $match->listing->stake_amount);
        $loser = $winner->id === $match->listing->user_id ? $match->taker : $match->listing->user;

        $winner->notify(new MatchSettledNotification($match, 'won', $payout));
        $loser->notify(new MatchSettledNotification($match, 'lost', '0'));
    }

    /**
     * Team fan-out: every live participant gets a `won` or `lost` variant
     * keyed on their side vs the winner's side. The per-player payout
     * shown in the notification matches `SettleTeamMatchAction`'s split
     * (`(pot − fee) / team_size`, slot 0 actually gets the remainder but
     * that's a micro-USDT difference not worth surfacing in copy).
     */
    private function notifyTeamPlayers(GameMatch $match, User $winner): void
    {
        $winnerSide = LobbyParticipant::query()
            ->where('listing_id', $match->listing_id)
            ->where('user_id', $winner->id)
            ->live()
            ->value('side');

        if ($winnerSide === null) {
            return;
        }

        $perPlayerPayout = $this->computeTeamPerPlayerPayout($match);

        $participants = $match->listing->lobbyParticipants
            ->whereNull('kicked_at');

        $winners = $participants->where('side', $winnerSide)->pluck('user');
        $losers = $participants->where('side', '!=', $winnerSide)->pluck('user');

        if ($winners->isNotEmpty()) {
            Notification::send(
                $winners,
                new MatchSettledNotification($match, 'won', $perPlayerPayout),
            );
        }

        if ($losers->isNotEmpty()) {
            Notification::send(
                $losers,
                new MatchSettledNotification($match, 'lost', '0'),
            );
        }
    }

    private function computeTeamPerPlayerPayout(GameMatch $match): string
    {
        $stake = (string) $match->listing->stake_amount;
        $teamSize = $match->listing->team_size;
        $totalPlayers = (string) ($teamSize * 2);
        $pot = bcmul($stake, $totalPlayers, 6);
        $feeRate = (string) config('stakly.platform_fee_rate');
        $fee = bcmul($pot, $feeRate, 6);
        $winnings = bcsub($pot, $fee, 6);

        return bcdiv($winnings, (string) $teamSize, 6);
    }
}
