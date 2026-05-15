// Frontend contract for the match domain. Backend source of truth:
// - App\Http\Resources\GameMatchResource (data shape)
// - App\Models\GameMatch (database)
// - App\Enums\MatchStatus / MatchOutcome

import type { GameId } from '@/config/games';
import type { TimeControl } from '@/types/listings';

export type MatchStatus = 'pending' | 'disputed' | 'settled' | 'manual_review';

export type MatchOutcome = 'won' | 'lost';

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

export interface MatchShowProps {
    match: Match;
}
