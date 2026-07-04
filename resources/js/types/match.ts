// Frontend contract for the match domain. Backend source of truth:
// - App\Http\Resources\GameMatchResource (data shape)
// - App\Models\GameMatch (database)
// - App\Enums\MatchStatus

import type { GameId } from '@/config/games';
import type { ListingPlatform, Paginator, TimeControl } from '@/types/listings';

export type MatchStatus =
    | 'lobby_filling'
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
    // The single chess time control (M41 P3a); null for non-chess matches.
    time_control: TimeControl | null;
    // M34 — drives the frontend branch between 1v1 chess UI (creator +
    // taker) and team-play UI (team rosters). 1 for chess, 2 for Wingman,
    // 5 for CS2 5v5.
    team_size: number;
}

// M34 P6 — one live roster entry on a team-play match. Mirrors
// `App\Http\Resources\GameMatchResource::buildRoster()`. Kicked
// participants are filtered out by the resource — frontend only sees
// the live set.
export interface TeamMatchPlayer {
    user_id: number;
    username: string;
    name: string;
    avatar_thumb_url: string | null;
    slot_index: number;
    // M34 P8 Slice A — per-player skill + trust payload powering the rich
    // roster cards on the match page. Both nullable: skill is null when
    // the linked account has no rating; platform_stats is null when the
    // controller skipped the batched aggregations (list contexts).
    skill_rating: number | null;
    platform_stats: {
        total_matches: number;
        win_rate: number | null;
        completion_rate_30d: number | null;
    } | null;
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
export interface MatchDispute {
    opened_by_id: number | null;
    opened_at: string | null;
}

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
    // API-resolution deadline (created_at + stakly.match_confirmation_timeout_hours),
    // backend-computed in GameMatchResource so the MatchTimer countdown can't
    // drift from the cron that enforces it. Null unless the match is Pending
    // (the timer only renders inside the polling window).
    match_deadline_at: string | null;
    cancellation: MatchCancellation;
    dispute: MatchDispute;
    // M34 P6 — team rosters and winning side. Present only when the
    // listing is team play AND the controller eager-loaded the lobby
    // participants (list contexts like `/matches` skip the eager-load
    // and these fields are absent from the payload). Frontend checks
    // `listing.team_size > 1 && team_a` to branch into TeamMatchView.
    team_a?: TeamMatchPlayer[];
    team_b?: TeamMatchPlayer[];
    winning_team?: 'a' | 'b' | null;
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

// Tagged on the user-authored message that carries the disputing player's
// reason (+ optional evidence file) at the moment they open the dispute.
// `ChatMessageBubble` adds a "Reason for dispute" header above the bubble
// content so opponent + admin see this is the formal claim, not just chat.
export interface ChatDisputeOpeningAttachment {
    type: 'dispute_opening';
}

// Non-image media (PDFs from dispute-opener evidence). Rendered as a
// download tile rather than an inline preview — chat-input itself is still
// image-only at the form-request layer, so this branch only fires from the
// dispute-opening flow.
export interface ChatFileAttachment {
    type: 'file';
    media_id: number;
    name: string;
    mime: string;
    size: number;
    url: string;
}

export type ChatAttachment =
    | ChatImageAttachment
    | ChatLinkAttachment
    | ChatGameCardAttachment
    | ChatDisputePromptAttachment
    | ChatDisputeOpeningAttachment
    | ChatFileAttachment;

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
    optimistic_file?: {
        name: string;
        // Object URL for image previews; null for non-image (PDF) attachments
        // which render as a file-icon tile in `OptimisticAttachment`.
        preview_url: string | null;
        size: number;
        mime: string;
    };
}

export interface MatchShowProps {
    match: Match;
    messages: { data: ChatMessage[] };
}

// Filters echoed from the backend (IndexMatchesRequest::filters()) so the
// M36: 'in_progress' (default) shows the active group; 'all' shows every
// status sliced by the chips. Mirrors Bybit's Orders → In Progress / All.
export type MatchView = 'in_progress' | 'all';

// chip row can hydrate from the URL.
export interface MatchFilters {
    view: MatchView;
    status: MatchStatus | null;
}

export interface MatchesIndexProps {
    matches: Paginator<Match>;
    filters: MatchFilters;
}
