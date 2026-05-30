<?php

namespace App\Console\Commands;

use App\Actions\GameMatch\ResolveMatchTimeoutAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use Illuminate\Console\Command;
use Throwable;

/**
 * Resolves Pending matches past the confirmation deadline (default 4h via
 * `stakly.match_confirmation_timeout_hours`). Per-match logic lives in
 * `ResolveMatchTimeoutAction`; every timed-out match flips to `ManualReview`.
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
