<?php

namespace App\Actions\GameMatch;

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M16 — settle a Pending match from an auto-fetched game card.
 *
 * Called by `AutoFetchLichessGameJob` / `AutoFetchChessComGameJob`
 * immediately after the card is posted. Replaces the M6 player-confirm
 * flow: the API is the only outcome source, the card IS the settlement
 * trigger, no Won/Lost/Drawn buttons.
 *
 * Branching on the card:
 *   - `winner_color = null` → draw, refund both stakes via `SettleDrawMatchAction`.
 *   - `winner_color` set    → map `winner_username` to a participant via
 *                             snapshot, pay winner via `SettleMatchAction`.
 *
 * Row-locked + status-guarded. Only acts on `Pending` — any other status
 * is a no-op:
 *   - Settled: idempotent re-call (e.g. job retry).
 *   - Disputed: `ResolveDisputeAction` owns that path; don't double-settle.
 *   - ManualReview: admin-resolved out of band; the card is evidence, not a trigger.
 *   - Cancelled: terminal, money's already refunded.
 *
 * Unmappable winners (snapshot lookup miss) log + no-op rather than throw.
 * This shouldn't fire — auto-fetch validates usernames against snapshots
 * before posting — but a mid-flight snapshot mutation shouldn't crash
 * settlement. Match stays Pending; cron retries via M16 Phase 2 triggers.
 */
class SettleFromCardAction
{
    public function __construct(
        private readonly SettleMatchAction $settle,
        private readonly SettleDrawMatchAction $settleDraw,
    ) {}

    /**
     * @param  array<string, mixed>  $gameCard
     */
    public function handle(GameMatch $match, array $gameCard): void
    {
        DB::transaction(function () use ($match, $gameCard) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Pending) {
                return;
            }

            $locked->load(['listing.user', 'taker', 'providerSnapshots']);

            if ($this->isDraw($gameCard)) {
                $this->settleDraw->handle($locked);

                return;
            }

            $winner = $this->resolveWinnerFromCard($locked, $gameCard);

            if ($winner === null) {
                Log::warning('SettleFromCardAction: card winner not in snapshots', [
                    'match_id' => $locked->id,
                    'winner_username' => $gameCard['winner_username'] ?? null,
                    'provider' => $gameCard['provider'] ?? null,
                ]);

                return;
            }

            $this->settle->handle($locked, $winner);
        });
    }

    /**
     * Draw cards carry `winner_color = null` regardless of provider — both
     * Lichess and chess.com result builders set this to null when the game
     * ended without a winner (agreed, stalemate, repetition, 50-move, etc.).
     *
     * @param  array<string, mixed>  $gameCard
     */
    private function isDraw(array $gameCard): bool
    {
        return ($gameCard['winner_color'] ?? null) === null;
    }

    /**
     * Map the card's `winner_username` back to a Stakly user via the
     * match's snapshotted handles for THE CARD'S PROVIDER. Mirrors
     * `ChessGameApi::resolveWinnerUserId` — both Lichess and chess.com
     * usernames are case-insensitive at the provider level.
     *
     * @param  array<string, mixed>  $gameCard
     */
    private function resolveWinnerFromCard(GameMatch $match, array $gameCard): ?User
    {
        $winnerUsername = $gameCard['winner_username'] ?? null;

        if (! is_string($winnerUsername) || $winnerUsername === '') {
            return null;
        }

        $provider = match ($gameCard['provider'] ?? null) {
            'lichess' => LinkedAccountProvider::Lichess,
            'chess_com' => LinkedAccountProvider::ChessCom,
            default => null,
        };

        if ($provider === null) {
            return null;
        }

        $winnerLower = strtolower($winnerUsername);

        $creatorSnap = $match->snapshotUsername(GameMatch::SIDE_CREATOR, $provider);
        if ($creatorSnap !== null && strtolower($creatorSnap) === $winnerLower) {
            return $match->listing->user;
        }

        $takerSnap = $match->snapshotUsername(GameMatch::SIDE_TAKER, $provider);
        if ($takerSnap !== null && strtolower($takerSnap) === $winnerLower) {
            return $match->taker;
        }

        return null;
    }
}
