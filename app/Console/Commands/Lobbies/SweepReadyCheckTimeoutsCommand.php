<?php

namespace App\Console\Commands\Lobbies;

use App\Actions\Lobby\LobbyReadyCheckTimeoutAction;
use App\Models\Listing;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fires `LobbyReadyCheckTimeoutAction` on every listing whose
 * `lobby_ready_check_deadline` has passed. Lobby goes back to recruiting
 * (non-Ready vacated), or cancels entirely if the creator was the one
 * not-Ready. Runs every minute — the 5-min deadline needs sub-5-min
 * cadence for the timer to feel responsive.
 */
class SweepReadyCheckTimeoutsCommand extends Command
{
    protected $signature = 'lobbies:sweep-ready-check-timeouts';

    protected $description = 'Vacate non-Ready participants from lobbies whose 5-min ready-check deadline has passed.';

    private const CHUNK_SIZE = 100;

    public function handle(LobbyReadyCheckTimeoutAction $action): int
    {
        $reverted = 0;
        $cancelled = 0;
        $skipped = 0;
        $failed = 0;

        Listing::query()
            ->where('team_size', '>', 1)
            ->where('lobby_state', 'ready_checking')
            ->where('lobby_ready_check_deadline', '<=', now())
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($listings) use ($action, &$reverted, &$cancelled, &$skipped, &$failed) {
                foreach ($listings as $listing) {
                    try {
                        $result = $action->handle($listing);

                        match ($result) {
                            'reverted' => $reverted++,
                            'cancelled' => $cancelled++,
                            default => $skipped++,
                        };
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->info("Reverted {$reverted}. Cancelled {$cancelled}. Skipped {$skipped}. Failed {$failed}.");

        return self::SUCCESS;
    }
}
