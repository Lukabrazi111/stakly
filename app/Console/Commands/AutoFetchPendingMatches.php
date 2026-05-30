<?php

namespace App\Console\Commands;

use App\Actions\GameMatch\DispatchAutoFetchAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use Illuminate\Console\Command;
use Throwable;

/**
 * Periodic auto-fetch backstop. Scans Pending matches with `created_at` between
 * `now - 4h` (auto-fetch past timeout would race the timeout-resolver) and
 * `now - 10min` (newer matches are covered by the page-visit trigger).
 */
class AutoFetchPendingMatches extends Command
{
    protected $signature = 'stakly:auto-fetch-pending';

    protected $description = 'Dispatch auto-fetch jobs for Pending matches in the [10min, 4h] window.';

    private const CHUNK_SIZE = 100;

    private const MIN_AGE_MINUTES = 10;

    public function handle(DispatchAutoFetchAction $dispatchAutoFetch): int
    {
        $now = now();
        $youngest = $now->copy()->subMinutes(self::MIN_AGE_MINUTES);
        $oldest = $now->copy()->subHours((int) config('stakly.match_confirmation_timeout_hours'));

        $dispatched = 0;
        $failed = 0;

        GameMatch::query()
            ->where('status', MatchStatus::Pending)
            ->whereBetween('created_at', [$oldest, $youngest])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($matches) use ($dispatchAutoFetch, &$dispatched, &$failed) {
                foreach ($matches as $match) {
                    try {
                        $dispatchAutoFetch->handle($match);
                        $dispatched++;
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->info("Dispatched {$dispatched}. Failed {$failed}.");

        return self::SUCCESS;
    }
}
