<?php

namespace App\Actions\GameMatch;

use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;
use App\Notifications\MatchSettledNotification;
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
        private readonly SettleTeamMatchAction $settleTeam,
    ) {}

    /**
     * @param  array<string, mixed>  $gameCard
     */
    public function handle(GameMatch $match, array $gameCard): void
    {
        $outcome = DB::transaction(function () use ($match, $gameCard) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);

            if ($locked->status !== MatchStatus::Pending) {
                return null;
            }

            $locked->load(['listing.user', 'listing.lobbyParticipants.user', 'taker', 'providerSnapshots']);

            if ($this->isDraw($gameCard)) {
                $this->settleDraw->handle($locked);

                return ['type' => 'draw'];
            }

            if ($this->isTeamPlayCard($locked, $gameCard)) {
                $winners = $this->resolveTeamWinnersFromCard($locked, $gameCard);

                if ($winners === null) {
                    Log::warning('SettleFromCardAction: team card rejected (winner roster mismatch)', [
                        'match_id' => $locked->id,
                        'winning_team' => $gameCard['winning_team'] ?? null,
                        'winner_user_ids' => $gameCard['winner_user_ids'] ?? null,
                        'provider' => $gameCard['provider'] ?? null,
                    ]);

                    return null;
                }

                $this->settleTeam->handle($locked, $winners);

                return ['type' => 'team_win', 'winners' => $winners];
            }

            $winner = $this->resolveWinnerFromCard($locked, $gameCard);

            if ($winner === null) {
                Log::warning('SettleFromCardAction: card winner not in snapshots', [
                    'match_id' => $locked->id,
                    'winner_username' => $gameCard['winner_username'] ?? null,
                    'provider' => $gameCard['provider'] ?? null,
                ]);

                return null;
            }

            $this->settle->handle($locked, $winner);

            return ['type' => 'win', 'winner' => $winner];
        });

        if ($outcome === null) {
            return;
        }

        $fresh = $match->fresh(['listing.user', 'listing.lobbyParticipants.user', 'taker']);

        if ($outcome['type'] === 'draw') {
            $this->notifyBothOfDraw($fresh);
        } elseif ($outcome['type'] === 'team_win') {
            $this->notifyTeamWinLoss($fresh, $outcome['winners']);
        } else {
            $this->notifyWinLoss($fresh, $outcome['winner']);
        }
    }

    private function notifyBothOfDraw(GameMatch $match): void
    {
        $notification = new MatchSettledNotification($match, 'draw');
        $match->listing->user->notify($notification);
        $match->taker->notify($notification);
    }

    private function notifyWinLoss(GameMatch $match, User $winner): void
    {
        $payout = SettleMatchAction::computeWinnerPayout((string) $match->listing->stake_amount);
        $loser = $winner->id === $match->listing->user_id ? $match->taker : $match->listing->user;

        $winner->notify(new MatchSettledNotification($match, 'won', $payout));
        $loser->notify(new MatchSettledNotification($match, 'lost', '0'));
    }

    /**
     * Fan out per-user MatchSettledNotification across the full team roster
     * — every live participant gets either a 'won' or 'lost' notification.
     * Payout amount on the 'won' notification is the per-player share
     * (slot-0 gets the truncation remainder via SettleTeamMatchAction; the
     * displayed amount here is the base perPlayer to keep the notification
     * copy uniform across the team).
     *
     * @param  list<User>  $winners
     */
    private function notifyTeamWinLoss(GameMatch $match, array $winners): void
    {
        $teamSize = $match->listing->team_size;
        $stake = (string) $match->listing->stake_amount;
        $totalPlayers = (string) ($teamSize * 2);
        $pot = bcmul($stake, $totalPlayers, 6);
        $feeRate = (string) config('stakly.platform_fee_rate');
        $fee = bcmul($pot, $feeRate, 6);
        $winnings = bcsub($pot, $fee, 6);
        $perPlayer = bcdiv($winnings, (string) $teamSize, 6);

        $winnerIds = array_map(fn (User $u) => $u->id, $winners);

        foreach ($winners as $winner) {
            $winner->notify(new MatchSettledNotification($match, 'won', $perPlayer));
        }

        foreach ($match->listing->lobbyParticipants->whereNull('kicked_at') as $participant) {
            if (in_array((int) $participant->user_id, $winnerIds, true)) {
                continue;
            }
            $participant->user->notify(new MatchSettledNotification($match, 'lost', '0'));
        }
    }

    /**
     * Draw rule per provider:
     *   - Chess (lichess / chess_com): `winner_color = null` — set when the
     *     game ended without a winner (agreed, stalemate, 50-move, etc.).
     *   - FACEIT (M15 P4): `winner_user_id = null` — the auto-fetch job
     *     embeds the resolved Stakly user_id directly on the card, so no
     *     winner means no resolution (draw).
     *
     * @param  array<string, mixed>  $gameCard
     */
    private function isDraw(array $gameCard): bool
    {
        if (($gameCard['provider'] ?? null) === 'faceit') {
            return ! isset($gameCard['winner_user_id']) || $gameCard['winner_user_id'] === null;
        }

        return ($gameCard['winner_color'] ?? null) === null;
    }

    /**
     * Team-play card detection — the auto-fetch job ships both the legacy
     * 1v1 fields AND the new team fields on every FACEIT card. We branch
     * on the listing's `team_size > 1` (the source of truth) AND the
     * presence of the team-shaped fields. Either alone isn't enough:
     *   - team_size > 1 alone could fire on a malformed card lacking the
     *     team fields (defensive — would fall through to the 1v1 resolver
     *     and almost certainly mis-pay).
     *   - team fields alone could fire on a hand-injected card against a
     *     1v1 listing (would attempt a fan-out the listing can't sustain).
     *
     * @param  array<string, mixed>  $gameCard
     */
    private function isTeamPlayCard(GameMatch $match, array $gameCard): bool
    {
        if ($match->listing->team_size <= 1) {
            return false;
        }

        $winningTeam = $gameCard['winning_team'] ?? null;
        $winnerUserIds = $gameCard['winner_user_ids'] ?? null;

        return is_string($winningTeam)
            && is_array($winnerUserIds)
            && count($winnerUserIds) > 0;
    }

    /**
     * Resolve a team-play card to the list of winner User models, with a
     * defensive roster check against the live lobby:
     *
     *   1. Every `winner_user_ids` member must be a live participant of
     *      this listing's lobby.
     *   2. Every `winner_user_ids` member must be on the `winning_team` side
     *      of the lobby (no cross-team payouts via malformed card).
     *   3. The count must match `listing.team_size` exactly.
     *
     * Returns null on any failure so the caller can log + no-op. Match
     * stays Pending; admin / dispute path takes over.
     *
     * @param  array<string, mixed>  $gameCard
     * @return list<User>|null
     */
    private function resolveTeamWinnersFromCard(GameMatch $match, array $gameCard): ?array
    {
        $winningTeam = $gameCard['winning_team'] ?? null;
        $winnerUserIds = $gameCard['winner_user_ids'] ?? null;

        if (! is_string($winningTeam) || ! is_array($winnerUserIds)) {
            return null;
        }

        $winnerUserIds = array_values(array_unique(array_map('intval', $winnerUserIds)));

        if (count($winnerUserIds) !== $match->listing->team_size) {
            return null;
        }

        $winningSideParticipants = $match->listing->lobbyParticipants
            ->whereNull('kicked_at')
            ->where('side', $winningTeam);

        $sideUserIds = $winningSideParticipants
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Strict containment: card's winners must be EXACTLY the live
        // members of the winning side. Subset / superset / any cross-team
        // overlap → reject.
        sort($winnerUserIds);
        sort($sideUserIds);
        if ($winnerUserIds !== $sideUserIds) {
            return null;
        }

        // Build the User list ordered by slot_index ascending — the team
        // settle action gives slot 0 the truncation remainder, so the
        // order matters.
        $orderedParticipants = $winningSideParticipants->sortBy('slot_index');

        return $orderedParticipants
            ->map(fn ($participant) => $participant->user)
            ->values()
            ->all();
    }

    /**
     * Resolve the card's winner to a Stakly user. Two paths:
     *
     *   - FACEIT (M15 P4): card carries `winner_user_id` resolved at job
     *     time. Direct lookup, with a defensive participant check — refuse
     *     any id that isn't the listing creator or the match taker
     *     (guards against a malformed card forging a foreign winner).
     *
     *   - Chess (M8 / M14): card carries `winner_username`, resolved via
     *     the match's snapshotted handle for the card's provider. Both
     *     Lichess and chess.com handles are case-insensitive.
     *
     * @param  array<string, mixed>  $gameCard
     */
    private function resolveWinnerFromCard(GameMatch $match, array $gameCard): ?User
    {
        $winnerUserId = $gameCard['winner_user_id'] ?? null;

        if (is_int($winnerUserId)) {
            if ($winnerUserId === $match->listing->user_id) {
                return $match->listing->user;
            }

            if ($winnerUserId === $match->taker_user_id) {
                return $match->taker;
            }

            return null;
        }

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
