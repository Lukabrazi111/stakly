<?php

namespace App\Console\Commands;

use App\Actions\Listing\ExpireListingAction;
use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refunds escrow + flips status on listings whose `expires_at` has passed
 * but whose `status` is still Open. Runs every minute via the scheduler.
 *
 * The per-listing refund logic lives in `ExpireListingAction`. This
 * command owns the iteration loop, summary statistics, and per-listing
 * failure isolation.
 */
class ExpireListings extends Command
{
    protected $signature = 'listings:expire {--limit=500}';

    protected $description = 'Refund escrow + flip status on listings past their expiry.';

    public function handle(ExpireListingAction $action): int
    {
        $limit = (int) $this->option('limit');

        $ids = Listing::query()
            ->where('status', ListingStatus::Open)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $expired = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $didExpire = $action->handle($id);

                $didExpire ? $expired++ : $skipped++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        $this->info("Expired {$expired}. Skipped {$skipped}. Failed {$failed}.");

        return self::SUCCESS;
    }
}
