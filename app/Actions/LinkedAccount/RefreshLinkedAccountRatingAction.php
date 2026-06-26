<?php

namespace App\Actions\LinkedAccount;

use App\Enums\LinkedAccountProvider;
use App\Jobs\RefreshChessRatingJob;
use App\Jobs\RefreshFaceitRatingJob;
use App\Models\LinkedAccount;
use App\Services\Provider\ProviderCircuitBreaker;

/**
 * Decides whether a linked account's cached skill rating is worth refreshing
 * and, if so, enqueues the provider-specific refresh job. The dispatch gate is
 * cheap, synchronous checks only — it keeps us from queueing pointless jobs
 * (unsupported provider, missing prerequisites, breaker open, still fresh).
 *
 * Supports FACEIT (scalar CS2 ELO → `RefreshFaceitRatingJob`) and the two chess
 * providers (per-time-control ratings → `RefreshChessRatingJob`, M41 P3b). A
 * refresh NEVER nulls an existing rating — see the respective jobs.
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
        if (! $this->providerSupportsRatings($account->provider)) {
            return false;
        }

        if (! $this->prerequisitesMet($account)) {
            return false;
        }

        if ($this->breaker->isOpen($account->provider)) {
            return false;
        }

        if (! $force && ! $this->isStale($account)) {
            return false;
        }

        $this->dispatchRefresh($account);

        return true;
    }

    private function providerSupportsRatings(LinkedAccountProvider $provider): bool
    {
        return in_array($provider, [
            LinkedAccountProvider::Faceit,
            LinkedAccountProvider::ChessCom,
            LinkedAccountProvider::Lichess,
        ], true);
    }

    /**
     * Provider-specific must-haves before a fetch is worth queueing. FACEIT
     * needs the stable player GUID + a configured Data-API key; the chess
     * providers read public, username-keyed endpoints, so they have none.
     */
    private function prerequisitesMet(LinkedAccount $account): bool
    {
        if ($account->provider !== LinkedAccountProvider::Faceit) {
            return true;
        }

        return $account->provider_user_id !== null
            && ! empty(config('services.faceit.api_key'));
    }

    private function dispatchRefresh(LinkedAccount $account): void
    {
        if ($account->provider === LinkedAccountProvider::Faceit) {
            RefreshFaceitRatingJob::dispatch($account);

            return;
        }

        RefreshChessRatingJob::dispatch($account);
    }

    private function isStale(LinkedAccount $account): bool
    {
        $syncedAt = $account->skill_rating_synced_at;

        if ($syncedAt === null) {
            return true;
        }

        $ttlHours = (int) config(
            "services.{$account->provider->value}.rating_ttl_hours",
            24,
        );

        return $syncedAt->lt(now()->subHours($ttlHours));
    }
}
