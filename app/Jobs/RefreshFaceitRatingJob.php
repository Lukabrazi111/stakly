<?php

namespace App\Jobs;

use App\Enums\LinkedAccountProvider;
use App\Models\LinkedAccount;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\FaceitProfileClient;
use App\Services\Provider\ProviderCircuitBreaker;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * Re-pulls one FACEIT account's CS2 ELO from the Data API and refreshes the
 * cached `skill_rating` + `skill_rating_synced_at`. Display-only (M41 P1) — it
 * touches no money and gates no match.
 *
 * Invariant: a failed, rate-limited, 404, or empty fetch ALWAYS preserves the
 * last-known rating. Stale-but-present beats wiped, because the rating drives
 * the UI only — settlement rides the match-time snapshot + the API result, not
 * this cache.
 *
 * Self-throttled on a dedicated `faceit-rating-api` limiter so a refresh storm
 * can never consume the `faceit-api` budget money-critical settlement
 * (`AutoFetchFaceitGameJob`) depends on. Deduped per account via ShouldBeUnique.
 *
 * Breaker note: `FaceitProfileClient` self-records success/failure on every
 * HTTP path, so this job must NOT call recordSuccess/recordFailure — it only
 * reads `isOpen()` to skip when the provider is already tripped.
 */
class RefreshFaceitRatingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Widened to absorb `RateLimited` middleware releases (each consumes an attempt). */
    public int $tries = 12;

    public int $timeout = 30;

    public int $uniqueFor = 180;

    public function __construct(
        public LinkedAccount $linkedAccount,
    ) {}

    public function uniqueId(): string
    {
        return "rating-refresh:{$this->linkedAccount->id}";
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('faceit-rating-api')];
    }

    public function handle(FaceitProfileClient $client, ProviderCircuitBreaker $breaker): void
    {
        // Breaker open → skip the call, keep last-known. `synced_at` stays as-is
        // so the lazy path retries once the cooldown lifts.
        if ($breaker->isOpen(LinkedAccountProvider::Faceit)) {
            return;
        }

        if ($this->linkedAccount->provider_user_id === null) {
            return;
        }

        try {
            $profile = $client->fetchPlayer($this->linkedAccount->provider_user_id);
        } catch (ProfileNotFoundException) {
            // Identity gone (404). Keep last-known; don't mark synced.
            return;
        } catch (RateLimitedError $e) {
            // The client already recorded the breaker failure. Honor the
            // provider's Retry-After when present, else fall back to backoff().
            $retryAt = $e->retryAt();
            $nowTs = now()->getTimestamp();

            if ($retryAt !== null
                && $retryAt->getTimestamp() > $nowTs
                && $this->attempts() < $this->tries
            ) {
                $this->release(max(1, $retryAt->getTimestamp() - $nowTs));

                return;
            }

            throw $e;
        } catch (PermanentProviderError $e) {
            $this->fail($e);

            return;
        }
        // A TransientProviderError (5xx / connect) is left uncaught so Laravel
        // retries it via backoff(); the last-known rating stays untouched until
        // a fetch succeeds.

        // No API key configured (dev) → the client returns null. Keep
        // last-known and don't mark synced, so the lazy path re-pulls once a
        // key exists.
        if ($profile === null) {
            return;
        }

        $payload = ['skill_rating_synced_at' => now()];

        // Overwrite the rating only when the provider returned one — a player
        // with no CS2 history (null ELO) keeps any previously-known value while
        // we still bump `synced_at` so we don't re-hammer them every trigger.
        if ($profile->cs2Elo !== null) {
            $payload['skill_rating'] = $profile->cs2Elo;
        }

        $this->linkedAccount->update($payload);
    }
}
