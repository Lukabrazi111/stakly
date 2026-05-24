<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Step 1 of the M10 mutual cancellation flow: one participant proposes to
 * call the match off. Writes the pending columns (`cancellation_requested_by`,
 * `cancellation_requested_at`, `cancellation_reason`) and posts a system
 * message inviting the opponent to accept or reject. No money moves at this
 * stage — funds keep waiting in escrow until the opponent acts.
 *
 * Returns:
 *   - `'requested'`  — request recorded, system message posted.
 *   - `'race_lost'`  — match was no longer Pending (or an open request appeared)
 *                      by the time our row lock acquired. Controller maps this
 *                      to an info toast.
 *
 * Race-safety:
 *   - Two participants requesting simultaneously → second caller's lock waits,
 *     sees `cancellation_requested_at` set, returns `'race_lost'`.
 *   - Race with opponent's confirm landing first → status flips off Pending
 *     before our lock, returns `'race_lost'`.
 *   - Re-request after our own previous request was rejected → policy
 *     enforces the 30-min cooldown upstream; this Action additionally clears
 *     the stale `cancellation_rejected_at` marker since a fresh open request
 *     supersedes the cooldown record.
 */
class RequestCancellationAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(User $requester, GameMatch $match, ?string $reason = null): string
    {
        return DB::transaction(function () use ($match, $requester, $reason) {
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
    }

    /**
     * Note on the reason field: it's persisted on the match but
     * deliberately NOT included in the system message body. The reason
     * surfaces in the structured inline banner on the match page where
     * the opponent reads it — never as free text inside chat. This
     * sidesteps the abuse vector where a malicious user could sneak
     * URLs / payment handles / harassment through cancellation reasons
     * (system messages bypass the M13 chat anti-abuse layer). The banner
     * renders the reason inside a controlled UI block and we keep the
     * sanitization options open (truncate, escape, regex-flag) without
     * having to retroactively scrub chat history.
     */
    private function recordRequest(GameMatch $match, User $requester, ?string $reason): void
    {
        $match->update([
            'cancellation_requested_by' => $requester->id,
            'cancellation_requested_at' => now(),
            'cancellation_reason' => $reason,
            // Clear the stale rejection marker — a fresh open request
            // supersedes any prior cooldown record (policy already verified
            // the requester is past their per-user cooldown window).
            'cancellation_rejected_at' => null,
        ]);
    }
}
