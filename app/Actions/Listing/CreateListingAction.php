<?php

namespace App\Actions\Listing;

use App\Models\Listing;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Creates a listing AND escrows the stake atomically. The DB::transaction
 * wraps both operations so a failed `Wallet::hold` rolls back the listing
 * row — we never leave an unfunded listing on the board.
 *
 * Sentinel returns the controller maps to user-facing flows:
 *   - `Listing` instance → success; controller redirects to /listings/mine.
 *   - `'not_linked'`     → creator has no verified chess provider account
 *                          (M8 Phase 5 create-gate). Controller redirects
 *                          to /settings/linked-accounts with CTA toast.
 *
 * Propagates `App\Exceptions\InsufficientBalanceException` to the caller
 * (race window between the FormRequest's balance pre-check and the
 * row-locked re-check inside `Wallet::hold`). `ListingController::store`
 * catches it and maps to a `ValidationException` keyed on `stake_amount`.
 *
 * Idempotency key on `Wallet::hold` is `listing-create:{id}` — paired with
 * `listing-cancel:{id}` in `CancelListingAction` so the create/release
 * reference pair is always discoverable.
 */
class CreateListingAction
{
    /**
     * @param  array<string, mixed>  $data  Validated input from `StoreListingRequest::validated()`.
     */
    public function handle(User $user, array $data): Listing|string
    {
        // Linked-account gate (M8 Phase 5). Runs before the transaction:
        // it's a static user-state check, no DB write to wrap. Frontend
        // hides the Create CTA when `has_chess_link === false`, so reaching
        // here is either a stale-tab POST or a crafted request.
        if (! $user->hasVerifiedChessLink()) {
            return 'not_linked';
        }

        return DB::transaction(function () use ($user, $data) {
            $listing = $user->listings()->create([
                'game' => $data['game'],
                'stake_amount' => $data['stake_amount'],
                'skill_min' => $data['skill_min'] ?? null,
                'skill_max' => $data['skill_max'] ?? null,
                'time_control' => $data['time_control'],
                'region' => $data['region'] ?? null,
                'language' => $data['language'] ?? null,
                'expires_at' => now()->addHours((int) $data['duration_hours']),
            ]);

            Wallet::hold(
                user: $user,
                amount: (string) $data['stake_amount'],
                listing: $listing,
                reference: "listing-create:{$listing->id}",
                description: 'Stake escrowed on listing creation.',
            );

            return $listing;
        });
    }
}
