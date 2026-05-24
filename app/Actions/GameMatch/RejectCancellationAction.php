<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Step 2-alt of the M10 mutual cancellation flow: the *other* participant
 * declines the pending request. Match stays Pending, both stakes stay in
 * escrow, the rejection timestamp is recorded so the original requester
 * enters the 30-min per-user cooldown (enforced in
 * `GameMatchPolicy::requestCancellation`).
 *
 * Returns:
 *   - `'rejected'`               — request declined, cooldown started.
 *   - `'race_lost'`              — match no longer Pending (resolution
 *                                  path beat us to the lock).
 *   - `'request_missing'`        — no open request to reject.
 *   - `'self_reject_forbidden'`  — defensive: requester tried to reject
 *                                  their own request. Policy guards
 *                                  upstream; this is defense in depth.
 *
 * State after rejection:
 *   - `cancellation_requested_at`   → null (request closed)
 *   - `cancellation_reason`          → null (cleared)
 *   - `cancellation_requested_by`    → preserved (cooldown key)
 *   - `cancellation_rejected_at`     → now() (cooldown clock start)
 *
 * Holding `cancellation_requested_by` after rejection is what makes the
 * per-user cooldown work: the policy reads "did THIS user request, and
 * was rejected within 30 minutes?" → if yes, block re-request. The OTHER
 * participant can still request immediately (the rejecter or anyone else
 * is not in cooldown).
 */
class RejectCancellationAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(User $rejecter, GameMatch $match): string
    {
        return DB::transaction(function () use ($match, $rejecter) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Pending) {
                return 'race_lost';
            }

            if ($locked->cancellation_requested_at === null) {
                return 'request_missing';
            }

            if ($locked->cancellation_requested_by === $rejecter->id) {
                return 'self_reject_forbidden';
            }

            $this->recordRejection($locked);

            $locked->load('listing.user', 'taker');

            $this->postSystem->handle(
                $locked,
                __(':name declined the cancellation. Match continues.', [
                    'name' => $rejecter->name,
                ]),
            );

            return 'rejected';
        });
    }

    private function recordRejection(GameMatch $match): void
    {
        $match->update([
            // Close the open request — frees the per-match slot so either
            // player can submit a new request (subject to per-user cooldown).
            'cancellation_requested_at' => null,
            'cancellation_reason' => null,
            // Preserve `cancellation_requested_by` as the cooldown key,
            // plus stamp the rejection time for the policy's expiry check.
            'cancellation_rejected_at' => now(),
        ]);
    }
}
