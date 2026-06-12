// Frontend contract for the M34 lobby domain. Backend source of truth:
// - App\Http\Resources\LobbyResource (data shape)
// - App\Models\Listing, LobbyParticipant
// - App\Enums\Game, LinkedAccountProvider, ListingStatus

import type { GameId } from '@/config/games';
import type { ListingPlatform } from '@/types/listings';

export type LobbySide = 'a' | 'b';

export type LobbyState =
    | 'recruiting'
    | 'ready_checking'
    | 'locked'
    | 'cancelled'
    | 'expired';

export interface LobbyParticipantPayload {
    participant_id: number;
    slot_index: number;
    side: LobbySide;
    is_ready: boolean;
    is_creator: boolean;
    joined_at: string | null;
    user: {
        id: number;
        name: string;
        username: string;
        avatar_thumb_url: string | null;
    };
    platform_account: {
        username: string;
        skill_rating: number | null;
    } | null;
}

export interface LobbyRoster {
    a: Array<LobbyParticipantPayload | null>;
    b: Array<LobbyParticipantPayload | null>;
}

export interface LobbyViewer {
    id: number;
    is_owner: boolean;
    is_participant: boolean;
    is_ready: boolean;
    side: LobbySide | null;
    slot_index: number | null;
    can_kick: boolean;
    balance: number;
}

export interface Lobby {
    id: number;
    game: GameId;
    platform: ListingPlatform;
    stake_amount: number;
    fee_rate: number;
    team_size: number;
    creator_side: LobbySide | null;
    is_public: boolean;
    /** 32-char invite token — exposed only to the listing owner. */
    invite_token: string | null;
    lobby_state: LobbyState | null;
    lobby_ready_check_deadline: string | null;
    status: 'open' | 'taken' | 'expired' | 'cancelled';
    skill_min: number | null;
    skill_max: number | null;
    region: string | null;
    language: string[] | null;
    expires_at: string;
    created_at: string | null;
    match_id: number | null;
    creator: {
        id: number;
        name: string;
        username: string;
        avatar_thumb_url: string | null;
    };
    roster: LobbyRoster;
    /** Null for unauthenticated visitors browsing a public lobby. */
    viewer: LobbyViewer | null;
}

export interface LobbyShowProps {
    lobby: Lobby;
    messages: {
        data: import('./match').ChatMessage[];
    };
}
