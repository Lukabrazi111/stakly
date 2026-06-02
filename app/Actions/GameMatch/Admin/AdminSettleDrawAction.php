<?php

namespace App\Actions\GameMatch\Admin;

use App\Actions\GameMatch\SettleDrawMatchAction;
use App\Enums\MatchAdminResolutionAction;
use App\Models\GameMatch;
use App\Models\MatchAdminResolution;
use App\Models\User;
use App\Notifications\MatchSettledNotification;
use Illuminate\Support\Facades\DB;

/**
 * M12 Phase 2 — admin-side wrapper for `SettleDrawMatchAction`. Writes a
 * `match_admin_resolutions` audit row inside the same transaction as the
 * underlying Wallet refunds. `winner_user_id` is always null on draw rows.
 *
 * Called from the Filament `GameMatchResource` "Draw — refund both" button.
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

        $fresh = $match->fresh(['listing.user', 'taker']);
        $notification = new MatchSettledNotification($fresh, 'draw');
        $fresh->listing->user->notify($notification);
        $fresh->taker->notify($notification);

        return $resolution;
    }
}
