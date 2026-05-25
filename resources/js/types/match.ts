// Frontend contract for the match domain. Backend source of truth:
// - App\Http\Resources\GameMatchResource (data shape)
// - App\Models\GameMatch (database)
// - App\Enums\MatchStatus

import type { GameId } from '@/config/games';
import type { ListingPlatform, Paginator, TimeControl } from '@/types/listings';

export type MatchStatus =
    | 'pending'
    | 'disputed'
    | 'settled'
    | 'manual_review'
    | 'cancelled';

export interface MatchPlayer {
    id: number;
    name: string;
    username: string;
    // M18 Phase 1 propagation — 128×128 avatar thumb for chat bubbles,
    // match info card, match list rows. Null when the player hasn't
    // uploaded an avatar; FE falls back to gradient-initials.
    avatar_thumb_url: string | null;
}

export interface MatchListing {
    id: number;
    game: GameId;
    stake_amount: number;
    // Platform binds outcome verification — the match auto-verifies via
    // this provider's API. Surfaced in `MatchInfoCard` as a capability
    // indicator + drives the M16 Pending action card copy.
    platform: ListingPlatform;
    time_control: TimeControl[];
}

// M16 — snapshotted external-account handles scoped to the listing's
// platform. Either side may be null if the snapshot row is missing
// (defensive — take + create gates require linked accounts upstream).
// The Pending action card uses these to tell the player which game we're
// polling for ("Looking for a game between MagnusCarlsen and hikaru").
export interface MatchSnapshots {
    creator_username: string | null;
    taker_username: string | null;
}

// M10 — mutual cancellation state. All fields nullable; the frontend
// infers the UI state from combinations:
//   - `requested_at !== null`  → open request, render the inline banner
//   - `requested_by_id === viewer.id && rejected_at` + 30 min > now
//                              → requester is in cooldown
//   - `match.status === 'cancelled'` → terminal banner
// `requested_by_id` lets the FE look up the name client-side from the
// already-loaded creator/taker — saves a backend eager-load.
export interface MatchCancellation {
    requested_by_id: number | null;
    requested_at: string | null;
    reason: string | null;
    rejected_at: string | null;
    cancelled_at: string | null;
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
    snapshots: MatchSnapshots;
    winner: MatchPlayer | null;
    settled_at: string | null;
    created_at: string | null;
    cancellation: MatchCancellation;
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

// M8 Phase 4 / 4b verified-game evidence card. Both Lichess and chess.com
// jobs emit the same shape, discriminated by `provider`. Two source paths
// produce identical structure:
//   - `source: 'paste'`      — user pasted a game URL into chat; the
//                              relevant `Fetch{Provider}GameMetadataJob`
//                              resolved it.
//   - `source: 'auto_fetch'` — `AutoFetch{Provider}GameJob` posted the
//                              card as a system message. M16 also triggers
//                              `SettleFromCardAction` immediately after
//                              this card lands.
//
// `verified: true` iff both game players' usernames matched the match's
// snapshotted handles. Auto-fetch is always verified by construction
// (search is username-anchored); paste can be either, depending on whether
// the URL belongs to a game between this match's players.
//
// `winner_color` is `null` on draw/aborted; `winner_username` mirrors that
// (null when no winner). `status` is the raw provider status — frontend
// maps it to human copy via `describeWinner` (provider-specific vocab).
export interface ChatGameCardAttachment {
    type: 'game_card';
    provider: 'lichess' | 'chess_com';
    source: 'paste' | 'auto_fetch';
    game_id: string;
    url: string;
    verified: boolean;
    white_username: string | null;
    black_username: string | null;
    winner_color: 'white' | 'black' | null;
    winner_username: string | null;
    status: string | null;
    speed: string | null;
    variant: string | null;
    rated: boolean;
    played_at: string | null;
}

// Phase 5 Slice C dispute-prompt marker. Posted by
// `ResolveDisputeAction::flipToManualReview` alongside the "submit
// evidence" system message. Carries no payload — its only job is to flip
// the system bubble into the warning-toned variant.
export interface ChatDisputePromptAttachment {
    type: 'dispute_prompt';
}

export type ChatAttachment =
    | ChatImageAttachment
    | ChatLinkAttachment
    | ChatGameCardAttachment
    | ChatDisputePromptAttachment;

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
