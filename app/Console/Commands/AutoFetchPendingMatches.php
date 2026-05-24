<?php

namespace App\Console\Commands;

use App\Actions\GameMatch\DispatchAutoFetchAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use Illuminate\Console\Command;
use Throwable;

/**
 * M16 Phase 2 — periodic auto-fetch backstop. Scans Pending matches in the
 * "old enough to plausibly have a game played" window and dispatches the
 * per-platform auto-fetch job for each.
 *
 * Scan window: `created_at` between `now - timeout` and `now - 10 min`.
 *   - Lower bound (10 min): brand-new matches are covered by the page-visit
 *     trigger; the cron doesn't need to hammer them.
 *   - Upper bound (4h default, per `stakly.match_confirmation_timeout_hours`):
 *     past that, the `matches:resolve-timeouts` cron flips them to
 *     ManualReview. Auto-fetch on 4h+ matches would race the timeout with
 *     no benefit.
 *
 * Idempotency:
 *   - `DispatchAutoFetchAction` is a no-op when match is not Pending or
 *     when snapshots are missing.
 *   - The dispatched job is `ShouldBeUnique` keyed on match.id — concurrent
 *     dispatches (cron + page-visit + chat-send) collapse to one.
 *   - The job's `alreadyPosted()` short-circuits when a card already exists.
 *
 * Per-match failures are isolated (`try/catch` + `report()`) so one bad
 * match doesn't break the whole run. `chunkById(100)` bounds memory.
 *
 * **Dev caveat:** Laravel's scheduler does not auto-run in dev. Fire
 * manually via `sail artisan stakly:auto-fetch-pending`, or run
 * `sail artisan schedule:work` in a separate terminal.
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
