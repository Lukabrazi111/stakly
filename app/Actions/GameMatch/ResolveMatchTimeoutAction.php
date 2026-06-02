<?php

namespace App\Actions\GameMatch;

use App\Actions\Admin\NotifyAdminsAction;
use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Filament\Resources\GameMatches\GameMatchResource;
use App\Models\GameMatch;
use App\Notifications\MatchManualReviewNotification;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a single timed-out `Pending` match by flipping it to `ManualReview`.
 * If no game was auto-fetched + settled during the 4h confirmation window, no
 * game is going to be found — money sits frozen until admin resolves.
 *
 * Returns `'manual-review'` or `'skipped'` (race: settled/cancelled between SELECT and lock).
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

        if ($outcome === 'manual-review') {
            $fresh = GameMatch::query()->with(['listing.user', 'taker'])->find($matchId);
            $this->notifyAdminsOfTimeout($fresh);
            $this->notifyPlayers($fresh);
        }

        return $outcome;
    }

    private function notifyPlayers(?GameMatch $match): void
    {
        if ($match === null) {
            return;
        }

        $notification = new MatchManualReviewNotification($match);
        $match->listing->user->notify($notification);
        $match->taker->notify($notification);
    }

    /**
     * Re-check inside the lock — another resolver may have settled / cancelled between SELECT and lock.
     */
    private function isStillEligible(?GameMatch $match, DateTimeInterface $deadline): bool
    {
        return $match !== null
            && $match->status === MatchStatus::Pending
            && $match->created_at->lte($deadline);
    }

    /**
     * Two system messages bracket the status flip: narration + `dispute_prompt`-marked
     * evidence CTA so `SystemBubble` renders the warning variant uniformly across
     * manual-dispute and timeout entry points.
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
