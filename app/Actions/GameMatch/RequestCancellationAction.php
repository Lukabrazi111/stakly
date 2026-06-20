<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use App\Notifications\CancellationRequestedNotification;
use App\Services\MatchParticipants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Step 1 of the mutual cancellation flow: one participant proposes to call the match off.
 * No money moves here — funds wait in escrow until the opponent accepts or rejects.
 *
 * Returns `'requested'` or `'race_lost'` (match no longer Pending or open request exists).
 */
class RequestCancellationAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(User $requester, GameMatch $match, ?string $reason = null): string
    {
        $result = DB::transaction(function () use ($match, $requester, $reason) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Pending) {
                return 'race_lost';
            }

            if ($locked->cancellation_requested_at !== null) {
                return 'race_lost';
            }

            $this->recordRequest($locked, $requester, $reason);

            $locked->load('listing.user', 'taker');

            $this->postSystem->handle(
                $locked,
                __(':name requested to cancel the match.', ['name' => $requester->name]),
            );

            return 'requested';
        });

        if ($result === 'requested') {
            $fresh = $match->fresh(['listing.user', 'taker']);
            $opposingTeam = MatchParticipants::opposing($fresh, $requester);

            if ($opposingTeam->isNotEmpty()) {
                Notification::send($opposingTeam, new CancellationRequestedNotification($fresh, $requester));
            }
        }

        return $result;
    }

    /**
     * The reason is persisted on the match but deliberately NOT included in the system message —
     * it surfaces in the structured inline banner on the match page. Keeping it out of chat
     * sidesteps the abuse vector where a user could sneak URLs / payment handles / harassment
     * through cancellation reasons (system messages bypass the chat anti-abuse layer).
     */
    private function recordRequest(GameMatch $match, User $requester, ?string $reason): void
    {
        $match->update([
            'cancellation_requested_by' => $requester->id,
            'cancellation_requested_at' => now(),
            'cancellation_reason' => $reason,
            // Clear any stale rejection marker — fresh open request supersedes the cooldown record.
            'cancellation_rejected_at' => null,
        ]);
    }
}
