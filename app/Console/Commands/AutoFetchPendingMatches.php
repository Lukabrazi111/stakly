<?php

namespace App\Console\Commands;

use App\Actions\GameMatch\DispatchAutoFetchAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use Illuminate\Console\Command;
use Throwable;

/**
 * Periodic auto-fetch backstop. Scans Pending matches with `created_at` in a
 * configurable age window and dispatches the per-platform auto-fetch job.
 *
 * Two schedule tiers use this command (routes/console.php):
 *   - Backstop (default): age [10min, 4h] @everyFiveMinutes — the original
 *     M16 catch-all for matches the page-visit / chat-send triggers missed.
 *   - Young (M46 P3): age [1min, 10min] @everyMinute — closes the seam between
 *     a job's own retry chain exhausting and the backstop's 10-min floor, so
 *     chess.com (no real-time stream) settles in ~1-2 min instead of ~10-15.
 *
 * The oldest bound never exceeds the M16 confirmation timeout — auto-fetching
 * past it would race `matches:resolve-timeouts`. Idempotency + provider-load
 * guards live in the jobs (`ShouldBeUnique` + `RateLimited` + circuit breaker),
 * so the finer young cadence can't double-settle or hammer a provider.
 */
class AutoFetchPendingMatches extends Command
{
    protected $signature = 'stakly:auto-fetch-pending
        {--min-age-minutes= : Youngest match age to include (default: 10)}
        {--max-age-minutes= : Oldest match age to include (default: confirmation-timeout hours × 60)}';

    protected $description = 'Dispatch auto-fetch jobs for Pending matches in the given age window.';

    private const CHUNK_SIZE = 100;

    private const MIN_AGE_MINUTES = 10;

    public function handle(DispatchAutoFetchAction $dispatchAutoFetch): int
    {
        $minAgeMinutes = (int) ($this->option('min-age-minutes') ?? self::MIN_AGE_MINUTES);
        $maxAgeMinutes = (int) ($this->option('max-age-minutes')
            ?? (int) config('stakly.match_confirmation_timeout_hours') * 60);

        $now = now();
        $youngest = $now->copy()->subMinutes($minAgeMinutes);
        $oldest = $now->copy()->subMinutes($maxAgeMinutes);

        $dispatched = 0;
        $failed = 0;

        GameMatch::query()
            ->where('status', MatchStatus::Pending)
            ->whereBetween('created_at', [$oldest, $youngest])
            // Eager-load what DispatchAutoFetchAction reads so its per-match
            // loadMissing('listing', 'providerSnapshots') is a no-op — otherwise
            // it's 2 queries × up to CHUNK_SIZE rows every run (amplified by the
            // M46 P3 every-minute young tier).
            ->with(['listing', 'providerSnapshots'])
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
