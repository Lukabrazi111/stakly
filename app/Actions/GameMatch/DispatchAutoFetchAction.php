<?php

namespace App\Actions\GameMatch;

use App\Enums\AutoFetchOutcome;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Jobs\AutoFetchChessComGameJob;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;

/**
 * M16 Phase 2 — dispatches the per-platform auto-fetch job for a Pending
 * match. Layered trigger sites all funnel through here:
 *
 *   - `GameMatchController::show` on every Pending page-visit.
 *   - `SendMessageAction` on every chat message during Pending / Disputed.
 *   - `App\Console\Commands\AutoFetchPendingMatches` cron at 5-min cadence.
 *   - (Phase 4) Lichess stream consumer on stream `game-end` events.
 *
 * Idempotency lives at the job layer:
 *   - `AutoFetch*GameJob` implements `ShouldBeUnique` — concurrent dispatches
 *     for the same match are dropped while a job is in-flight, so F5-spam
 *     or a cron-page-visit race doesn't thunder-herd the provider API.
 *   - The job's `alreadyPosted()` check short-circuits if a card already
 *     landed (e.g. settled in a prior run; re-dispatch is a cheap DB query).
 *
 * Pre-flight gates (silent skip — these are normal "nothing to do" states):
 *   - Match must be Pending. Other statuses don't need a fresh API fetch.
 *   - Both sides must have a snapshot for the listing's platform —
 *     auto-fetch can't anchor a card without both usernames.
 *
 * M14 Phase 1 — every skip writes a `match_auto_fetch_attempts` row via
 * `RecordAutoFetchAttemptAction` so the admin timeline reflects "we
 * considered fetching and didn't, here's why." High-frequency triggers
 * (page-visit, chat-send) on non-Pending matches will dominate row counts;
 * that's expected and is itself a signal about user activity on closed
 * matches.
 */
class DispatchAutoFetchAction
{
    public function __construct(
        private readonly RecordAutoFetchAttemptAction $recordAttempt,
    ) {}

    public function handle(GameMatch $match): void
    {
        $match->loadMissing('listing', 'providerSnapshots');

        // Reading the platform off the listing first means even the
        // `not_pending` skip carries provider context. Without that,
        // PipelineHealth couldn't roll up skip volume per provider.
        $platform = $match->listing->platform;

        if ($match->status !== MatchStatus::Pending) {
            $this->recordSkip($match->id, $platform, 'not_pending');

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
