<?php

namespace App\Actions\GameMatch;

use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Jobs\AutoFetchChessComGameJob;
use App\Jobs\AutoFetchFaceitGameJob;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;
use App\Services\Provider\ProviderCircuitBreaker;

/**
 * Funnel for per-platform auto-fetch job dispatch (page-visit, chat-send, cron, Lichess stream).
 * Idempotency lives at the job layer (`ShouldBeUnique` + `alreadyPosted()` short-circuit).
 * Every skip writes a `match_auto_fetch_attempts` row so the admin timeline reflects the decision.
 *
 * M14 Slice 2d: `circuit_open` skip when the per-provider breaker has tripped — keeps us
 * from hammering a provider that's already failing the rest of the pipeline.
 */
class DispatchAutoFetchAction
{
    public function __construct(
        private readonly RecordAutoFetchAttemptAction $recordAttempt,
        private readonly ProviderCircuitBreaker $breaker,
    ) {}

    public function handle(GameMatch $match): void
    {
        $match->loadMissing('listing', 'providerSnapshots');

        // Read platform first so even the `not_pending` skip carries provider
        // context for PipelineHealth's per-provider skip rollup.
        $platform = $match->listing->platform;

        if ($match->status !== MatchStatus::Pending) {
            $this->recordSkip($match->id, $platform, 'not_pending');

            return;
        }

        if ($this->breaker->isOpen($platform)) {
            $this->recordSkip($match->id, $platform, 'circuit_open');

            return;
        }

        $creatorSnap = $match->snapshotUsername(GameMatch::SIDE_CREATOR, $platform);
        $takerSnap = $match->snapshotUsername(GameMatch::SIDE_TAKER, $platform);

        if ($creatorSnap === null || $takerSnap === null) {
            $this->recordSkip($match->id, $platform, 'snapshot_missing');

            return;
        }

        match ($platform) {
            LinkedAccountProvider::Lichess => AutoFetchLichessGameJob::dispatch($match),
            LinkedAccountProvider::ChessCom => AutoFetchChessComGameJob::dispatch($match),
            LinkedAccountProvider::Faceit => AutoFetchFaceitGameJob::dispatch($match),
        };
    }

    private function recordSkip(int $matchId, LinkedAccountProvider $provider, string $reason): void
    {
        $this->recordAttempt->handle(
            matchId: $matchId,
            provider: $provider,
            outcome: AutoFetchOutcome::Skipped,
            extras: ['outcome_reason' => $reason],
        );
    }
}
