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

// Image attachment entry. Both URLs point at the authenticated streaming
// route — clients receive image bytes by following the URL, not by getting
// raw bytes inline. `thumb_url` returns the ~400px preview rendered in the
// bubble; `url` returns the original for the lightbox.
//
// `width` / `height` are the original image dimensions captured at upload
// time. When both are present, the bubble sets aspect-ratio on the <img>
// so chat history scroll doesn't shift when a run of images loads.
export interface ChatImageAttachment {
    type: 'image';
    media_id: number;
    name: string;
    mime: string;
    size: number;
    width: number | null;
    height: number | null;
    url: string;
    thumb_url: string;
}

// Slice 2 link cards — Open Graph / Twitter / oEmbed preview shaped by
// the `App\Jobs\FetchLinkMetadataJob` queued fetcher. `image_url` points
// at the authenticated `link-images.show` route; the browser pulls bytes
// from Stakly so third-party hosts never see participant IPs.
//
// Title is required (the fetcher rejects pages with no title because a
// card identical to the plain URL is noise). The rest are optional —
// thin metadata (a robots-blocked tweet, a Reddit thread with no
// description) still renders a useful card.
export interface ChatLinkAttachment {
    type: 'link';
    url: string;
    canonical_url: string | null;
    title: string;
    description: string | null;
    site_name: string | null;
    image_url: string | null;
}

export type ChatAttachment = ChatImageAttachment | ChatLinkAttachment;

export interface ChatMessage {
    id: number;
    match_id: number;
    // Null for system messages (no human author).
    user_id: number | null;
    type: ChatMessageType;
    // Null for image-only messages (a screenshot with no caption is a valid send).
    content: string | null;
    // Mixed-source list — image entries come from Spatie Media (Slice 1),
    // link entries from `attachments_json` (Slice 2). Empty array (not null)
    // when there are no attachments — matches `MessageAttachmentsPayload`.
    attachments: ChatAttachment[];
    // Echo of the client-generated UUID that the sender sent with the POST.
    // The sender's frontend matches its optimistic pending bubble to the
    // broadcast-confirmed one by this id. Always null on initial-load
    // resource serialization (the correlation only lives in the broadcast).
    correlation_id?: string | null;
    created_at: string | null;
    // Client-side only — present on locally-injected optimistic bubbles, not
    // on server-sourced messages. The broadcast handler clears `pending` and
    // `failed` when it replaces the optimistic entry; the retry handler
    // toggles `failed`. Optional + boolean so server-sourced messages stay
    // type-clean (these fields are absent on those).
    pending?: boolean;
    failed?: boolean;
    // Local-only mirror of the queued file used to render the optimistic
    // bubble while the upload is in flight. Replaced by the broadcast's
    // `attachments` entries when the server confirms.
    optimistic_file?: { name: string; preview_url: string; size: number };
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
