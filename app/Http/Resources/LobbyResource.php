<?php

namespace App\Http\Resources;

use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lobby page payload. Combines listing details + the roster (both teams'
 * slots, filled or empty) + viewer-derived capability flags so the React
 * page can disable / hide controls without re-computing client-side.
 *
 * `kicked_at` participants are filtered out (live rows only). Frontend
 * doesn't surface kicked players in the visible roster; the kick cooldown
 * is enforced server-side on JoinLobbyAction.
 *
 * @mixin Listing
 */
class LobbyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $live = $this->lobbyParticipants->whereNull('kicked_at');

        $viewerParticipant = $viewer === null
            ? null
            : $live->firstWhere('user_id', $viewer->id);

        return [
            'id' => $this->id,
            'game' => $this->game->value,
            'platform' => $this->platform->value,
            'stake_amount' => (float) $this->stake_amount,
            'fee_rate' => (float) config('stakly.platform_fee_rate'),
            'team_size' => $this->team_size,
            'creator_side' => $this->creator_side,
            'is_public' => (bool) $this->is_public,
            'invite_token' => $viewer?->id === $this->user_id ? $this->invite_token : null,
            'lobby_state' => $this->lobby_state,
            'lobby_ready_check_deadline' => $this->lobby_ready_check_deadline?->toIso8601String(),
            'status' => $this->status->value,
            'skill_min' => $this->skill_min,
            'skill_max' => $this->skill_max,
            'region' => $this->region,
            'language' => $this->language,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'match_id' => $this->gameMatch?->id,
            'match_status' => $this->gameMatch?->status->value,
            'match_deadline_at' => $this->matchDeadlineAt(),
            'creator' => $this->presentCreator(),
            'roster' => $this->presentRoster(),
            'viewer' => $this->presentViewer($viewer, $viewerParticipant),
            'aggregates' => $this->presentAggregates(),
        ];
    }

    /**
     * Locked-state header countdown deadline (M34 P7). The `game_matches`
     * row exists from listing creation in `LobbyFilling` status — only once
     * `LobbyLockAction` flips it to `Pending` does the 4h confirmation window
     * start ticking. Gate on `Pending` so the FE countdown lines up with the
     * cron (`ResolveMatchTimeoutAction`) that flips a timed-out match to
     * `ManualReview`.
     */
    private function matchDeadlineAt(): ?string
    {
        $match = $this->gameMatch;

        if ($match === null
            || $match->status !== MatchStatus::Pending
            || $match->created_at === null
        ) {
            return null;
        }

        $hours = (int) config('stakly.match_confirmation_timeout_hours');

        return $match->created_at->copy()->addHours($hours)->toIso8601String();
    }

    /**
     * Per-team computed view for the FACEIT-grade center column on
     * `pages/listings/show.tsx`. Money math is derived from existing fields;
     * skill aggregates come from each participant's `platform_account.skill_rating`
     * (FACEIT ELO snapshot in M15); trust aggregates come from the `seller_trust`
     * attribute that `ListingController::showTeamPlay` attaches via
     * `SellerTrust::forBatch` over every participant's user.
     *
     * Floats at the JSON boundary (display, not authoritative). Internal
     * money writes still go through Wallet's BCMath path.
     *
     * @return array<string, mixed>
     */
    private function presentAggregates(): array
    {
        $stakeAmount = (float) $this->stake_amount;
        $feeRate = (float) config('stakly.platform_fee_rate');
        $pot = $stakeAmount * $this->team_size * 2;
        $fee = $pot * $feeRate;
        $winnerTakePerPlayer = $this->team_size > 0
            ? ($pot - $fee) / $this->team_size
            : 0.0;

        $live = $this->lobbyParticipants->whereNull('kicked_at');
        $sideA = $live->where('side', 'a');
        $sideB = $live->where('side', 'b');

        $skillA = $this->skillFor($sideA);
        $skillB = $this->skillFor($sideB);

        $delta = ($skillA['avg'] !== null && $skillB['avg'] !== null)
            ? (int) abs($skillA['avg'] - $skillB['avg'])
            : null;

        $deltaTone = $delta === null
            ? null
            : ($delta <= 50 ? 'even' : ($delta <= 150 ? 'mismatched' : 'stacked'));

        return [
            'pot' => $pot,
            'fee' => $fee,
            'winner_take_per_player' => $winnerTakePerPlayer,
            'loser_loss_per_player' => $stakeAmount,
            'skill' => [
                'a' => $skillA,
                'b' => $skillB,
                'delta' => $delta,
                'delta_tone' => $deltaTone,
            ],
            'trust' => [
                'a' => $this->trustFor($sideA),
                'b' => $this->trustFor($sideB),
            ],
        ];
    }

    /**
     * @param  iterable<LobbyParticipant>  $participants
     * @return array{avg: int|null, min: int|null, max: int|null, count: int}
     */
    private function skillFor(iterable $participants): array
    {
        $ratings = [];
        foreach ($participants as $participant) {
            $rating = $this->skillRatingFor($participant);
            if ($rating !== null) {
                $ratings[] = $rating;
            }
        }

        if (count($ratings) === 0) {
            return ['avg' => null, 'min' => null, 'max' => null, 'count' => 0];
        }

        return [
            'avg' => (int) round(array_sum($ratings) / count($ratings)),
            'min' => min($ratings),
            'max' => max($ratings),
            'count' => count($ratings),
        ];
    }

    private function skillRatingFor(LobbyParticipant $participant): ?int
    {
        $link = $participant->user->linkedAccounts
            ->firstWhere('provider.value', $this->platform->value);

        return $link?->skill_rating;
    }

    /**
     * @param  iterable<LobbyParticipant>  $participants
     * @return array{avg_completion_rate: int|null, settled_lifetime_sum: int, player_count: int}
     */
    private function trustFor(iterable $participants): array
    {
        $rates = [];
        $settledSum = 0;
        $playerCount = 0;

        foreach ($participants as $participant) {
            $playerCount++;
            $trust = $participant->user->getAttribute('seller_trust')
                ?? ['rate_30d' => null, 'settled_lifetime' => 0];

            $settledSum += (int) ($trust['settled_lifetime'] ?? 0);

            if ($trust['rate_30d'] !== null) {
                $rates[] = (int) $trust['rate_30d'];
            }
        }

        return [
            'avg_completion_rate' => count($rates) > 0
                ? (int) round(array_sum($rates) / count($rates))
                : null,
            'settled_lifetime_sum' => $settledSum,
            'player_count' => $playerCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCreator(): array
    {
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'username' => $this->user->username,
            'avatar_thumb_url' => $this->user->avatar_thumb_url,
        ];
    }

    /**
     * Slot grid: 2 × team_size entries (side 'a' first, then side 'b'),
     * each either a filled-slot payload or a null placeholder. Frontend
     * renders identical-size cards for filled + empty so the lobby grid
     * doesn't reflow as players join.
     *
     * @return array<string, array<int, mixed>>
     */
    private function presentRoster(): array
    {
        // Don't `->load(...)` here — that re-fetches user models from the
        // database and wipes any `seller_trust` attribute the controller
        // attached for the trust-signals aggregator. The controller already
        // eager-loads `lobbyParticipants.user.linkedAccounts` so this
        // collection has everything we need.
        $live = $this->lobbyParticipants->whereNull('kicked_at');

        $bySide = $live->groupBy('side');

        return [
            'a' => $this->presentSide($bySide->get('a', collect())),
            'b' => $this->presentSide($bySide->get('b', collect())),
        ];
    }

    /**
     * @param  iterable<LobbyParticipant>  $participants
     * @return array<int, mixed>
     */
    private function presentSide($participants): array
    {
        $bySlot = collect($participants)->keyBy('slot_index');

        $rows = [];

        for ($slot = 0; $slot < $this->team_size; $slot++) {
            $participant = $bySlot->get($slot);

            $rows[] = $participant === null
                ? null
                : $this->presentParticipant($participant);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentParticipant(LobbyParticipant $participant): array
    {
        $platformLink = $participant->user->linkedAccounts
            ->firstWhere('provider.value', $this->platform->value);

        $stats = $participant->user->getAttribute('platform_stats');
        $trust = $participant->user->getAttribute('seller_trust');

        return [
            'participant_id' => $participant->id,
            'slot_index' => $participant->slot_index,
            'side' => $participant->side,
            'is_ready' => (bool) $participant->is_ready,
            'is_creator' => $participant->user_id === $this->user_id,
            'joined_at' => $participant->joined_at?->toIso8601String(),
            'user' => [
                'id' => $participant->user->id,
                'name' => $participant->user->name,
                'username' => $participant->user->username,
                'avatar_thumb_url' => $participant->user->avatar_thumb_url,
            ],
            'platform_account' => $platformLink === null ? null : [
                'username' => $platformLink->username,
                'skill_rating' => $platformLink->skill_rating,
            ],
            // Matches / Win rate / Completion-30d stats row on the slot card.
            // Null when the controller hasn't attached `platform_stats` (e.g.
            // in a unit test that builds the resource directly without going
            // through `ListingController::showTeamPlay`).
            'platform_stats' => $stats === null ? null : [
                'total_matches' => (int) ($stats['total_matches'] ?? 0),
                'win_rate' => $stats['win_rate'] ?? null,
                'completion_rate_30d' => $trust === null ? null : ($trust['rate_30d'] ?? null),
            ],
        ];
    }

    /**
     * Viewer-derived state — capability flags + their own slot info if
     * they're a participant. Lets the React page render the right action
     * buttons without re-deriving from the roster.
     *
     * @return array<string, mixed>|null
     */
    private function presentViewer(?User $viewer, ?LobbyParticipant $participant): ?array
    {
        if ($viewer === null) {
            return null;
        }

        $isOwner = $viewer->id === $this->user_id;
        $inLobby = $participant !== null;

        return [
            'id' => $viewer->id,
            'is_owner' => $isOwner,
            'is_participant' => $inLobby,
            'is_ready' => $inLobby ? (bool) $participant->is_ready : false,
            'side' => $participant?->side,
            'slot_index' => $participant?->slot_index,
            'can_kick' => $isOwner,
            'balance' => (float) $viewer->usdt_balance,
        ];
    }
}
