<?php

namespace App\Console\Commands;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Services\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refunds escrow + flips status on listings whose `expires_at` has passed
 * but whose `status` is still Open. Runs every minute via the scheduler.
 *
 * Idempotent end-to-end:
 *   - Re-checks status + expiry inside a row-locked transaction so concurrent
 *     take / cancel can't race us into a double-action.
 *   - `Wallet::release` is keyed on `listing-expire:{id}`, so a crashed
 *     mid-run never double-refunds on retry.
 */
class ExpireListings extends Command
{
    protected $signature = 'listings:expire {--limit=500}';

    protected $description = 'Refund escrow + flip status on listings past their expiry.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        // Paused listings still hit their expiry deadline — pause is
        // visibility-only, not a time freeze. Locked decision in
        // milestones.md M6 Phase 6 "Pause/resume locked decisions".
        $expirable = [ListingStatus::Open, ListingStatus::Paused];

        $ids = Listing::query()
            ->whereIn('status', $expirable)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $expired = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $didExpire = DB::transaction(function () use ($id, $expirable) {
                    $listing = Listing::query()->lockForUpdate()->find($id);

                    // Re-check inside the lock: a concurrent take, cancel, or
                    // pause/resume may have flipped status between our SELECT
                    // and the lock.
                    if (! $listing
                        || ! in_array($listing->status, $expirable, true)
                        || $listing->expires_at->gt(now())
                    ) {
                        return false;
                    }

                    Wallet::release(
                        user: $listing->user,
                        amount: (string) $listing->stake_amount,
                        listing: $listing,
                        reference: "listing-expire:{$listing->id}",
                        description: 'Stake refunded on listing expiry.',
                    );

                    $listing->update(['status' => ListingStatus::Expired]);

                    return true;
                });

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
