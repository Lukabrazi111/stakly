<?php

namespace App\Actions\GameMatch;

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
 */
class DispatchAutoFetchAction
{
    public function handle(GameMatch $match): void
    {
        if ($match->status !== MatchStatus::Pending) {
            return;
        }

        $match->loadMissing('listing', 'providerSnapshots');

        $platform = $match->listing->platform;

        $creatorSnap = $match->snapshotUsername(GameMatch::SIDE_CREATOR, $platform);
        $takerSnap = $match->snapshotUsername(GameMatch::SIDE_TAKER, $platform);

        if ($creatorSnap === null || $takerSnap === null) {
            return;
        }

        match ($platform) {
            LinkedAccountProvider::Lichess => AutoFetchLichessGameJob::dispatch($match),
            LinkedAccountProvider::ChessCom => AutoFetchChessComGameJob::dispatch($match),
        };
    }
}
