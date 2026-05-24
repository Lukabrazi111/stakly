<?php

namespace App\Console\Commands;

use App\Actions\GameMatch\ResolveMatchTimeoutAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use Illuminate\Console\Command;
use Throwable;

/**
 * Resolves Pending matches that have passed the confirmation deadline
 * (default: 4h after match creation, configurable via
 * `stakly.match_confirmation_timeout_hours`). Scheduled task in
 * `routes/console.php`.
 *
 * Per-match logic lives in `ResolveMatchTimeoutAction`. M16 simplified the
 * action: every timed-out match flips to `ManualReview` (the auto-fetch
 * triggers in M16 Phase 2 have been retrying every 5 min for 4h — no game
 * is going to materialize at the timeout boundary). This command owns
 * the iteration loop, summary, and per-match failure isolation.
 *
 * Iteration uses `chunkById(100)` so a backlog stays bounded in memory.
 *
 * Idempotency: handled at the match-status level (Pending guard inside
 * the action's row lock).
 *
 * **Dev caveat:** Laravel's scheduler does not auto-run in dev. Fire
 * manually via `sail artisan matches:resolve-timeouts`, or run
 * `sail artisan schedule:work` in a separate terminal.
 */
class MatchesResolveTimeouts extends Command
{
    protected $signature = 'matches:resolve-timeouts';

    protected $description = 'Resolve Pending matches that have passed the confirmation deadline.';

    private const CHUNK_SIZE = 100;

    public function handle(ResolveMatchTimeoutAction $action): int
    {
        $deadline = now()->subHours((int) config('stakly.match_confirmation_timeout_hours'));

        $manualReview = 0;
        $skipped = 0;
        $failed = 0;

        GameMatch::query()
            ->where('status', MatchStatus::Pending)
            ->where('created_at', '<=', $deadline)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($matches) use ($action, &$manualReview, &$skipped, &$failed, $deadline) {
                foreach ($matches as $match) {
                    try {
                        $result = $action->handle($match->id, $deadline);

                        match ($result) {
                            'manual-review' => $manualReview++,
                            default => $skipped++,
                        };
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->info("Flagged for review {$manualReview}. Skipped {$skipped}. Failed {$failed}.");

        return self::SUCCESS;
    }
}
