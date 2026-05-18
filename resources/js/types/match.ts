// Frontend contract for the match domain. Backend source of truth:
// - App\Http\Resources\GameMatchResource (data shape)
// - App\Models\GameMatch (database)
// - App\Enums\MatchStatus / MatchOutcome

import type { GameId } from '@/config/games';
import type { Paginator, TimeControl } from '@/types/listings';

export type MatchStatus = 'pending' | 'disputed' | 'settled' | 'manual_review';

// `drawn` is a self-reported outcome submitted via the third button in
// `ConfirmButtons`. Both players claiming `drawn` settles as a draw —
// stakes refunded, no platform fee, `Match.winner` stays null.
export type MatchOutcome = 'won' | 'lost' | 'drawn';

export interface MatchPlayer {
    id: number;
    name: string;
    username: string;
}

export interface MatchListing {
    id: number;
    game: GameId;
    stake_amount: number;
    time_control: TimeControl[];
}

export interface Match {
    id: number;
    status: MatchStatus;
    // Platform fee rate as float (BCMath string in backend, JSONified to
    // float at the resource boundary). Frontend uses this to compute
    // pot / fee / payout for the settlement summary.
    fee_rate: number;
    listing: MatchListing;
    creator: MatchPlayer;
    taker: MatchPlayer;
    creator_confirmed_outcome: MatchOutcome | null;
    taker_confirmed_outcome: MatchOutcome | null;
    winner: MatchPlayer | null;
    settled_at: string | null;
    created_at: string | null;
}

// Chat messages on a match. Mirrors `App\Http\Resources\MessageResource` AND
// `App\Events\MessageSent::broadcastWith()` — initial-load and live-broadcast
// payloads share this shape so the component can append from either source.
export type ChatMessageType = 'text' | 'system';

export interface ChatMessage {
    id: number;
    match_id: number;
    // Null for system messages (no human author).
    user_id: number | null;
    type: ChatMessageType;
    content: string;
    // Reserved for Phase 3 (image uploads) / Phase 4 (link cards). Always
    // null for text/system messages in Phase 2.
    attachments: Record<string, unknown> | null;
    created_at: string | null;
}

export interface MatchShowProps {
    match: Match;
    messages: { data: ChatMessage[] };
}

// Filters echoed from the backend (IndexMatchesRequest::filters()) so the
// chip row can hydrate from the URL.
export interface MatchFilters {
    status: MatchStatus | null;
}

export interface MatchesIndexProps {
    matches: Paginator<Match>;
    filters: MatchFilters;
}
