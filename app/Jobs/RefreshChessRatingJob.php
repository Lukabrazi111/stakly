<?php

namespace App\Jobs;

use App\Enums\LinkedAccountProvider;
use App\Models\LinkedAccount;
use App\Models\LinkedAccountRating;
use App\Services\Provider\ChessComProfileClient;
use App\Services\Provider\ChessRatings;
use App\Services\Provider\ChessTimeControlRating;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\LichessProfileClient;
use App\Services\Provider\ProviderCircuitBreaker;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Re-pulls one chess account's per-time-control ratings (bullet/blitz/rapid)
 * and upserts the `linked_account_ratings` rows (M41 P3b). Display-only — it
 * touches no money and gates no match. The chess analogue of
 * `RefreshFaceitRatingJob`; the two share no lock namespace (distinct classes).
 *
 * Invariant: a failed, rate-limited, or 404 fetch ALWAYS preserves the
 * last-known ratings — rows are UPSERTED, never deleted, and an existing rating
 * is never overwritten with null. Stale-but-present beats wiped, because the
 * rating drives the UI only; settlement rides the match-time snapshot + the
 * game API, not this cache.
 *
 * Self-throttled on a per-provider rating limiter (`chess-com-rating-api` /
 * `lichess-rating-api`) — SEPARATE from the settlement budgets so a refresh
 * storm can't freeze auto-settlement. Deduped per account via ShouldBeUnique.
 *
 * Breaker note: the profile clients self-record success/failure on every HTTP
 * path, so this job only READS `isOpen()` to skip when the provider is tripped.
 */
class RefreshChessRatingJob implements ShouldBeUnique, ShouldQueue
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
        return "chess-rating-refresh:{$this->linkedAccount->id}";
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
        $limiter = $this->linkedAccount->provider === LinkedAccountProvider::Lichess
            ? 'lichess-rating-api'
            : 'chess-com-rating-api';

        return [new RateLimited($limiter)];
    }

    public function handle(
        ChessComProfileClient $chessCom,
        LichessProfileClient $lichess,
        ProviderCircuitBreaker $breaker,
    ): void {
        $account = $this->linkedAccount;

        // Breaker open → skip the call, keep last-known. `synced_at` stays as-is
        // so the lazy path retries once the cooldown lifts.
        if ($breaker->isOpen($account->provider)) {
            return;
        }

        $client = $account->provider === LinkedAccountProvider::Lichess
            ? $lichess
            : $chessCom;

        try {
            $ratings = $client->fetchRatings($account->username);
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
        // retries it via backoff(); the last-known ratings stay untouched.

        $this->storeRatings($ratings);
    }

    /**
     * Upsert one row per returned time control + stamp the account-level
     * freshness marker, atomically. Rows for time controls absent from the
     * payload are left untouched (never deleted) — the never-null invariant.
     *
     * A single `upsert` (ON CONFLICT DO UPDATE on the unique
     * (linked_account_id, time_control)) is atomic and concurrency-safe — two
     * overlapping refreshes for the same account can't race into a duplicate
     * insert. The whole write runs in one transaction so a partial upsert + a
     * skipped freshness bump can't happen.
     */
    private function storeRatings(ChessRatings $ratings): void
    {
        $now = now();

        $rows = array_map(fn (ChessTimeControlRating $rating): array => [
            'linked_account_id' => $this->linkedAccount->id,
            'time_control' => $rating->timeControl->value,
            'rating' => $rating->rating,
            'rd' => $rating->rd,
            'is_provisional' => $rating->isProvisional,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ratings->ratings);

        DB::transaction(function () use ($rows, $now) {
            if ($rows !== []) {
                LinkedAccountRating::upsert(
                    $rows,
                    ['linked_account_id', 'time_control'],
                    ['rating', 'rd', 'is_provisional', 'synced_at', 'updated_at'],
                );
            }

            // Account-level freshness gate (uniform across providers — the chess
            // ratings live in the child table, but staleness is tracked here so
            // `RefreshLinkedAccountRatingAction` reads one column for every kind).
            $this->linkedAccount->update(['skill_rating_synced_at' => $now]);
        });
    }
}
