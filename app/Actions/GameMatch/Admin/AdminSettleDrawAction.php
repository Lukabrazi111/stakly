<?php

namespace App\Actions\GameMatch\Admin;

use App\Actions\GameMatch\SettleDrawMatchAction;
use App\Enums\MatchAdminResolutionAction;
use App\Models\GameMatch;
use App\Models\MatchAdminResolution;
use App\Models\User;
use App\Notifications\MatchSettledNotification;
use App\Services\MatchParticipants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * M12 Phase 2 / M34 P6 — admin-side wrapper for `SettleDrawMatchAction`.
 * Writes a `match_admin_resolutions` audit row inside the same
 * transaction as the underlying Wallet refunds. `winner_user_id` is
 * always null on draw rows.
 *
 * Notifications fan out across every live participant — for 1v1 that's
 * creator + taker (legacy behavior); for team play that's the full
 * roster, all 10 (or 4 for Wingman) need to know the match was drawn
 * and their stake refunded.
 *
 * Called from the Filament `GameMatchResource` "Draw — refund all" button.
 */
class AdminSettleDrawAction
{
    public function __construct(
        private readonly SettleDrawMatchAction $settleDraw,
    ) {}

    public function handle(
        GameMatch $match,
        User $admin,
        string $reason,
    ): MatchAdminResolution {
        $resolution = DB::transaction(function () use ($match, $admin, $reason) {
            $this->settleDraw->handle($match);

            return MatchAdminResolution::create([
                'match_id' => $match->id,
                'admin_user_id' => $admin->id,
                'action' => MatchAdminResolutionAction::SettleDraw,
                'winner_user_id' => null,
                'reason' => $reason,
            ]);
        });

        $fresh = $match->fresh(['listing.user', 'taker', 'listing.lobbyParticipants.user']);
        $recipients = MatchParticipants::all($fresh);

        if ($recipients->isNotEmpty()) {
            Notification::send(
                $recipients,
                new MatchSettledNotification($fresh, 'draw'),
            );
        }

        return $resolution;
    }
}
