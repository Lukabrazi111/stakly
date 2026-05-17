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
 * The per-match resolution logic lives in `ResolveMatchTimeoutAction`.
 * This command owns the iteration loop, summary statistics, and
 * per-match failure isolation — one bad match must not stop the run.
 *
 * Iteration uses `chunkById(100)` so a backlog stays bounded in memory.
 *
 * Idempotency: handled at the match-status level (Pending guard) + at the
 * wallet-reference level via the underlying settle / settleDraw /
 * resolveDispute Actions. No additional `match-timeout:{id}` reference.
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

        $settled = 0;
        $disputed = 0;
        $skipped = 0;
        $failed = 0;

        GameMatch::query()
            ->where('status', MatchStatus::Pending)
            ->where('created_at', '<=', $deadline)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($matches) use ($action, &$settled, &$disputed, &$skipped, &$failed, $deadline) {
                foreach ($matches as $match) {
                    try {
                        $result = $action->handle($match->id, $deadline);

                        match ($result) {
                            'settled' => $settled++,
                            'disputed' => $disputed++,
                            default => $skipped++,
                        };
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->info("Settled {$settled}. Sent to API {$disputed}. Skipped {$skipped}. Failed {$failed}.");

        return self::SUCCESS;
    }
}
