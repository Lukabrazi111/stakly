<?php

namespace App\Http\Resources;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a match. Whitelist only — never leak PII from either
 * player's `User` record (email, two-factor secret, balance, tron address).
 *
 * Authorization to even reach this resource is enforced upstream
 * (`GameMatchPolicy::view`); this resource trusts the caller has access.
 *
 * 1v1 chess — `creator` + `taker` carry the two participants; `team_a` /
 * `team_b` / `winning_team` are omitted (frontend branches on
 * `listing.team_size === 1`).
 *
 * Team play (M34 P6) — `team_a` + `team_b` carry the live lobby rosters
 * keyed by `slot_index`; `winning_team` resolves once the match is
 * Settled. `taker` is still emitted (= creator, per
 * `CreateTeamPlayListingAction`'s NOT-NULL workaround) for back-compat
 * but is meaningless on the team-play path. Rosters require
 * `lobbyParticipants.user.media` to be eager-loaded in the calling
 * controller — `mergeWhen` short-circuits when the relation isn't
 * loaded so list contexts (e.g. `/matches`) aren't forced to pay the
 * eager-load cost on every row.
 *
 * @mixin GameMatch
 */
class GameMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            // Float at the JSON boundary (BCMath string internally).
            // Frontend computes pot / fee / payout from stake_amount +
            // fee_rate — single source of truth for settlement math.
            'fee_rate' => (float) config('stakly.platform_fee_rate'),
            'listing' => [
                'id' => $this->listing->id,
                'game' => $this->listing->game->value,
                'stake_amount' => (float) $this->listing->stake_amount,
                // Platform binds outcome verification — a Lichess listing
                // is auto-verified via Lichess, a chess.com listing via
                // chess.com.
                'platform' => $this->listing->platform->value,
                'time_control' => $this->listing->time_control
                    ->map(fn ($tc) => $tc->value)
                    ->values()
                    ->all(),
                // Drives the frontend branch between 1v1 chess UI
                // (creator/taker) and team-play UI (rosters).
                'team_size' => $this->listing->team_size,
            ],
            'creator' => [
                'id' => $this->listing->user->id,
                'name' => $this->listing->user->name,
                'username' => $this->listing->user->username,
                // 128×128 thumb; null until upload.
                'avatar_thumb_url' => $this->listing->user->avatar_thumb_url,
            ],
            'taker' => [
                'id' => $this->taker->id,
                'name' => $this->taker->name,
                'username' => $this->taker->username,
                'avatar_thumb_url' => $this->taker->avatar_thumb_url,
            ],
            // Snapshotted external-account handles scoped to the listing's
            // platform. Either side may be null — take + create gates
            // prevent unlinked matches in practice, but FE handles null
            // defensively.
            'snapshots' => [
                'creator_username' => $this->snapshotUsername(
                    GameMatch::SIDE_CREATOR,
                    $this->listing->platform,
                ),
                'taker_username' => $this->snapshotUsername(
                    GameMatch::SIDE_TAKER,
                    $this->listing->platform,
                ),
            ],
            'winner' => $this->winner ? [
                'id' => $this->winner->id,
                'name' => $this->winner->name,
                'username' => $this->winner->username,
                'avatar_thumb_url' => $this->winner->avatar_thumb_url,
            ] : null,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Mutual cancellation state. FE infers open-request / cooldown
            // / terminal banner from the combination. `requested_by_id`
            // is enough — FE looks up the name from creator / taker
            // (already loaded), avoiding a requester eager-load.
            'cancellation' => [
                'requested_by_id' => $this->cancellation_requested_by,
                'requested_at' => $this->cancellation_requested_at?->toIso8601String(),
                'reason' => $this->cancellation_reason,
                'rejected_at' => $this->cancellation_rejected_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
            // Dispute opener — drives viewer-aware copy on the Disputed
            // banner ("you opened it" vs "{opponent} opened it").
            'dispute' => [
                'opened_by_id' => $this->dispute_opened_by,
                'opened_at' => $this->dispute_opened_at?->toIso8601String(),
            ],
            // Team-play rosters — present only when the listing is team
            // play AND the controller eager-loaded the relation. List
            // contexts that don't need rosters skip the eager-load and
            // omit these fields entirely.
            ...$this->teamRosterFields(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teamRosterFields(): array
    {
        if (! $this->listing->isTeamPlay()) {
            return [];
        }

        if (! $this->listing->relationLoaded('lobbyParticipants')) {
            return [];
        }

        return [
            'team_a' => $this->buildRoster(LobbyParticipant::SIDE_A),
            'team_b' => $this->buildRoster(LobbyParticipant::SIDE_B),
            'winning_team' => $this->resolveWinningTeam(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRoster(string $side): array
    {
        return $this->listing->lobbyParticipants
            ->filter(fn (LobbyParticipant $p) => $p->kicked_at === null && $p->side === $side)
            ->sortBy('slot_index')
            ->values()
            ->map(fn (LobbyParticipant $p) => [
                'user_id' => $p->user_id,
                'username' => $p->user->username,
                'name' => $p->user->name,
                'avatar_thumb_url' => $p->user->avatar_thumb_url,
                'slot_index' => (int) $p->slot_index,
                // M34 P8 Slice A — per-player trust + skill payload powering
                // the rich roster cards on the match page. Mirrors the
                // lobby's slot-card stats line. Nullable: controller may
                // skip the batched aggregations on hot list-context calls,
                // in which case the attributes are absent and we ship null.
                'skill_rating' => $this->skillRatingFor($p->user),
                'platform_stats' => $this->platformStatsFor($p->user),
            ])
            ->all();
    }

    /**
     * Snapshot of the user's skill rating on the listing's platform. Null
     * when the linked account isn't loaded or rating isn't populated
     * (chess providers don't snapshot ratings yet).
     */
    private function skillRatingFor(User $user): ?int
    {
        if (! $user->relationLoaded('linkedAccounts')) {
            return null;
        }

        $link = $user->linkedAccounts
            ->firstWhere('provider.value', $this->listing->platform->value);

        return $link?->skill_rating;
    }

    /**
     * Trust + match-history aggregates attached by the controller via
     * `SellerTrust::forBatch` + `ParticipantStats::forBatch`. Null when
     * the controller didn't batch (list contexts), so the FE renders a
     * "No matches yet" placeholder rather than crashing.
     *
     * @return array{total_matches: int, win_rate: int|null, completion_rate_30d: int|null}|null
     */
    private function platformStatsFor(User $user): ?array
    {
        $stats = $user->getAttribute('platform_stats');
        $trust = $user->getAttribute('seller_trust');

        if ($stats === null) {
            return null;
        }

        return [
            'total_matches' => (int) ($stats['total_matches'] ?? 0),
            'win_rate' => $stats['win_rate'] ?? null,
            'completion_rate_30d' => $trust === null
                ? null
                : ($trust['rate_30d'] ?? null),
        ];
    }

    /**
     * Returns the winning side ('a' or 'b') once the match is Settled,
     * derived by looking up the side of the `winner_user_id` in the
     * already-eager-loaded `lobbyParticipants` collection. Returns null
     * for non-Settled matches or when the winner isn't on the roster
     * (defensive — shouldn't happen since SettleTeamMatchAction picks
     * the winner from the live roster).
     */
    private function resolveWinningTeam(): ?string
    {
        if ($this->status !== MatchStatus::Settled || $this->winner_user_id === null) {
            return null;
        }

        $winnerRow = $this->listing->lobbyParticipants
            ->firstWhere('user_id', $this->winner_user_id);

        return $winnerRow?->side;
    }
}
