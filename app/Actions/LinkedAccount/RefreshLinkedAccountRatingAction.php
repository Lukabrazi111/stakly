<?php

namespace App\Actions\LinkedAccount;

use App\Enums\LinkedAccountProvider;
use App\Jobs\RefreshFaceitRatingJob;
use App\Models\LinkedAccount;
use App\Services\Provider\ProviderCircuitBreaker;

/**
 * Decides whether a linked account's cached skill rating is worth refreshing
 * and, if so, enqueues the provider-specific refresh job. The dispatch gate is
 * cheap, synchronous checks only — it keeps us from queueing pointless jobs
 * (unsupported provider, no API key, breaker open, still fresh).
 *
 * Provider-agnostic by name; FACEIT-only today (chess rating capture lands in
 * M41 P3). A refresh NEVER nulls an existing rating — see RefreshFaceitRatingJob.
 */
class RefreshLinkedAccountRatingAction
{
    public function __construct(
        private readonly ProviderCircuitBreaker $breaker,
    ) {}

    /**
     * Returns true when a refresh job was dispatched. `$force` skips only the
     * staleness check — every other gate still applies.
     */
    public function handle(LinkedAccount $account, bool $force = false): bool
    {
        if ($account->provider !== LinkedAccountProvider::Faceit) {
            return false;
        }

        if ($account->provider_user_id === null) {
            return false;
        }

        if (empty(config('services.faceit.api_key'))) {
            return false;
        }

        if ($this->breaker->isOpen(LinkedAccountProvider::Faceit)) {
            return false;
        }

        if (! $force && ! $this->isStale($account)) {
            return false;
        }

        RefreshFaceitRatingJob::dispatch($account);

        return true;
    }

    private function isStale(LinkedAccount $account): bool
    {
        $syncedAt = $account->skill_rating_synced_at;

        if ($syncedAt === null) {
            return true;
        }

        $ttlHours = (int) config('services.faceit.rating_ttl_hours', 24);

        return $syncedAt->lt(now()->subHours($ttlHours));
    }
}
