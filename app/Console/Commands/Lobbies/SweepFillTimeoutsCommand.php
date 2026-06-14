<?php

namespace App\Console\Commands\Lobbies;

use App\Actions\Lobby\LobbyFillTimeoutAction;
use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cancels team-play listings that have been on the board for 24h without
 * ever locking (lobby_state stuck in `recruiting` or `ready_checking`).
 * Refunds any Ready'd participants via `LobbyFillTimeoutAction`. Runs
 * hourly — there's no urgency to within-the-minute precision on a 24h
 * timer, and hourly cadence is well under the existing per-minute floor
 * for `listings:expire`.
 */
class SweepFillTimeoutsCommand extends Command
{
    protected $signature = 'lobbies:sweep-fill-timeouts';

    protected $description = 'Cancel team-play listings stuck without locking past their 24h fill deadline.';

    private const CHUNK_SIZE = 100;

    public function handle(LobbyFillTimeoutAction $action): int
    {
        $deadline = now()->subHours(24);

        $cancelled = 0;
        $skipped = 0;
        $failed = 0;

        Listing::query()
            ->where('team_size', '>', 1)
            ->where('status', ListingStatus::Open)
            ->whereIn('lobby_state', ['recruiting', 'ready_checking'])
            ->where('created_at', '<=', $deadline)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($listings) use ($action, &$cancelled, &$skipped, &$failed) {
                foreach ($listings as $listing) {
                    try {
                        $result = $action->handle($listing);

                        match ($result) {
                            'cancelled' => $cancelled++,
                            default => $skipped++,
                        };
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->info("Cancelled {$cancelled}. Skipped {$skipped}. Failed {$failed}.");

        return self::SUCCESS;
    }
}
