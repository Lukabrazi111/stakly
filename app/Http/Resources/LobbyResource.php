<?php

namespace App\Http\Resources;

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
            'creator' => $this->presentCreator(),
            'roster' => $this->presentRoster(),
            'viewer' => $this->presentViewer($viewer, $viewerParticipant),
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
        $live = $this->lobbyParticipants
            ->whereNull('kicked_at')
            ->load('user.linkedAccounts');

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
