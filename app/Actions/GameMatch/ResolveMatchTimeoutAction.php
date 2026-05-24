<?php

namespace App\Actions\GameMatch;

use App\Actions\Admin\NotifyAdminsAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Filament\Resources\GameMatches\GameMatchResource;
use App\Models\GameMatch;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a single timed-out `Pending` match by flipping it to
 * `ManualReview`. Designed to be called from the iteration loop in
 * `App\Console\Commands\MatchesResolveTimeouts`.
 *
 * M16 simplification — there's no "honor claim" path anymore (player
 * Won/Lost/Drawn confirms were removed). The Phase 2 auto-fetch triggers
 * (page-visit, chat-send, every-5-min cron) have been hammering the
 * provider API for the full 4h window. If no game has been auto-fetched
 * + settled by the time the timeout fires, no game is going to be found
 * — money sits frozen until admin (M12) or M16-cooling-off resolves.
 *
 * Returns one of:
 *   - `'manual-review'` → flipped to ManualReview, system messages posted.
 *   - `'skipped'`       → no longer eligible (race: match settled / cancelled
 *                          between the SELECT and the row lock).
 */
class ResolveMatchTimeoutAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
        private readonly NotifyAdminsAction $notifyAdmins,
    ) {}

    public function handle(int $matchId, DateTimeInterface $deadline): string
    {
        $outcome = DB::transaction(function () use ($matchId, $deadline) {
            $match = GameMatch::query()->lockForUpdate()->find($matchId);

            if (! $this->isStillEligible($match, $deadline)) {
                return 'skipped';
            }

            $match->load(['listing.user', 'taker']);

            $this->flipToManualReview($match);

            return 'manual-review';
        });

        // After-commit notification — admin queue gets a bell ping for the
        // newly-flagged match. Skipped path doesn't fire (race-loss to
        // another resolver — there's nothing for admin to act on).
        if ($outcome === 'manual-review') {
            $this->notifyAdminsOfTimeout(GameMatch::query()->find($matchId));
        }

        return $outcome;
    }

    /**
     * Re-check inside the lock: auto-fetch (or any other resolver) may
     * have settled / cancelled this match between our SELECT and the lock.
     */
    private function isStillEligible(?GameMatch $match, DateTimeInterface $deadline): bool
    {
        return $match !== null
            && $match->status === MatchStatus::Pending
            && $match->created_at->lte($deadline);
    }

    /**
     * Two system messages bracket the status flip:
     *
     *   1. Narration — why the match was flagged (timeout, no API game found).
     *   2. `dispute_prompt`-marked evidence call-to-action — same shape as
     *      `ResolveDisputeAction::flipToManualReview` so the React
     *      `SystemBubble` renders the warning-toned variant uniformly
     *      across both manual-dispute and timeout entry points.
     */
    private function flipToManualReview(GameMatch $match): void
    {
        $this->postSystem->handle(
            $match,
            __('4-hour confirmation window expired without an API-verified game record. Match flagged for admin review — your stakes stay in escrow until resolved.'),
        );

        $match->update(['status' => MatchStatus::ManualReview]);

        $this->postSystem->handle(
            $match,
            __('Submit evidence in chat — screenshot, game URL, or PGN. An admin will review.'),
            [['type' => 'dispute_prompt']],
        );
    }

    private function notifyAdminsOfTimeout(?GameMatch $match): void
    {
        if ($match === null) {
            return;
        }

        $match->loadMissing(['listing.user', 'taker']);

        $creator = $match->listing?->user?->username ?? 'unknown';
        $taker = $match->taker?->username ?? 'unknown';
        $stake = number_format((float) ($match->listing?->stake_amount ?? 0), 2);

        $this->notifyAdmins->handle(
            title: "Match auto-flagged — #{$match->id}",
            body: "Match #{$match->id} timed out without an API-verified result. {$creator} vs {$taker}, \${$stake} stake each. Please review.",
            url: GameMatchResource::getUrl('view', ['record' => $match]),
            color: 'danger',
        );
    }
}
