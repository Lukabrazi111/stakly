<?php

namespace App\Console\Commands;

use App\Enums\MatchOutcome;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Services\MatchSettlement;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Resolves Pending matches that have passed the confirmation deadline
 * (default: 4h after match creation, configurable via
 * `stakly.match_confirmation_timeout_hours`). Scheduled task in
 * `routes/console.php`.
 *
 * Resolution rules (locked in milestones.md Phase 7):
 *   - One `Won`  + silent opponent → confirmer wins (honor claim).
 *   - One `Lost` + silent opponent → opponent wins (the confirmer told us
 *     the opponent won; we honor it).
 *   - One `Drawn` + silent opponent → game-API arbitrates. A single Drawn
 *     claim cannot unilaterally declare a draw — the opponent never agreed.
 *   - Neither confirmed → game-API arbitrates.
 *   - Both confirmed but still Pending → anomaly. The synchronous resolver
 *     in `GameMatchController::confirm` should have handled this; if it
 *     didn't we want it logged not auto-resolved.
 *
 * Mechanics:
 *   - Iterate via `chunkById` so backlogs stay bounded in memory.
 *   - Per-match try/catch + `report()` on failure so one bad match doesn't
 *     kill the whole run.
 *   - Row-locked transaction with status re-check inside the lock for
 *     race-safety against concurrent confirm / dispute submissions.
 *   - `resolveDispute` runs OUTSIDE the transaction (mirrors
 *     `GameMatchController::confirm`) — keeps the row lock free of API
 *     latency once real chess.com / Lichess adapters land in M8.
 *
 * Idempotency: handled at the match-status level (Pending guard) + at the
 * wallet-reference level via the existing `match-payout:{id}` /
 * `match-draw-*:{id}` / `match-fee:{id}` keys on the underlying
 * `settle` / `settleDraw` / `resolveDispute` calls. No additional
 * `match-timeout:{id}` reference is written.
 */
class MatchesResolveTimeouts extends Command
{
    protected $signature = 'matches:resolve-timeouts';

    protected $description = 'Resolve Pending matches that have passed the confirmation deadline.';

    private const CHUNK_SIZE = 100;

    public function handle(): int
    {
        $deadline = now()->subHours((int) config('stakly.match_confirmation_timeout_hours'));

        $settled = 0;
        $disputed = 0;
        $skipped = 0;
        $failed = 0;

        GameMatch::query()
            ->where('status', MatchStatus::Pending)
            ->where('created_at', '<=', $deadline)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($matches) use (&$settled, &$disputed, &$skipped, &$failed, $deadline) {
                foreach ($matches as $match) {
                    try {
                        $result = $this->resolveOne($match->id, $deadline);

                        match ($result) {
                            'settled' => $settled++,
                            'disputed' => $disputed++,
                            default => $skipped++,
                        };
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            });

        $this->info("Settled {$settled}. Sent to API {$disputed}. Skipped {$skipped}. Failed {$failed}.");

        return self::SUCCESS;
    }

    /**
     * Resolve a single match. Returns one of:
     *   - 'settled'  — directly settled via `MatchSettlement::settle`.
     *   - 'disputed' — flipped to Disputed + `MatchSettlement::resolveDispute` ran.
     *   - 'skipped'  — no longer eligible (race, anomaly, etc.).
     */
    private function resolveOne(int $matchId, DateTimeInterface $deadline): string
    {
        $action = DB::transaction(function () use ($matchId, $deadline) {
            $match = GameMatch::query()->lockForUpdate()->find($matchId);

            // Re-check inside the lock: a synchronous confirm may have just
            // resolved this match between our SELECT and the lock.
            if (! $match
                || $match->status !== MatchStatus::Pending
                || $match->created_at->gt($deadline)
            ) {
                return 'skip';
            }

            $match->load(['listing.user', 'taker']);

            $creatorOutcome = $match->creator_confirmed_outcome;
            $takerOutcome = $match->taker_confirmed_outcome;

            // Both confirmed but still Pending → the synchronous resolver in
            // GameMatchController::confirm should have flipped the status. If
            // it didn't (exception during settle? bug?), don't auto-resolve
            // — surface the anomaly and let an admin investigate.
            if ($creatorOutcome !== null && $takerOutcome !== null) {
                report(new RuntimeException(
                    "MatchesResolveTimeouts: match #{$match->id} has both confirmations set but status is Pending. Synchronous resolver should have handled this."
                ));

                return 'skip';
            }

            // No confirmations OR a single Drawn confirmation → game-API arbitrates.
            $singleConfirmedOutcome = $creatorOutcome ?? $takerOutcome;

            if ($singleConfirmedOutcome === null || $singleConfirmedOutcome === MatchOutcome::Drawn) {
                $match->update([
                    'status' => MatchStatus::Disputed,
                    'dispute_opened_at' => now(),
                ]);

                return 'dispute';
            }

            // Single Won/Lost confirmer: honor the claim.
            //   Won  → confirmer wins.
            //   Lost → opponent wins (confirmer told us the opponent won).
            $confirmerIsCreator = $creatorOutcome !== null;
            $confirmer = $confirmerIsCreator ? $match->listing->user : $match->taker;
            $opponent = $confirmerIsCreator ? $match->taker : $match->listing->user;

            $winner = $singleConfirmedOutcome === MatchOutcome::Won ? $confirmer : $opponent;

            MatchSettlement::settle($match, $winner);

            return 'settle';
        });

        // resolveDispute runs OUTSIDE the transaction (consistent with
        // GameMatchController::confirm). Mock driver is synchronous + fast;
        // M8 will swap in queued jobs for real adapters so the row lock
        // isn't held across network latency.
        if ($action === 'dispute') {
            MatchSettlement::resolveDispute(GameMatch::query()->findOrFail($matchId));

            return 'disputed';
        }

        return $action === 'settle' ? 'settled' : 'skipped';
    }
}
