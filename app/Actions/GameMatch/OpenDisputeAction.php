<?php

namespace App\Actions\GameMatch;

use App\Actions\Message\PostSystemMessageAction;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Player-triggered escalation during the Pending window. Either participant
 * can open a dispute; the match flips to `Disputed` and lands in the
 * admin review queue (M12 Phase 2).
 *
 * M12 Phase 3 — dispute resolution is now admin-driven by default. The
 * pre-Phase-3 behavior auto-resolved via `ResolveDisputeAction` (which
 * called the configured `GameApi` driver — `MockGameApi` in tests, no
 * production driver yet); after Phase 3, OpenDisputeAction stops invoking
 * that path. `ResolveDisputeAction` + `MockGameApi` remain in the codebase
 * for the test suite and for any future automated arbitration (M14).
 *
 * Returns `true` if the dispute was opened, `false` if the match was
 * already past Pending by the time our row lock acquired (race with
 * cancellation, settlement, or another dispute).
 *
 * Race-safety:
 *   - Two players opening dispute simultaneously → second caller's row
 *     lock waits, sees status=Disputed, returns false.
 *   - Race with the timeout job → same `lockForUpdate` + status guard;
 *     whichever runs second is a no-op.
 *   - Race with mutual cancellation acceptance → status becomes Cancelled
 *     before our lock; we return false.
 */
class OpenDisputeAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(User $user, GameMatch $match): bool
    {
        return DB::transaction(function () use ($match, $user) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Pending) {
                return false;
            }

            $this->flipToDisputed($locked, $user);

            $this->postSystem->handle(
                $locked,
                __('Dispute opened by :name. Please post any evidence (screenshots, game URLs, PGN) in this chat — an admin will review.', [
                    'name' => $user->name,
                ]),
                [['type' => 'dispute_prompt']],
            );

            return true;
        });
    }

    private function flipToDisputed(GameMatch $match, User $opener): void
    {
        $match->update([
            'status' => MatchStatus::Disputed,
            'dispute_opened_at' => now(),
            'dispute_opened_by' => $opener->id,
        ]);
    }
}
