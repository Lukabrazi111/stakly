<?php

namespace App\Actions\GameMatch;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manual escalation to game-API resolution during the player-confirm
 * window. Either participant can open a dispute — the API winner is
 * authoritative and overrides player self-reports.
 *
 * Returns `null` if the match was already resolved (status non-Pending by
 * the time our row lock acquired), otherwise returns the post-resolution
 * sentinel string the controller maps to a flash toast.
 *
 * Race-safety:
 *   - Opponent's confirm landing first → match flips to Settled or
 *     Disputed before our row lock; we return null ("already resolved").
 *   - Two players opening dispute simultaneously → second caller's row
 *     lock waits, sees status=Disputed, returns null.
 *   - Race with the timeout job → same `lockForUpdate` + status guard;
 *     whichever runs second is a no-op.
 */
class OpenDisputeAction
{
    public function __construct(
        private readonly ResolveDisputeAction $resolveDispute,
    ) {}

    public function handle(User $user, GameMatch $match): ?string
    {
        $opened = DB::transaction(function () use ($match, $user) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Pending) {
                return false;
            }

            $this->flipToDisputed($locked, $user);

            return true;
        });

        if (! $opened) {
            return null;
        }

        // Resolve via API outside the dispute-flip transaction. Mock is sync;
        // M8 swaps in queued jobs for real chess.com / Lichess calls.
        $this->resolveDispute->handle($match->fresh());

        return $this->postDisputeResolutionSentinel($match->fresh());
    }

    private function flipToDisputed(GameMatch $match, User $opener): void
    {
        $match->update([
            'status' => MatchStatus::Disputed,
            'dispute_opened_at' => now(),
            'dispute_opened_by' => $opener->id,
        ]);
    }

    private function postDisputeResolutionSentinel(GameMatch $match): string
    {
        return match ($match->status) {
            MatchStatus::Settled => $match->winner_user_id === null
                ? 'settled-by-api-draw'
                : 'settled-by-api',
            MatchStatus::ManualReview => 'manual-review',
            default => 'disputed',
        };
    }
}
