<?php

namespace App\Actions\LinkedAccount;

use App\Enums\Game;
use App\Models\LinkedAccount;
use App\Models\Listing;

/**
 * Refresh-on-view fan-out (M41 P2/P3b). Given the listings actually rendered on
 * a page, queue a stale-gated rating refresh for each distinct creator whose
 * rating is displayed — CS2 (FACEIT ELO) and chess (per-time-control ratings).
 * Reused by every surface that shows a listing's rating — the marketplace,
 * listing detail, lobby, my-listings, and public profiles — so the dedup /
 * stale / throttle policy lives in ONE place.
 *
 * Safe on a read path: the underlying RefreshLinkedAccountRatingAction gates on
 * provider + prerequisites + circuit breaker + 24h staleness, and the queued
 * job is deduped (ShouldBeUnique) + throttled (per-provider budget), so repeated
 * views can't hammer the provider.
 */
class RefreshDisplayedRatingsAction
{
    public function __construct(
        private readonly RefreshLinkedAccountRatingAction $refreshRating,
    ) {}

    /**
     * Refresh the creator ratings shown for a set of listings (CS2 + chess).
     * Iterates eager-loaded relations only — never lazy-load inside the loop.
     *
     * @param  iterable<Listing>  $listings
     */
    public function forListings(iterable $listings): void
    {
        $accounts = collect($listings)
            ->filter(fn (Listing $listing) => in_array($listing->game, [Game::Cs2, Game::Chess], true))
            // Only the account on the listing's OWN platform is displayed, so
            // refresh just that one — viewing a chess board shouldn't touch a
            // creator's unrelated FACEIT rating (and vice-versa).
            ->map(fn (Listing $listing) => $listing->user->linkedAccounts
                ->firstWhere('provider', $listing->platform))
            ->filter();

        $this->forAccounts($accounts);
    }

    /**
     * Refresh a set of linked accounts directly — used for lobby rosters where
     * each live participant's rating is displayed. Non-FACEIT accounts no-op
     * inside the action; dedup avoids double-dispatching one account per request.
     *
     * @param  iterable<LinkedAccount>  $accounts
     */
    public function forAccounts(iterable $accounts): void
    {
        collect($accounts)
            ->unique('id')
            ->each(fn (LinkedAccount $account) => $this->refreshRating->handle($account));
    }
}
