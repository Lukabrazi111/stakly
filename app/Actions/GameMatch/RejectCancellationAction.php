<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Step 2-alt of the mutual cancellation flow: the opponent declines.
 * Match stays Pending, stakes stay in escrow, rejection timestamp starts the
 * original requester's 30-min per-user cooldown (enforced in `GameMatchPolicy::requestCancellation`).
 *
 * `cancellation_requested_by` is preserved as the cooldown key — the policy reads
 * "did THIS user request, and was rejected within 30 minutes?" to block re-request.
 * The OTHER participant can still request immediately.
 *
 * Returns: `'rejected'`, `'race_lost'`, `'request_missing'`, or `'self_reject_forbidden'`.
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
            'cancellation_requested_at' => null,
            'cancellation_reason' => null,
            // `cancellation_requested_by` preserved as the per-user cooldown key.
            'cancellation_rejected_at' => now(),
        ]);
    }
}
