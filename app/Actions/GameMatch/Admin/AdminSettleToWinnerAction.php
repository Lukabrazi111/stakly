<?php

namespace App\Actions\GameMatch\Admin;

use App\Actions\GameMatch\SettleMatchAction;
use App\Enums\MatchAdminResolutionAction;
use App\Models\GameMatch;
use App\Models\MatchAdminResolution;
use App\Models\User;
use App\Notifications\MatchSettledNotification;
use Illuminate\Support\Facades\DB;

/**
 * M12 Phase 2 — admin-side wrapper for `SettleMatchAction`. Writes a
 * `match_admin_resolutions` audit row in the same transaction as the
 * underlying Wallet payout, so the audit trail can never drift from the
 * money movement (transaction rolls back together on failure).
 *
 * Called from the Filament `GameMatchResource` "Settle to Creator" and
 * "Settle to Taker" action buttons. The Filament layer picks which user
 * is the winner; this action stays winner-agnostic.
 */
class AdminSettleToWinnerAction
{
    public function __construct(
        private readonly SettleMatchAction $settle,
    ) {}

    public function handle(
        GameMatch $match,
        User $winner,
        User $admin,
        MatchAdminResolutionAction $action,
        string $reason,
    ): MatchAdminResolution {
        $resolution = DB::transaction(function () use ($match, $winner, $admin, $action, $reason) {
            $this->settle->handle($match, $winner);

            return MatchAdminResolution::create([
                'match_id' => $match->id,
                'admin_user_id' => $admin->id,
                'action' => $action,
                'winner_user_id' => $winner->id,
                'reason' => $reason,
            ]);
        });

        $this->notifyPlayers($match->fresh(['listing.user', 'taker']), $winner);

        return $resolution;
    }

    private function notifyPlayers(GameMatch $match, User $winner): void
    {
        $payout = SettleMatchAction::computeWinnerPayout((string) $match->listing->stake_amount);
        $loser = $winner->id === $match->listing->user_id ? $match->taker : $match->listing->user;

        $winner->notify(new MatchSettledNotification($match, 'won', $payout));
        $loser->notify(new MatchSettledNotification($match, 'lost', '0'));
    }
}
