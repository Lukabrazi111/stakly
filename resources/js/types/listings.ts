// Frontend contract for the listings domain. Backend source of truth:
// - App\Http\Resources\ListingResource (data shape)
// - App\Http\Requests\Listings\IndexListingsRequest (filter shape + sort list)
// - resources/js/config/games.ts (game registry)

import type { GameId } from '@/config/games';
import type { GameTile } from '@/types/home';
import type { Lobby } from './lobby';
import type { ChatMessage } from './match';

export type ListingStatus = 'open' | 'taken' | 'expired' | 'cancelled';

export type TimeControl = 'bullet' | 'blitz' | 'rapid';

export type ListingSort =
    | 'newest'
    | 'highest_stake'
    | 'lowest_stake'
    | 'ending_soon';

// M41 P2 — a creator's verified FACEIT rating. Present on CS2 listings only
// (chess listings carry `chess_rating` instead). `elo`/`level` are null +
// `is_unrated` true when the CS2 creator has no FACEIT link or no CS2 ELO yet,
// so the badge renders "Unrated". `level` is derived from ELO server-side
// (App\Support\FaceitLevel).
export interface FaceitRating {
    elo: number | null;
    level: number | null;
    is_unrated: boolean;
}

// M41 P4 — a creator's verified chess rating for a listing's platform + time
// control. The number ALWAYS shows when present; `is_provisional` (few games)
// renders a "?" marker rather than hiding it. `is_unrated` (rating null) means
// only "no rating for that time control". No level — chess providers expose an
// ELO number only.
export interface ChessRating {
    rating: number | null;
    is_provisional: boolean;
    is_unrated: boolean;
}

// M41 P7 — a single recent-match outcome (win / loss / draw) from Stakly's own
// settled matches (NOT FACEIT). CS2 never draws, so 'D' won't appear in
// practice, but the strip supports it for future 1v1 reuse.
export type RecentFormResult = 'W' | 'L' | 'D';

export interface ListingCreator {
    id: number;
    name: string;
    username: string;
    // M18 Phase 1 propagation — 128×128 avatar thumb for listing cards +
    // detail page. Null when the creator hasn't uploaded one; FE falls
    // back to gradient-initials via `useInitials()`.
    avatar_thumb_url: string | null;
    // M6 Phase 6.5 — used by the listing detail page to disable the Take
    // button + show "currently inactive" banner when false. Server-side gate
    // in `GameMatchController::take` is the authoritative enforcement.
    is_active_mode: boolean;
    // M22 Phase 1 — seller trust signals on the listing row chip.
    // `completion_rate_30d` is an integer percentage (0–100), null when the
    // creator has no engaged matches in the last 30 days (no headline to
    // show). `settled_lifetime` is the all-time settled count and drives
    // the chip's "98% · 47" denominator. Chip hides entirely when
    // `settled_lifetime === 0` (no track record).
    completion_rate_30d: number | null;
    settled_lifetime: number;
    // M22 Phase 1 (badge tier) — list of providers the creator is verified
    // on (chess.com / Lichess today; FACEIT / Riot / Steam in M15). Drives
    // the cross-platform earned badge in `SellerTrustMeta`: the green
    // `BadgeCheck` icon appears in the meta line ONLY when the creator has
    // verified on 2+ providers ("went the extra mile" credential).
    verified_providers: ChessProvider[];
    // M23 Phase 1 — detail-page creator card uplift. `bio` and `member_since`
    // mirror the profile-page hero. `linked_accounts` carries the
    // (provider, username) pairs the detail-page chip strip needs to click
    // out to each external profile — different shape from `verified_providers`
    // above (which only carries the provider id, sufficient for the badge).
    // M15 Phase 3 — widened from `ChessProvider` to `ListingPlatform` so
    // FACEIT (+ future Steam) verifications surface in the chip strip too.
    bio: string | null;
    member_since: string | null;
    linked_accounts: Array<{ provider: ListingPlatform; username: string }>;
    // M41 P2 — verified FACEIT rating; populated on CS2 listings, null for chess.
    faceit_rating: FaceitRating | null;
    // M41 P4 — verified chess rating for the listing's platform + time control;
    // populated on chess listings, null for CS2.
    chess_rating: ChessRating | null;
    // M41 P7 — recent W/L/D form (last 5 settled CS2 matches, newest first)
    // from Stakly's DB. CS2 listings only (null for chess); [] = no settled
    // matches yet. Pairs with the FACEIT level dial on CS2 cards.
    recent_form: RecentFormResult[] | null;
}

// Chess-only linked-account providers. Distinct from `ListingPlatform` below
// because a user can only "link" chess accounts today — FACEIT/Steam linking
// is M15 work. The two types overlap on `chess_com | lichess`.
export type ChessProvider = 'chess_com' | 'lichess';

// The external provider the match must be played on (M8 Phase 5 Slice B).
// Matches `App\Enums\LinkedAccountProvider` values. Taker must have THIS
// platform verified to take the listing.
// `faceit` + `steam` map to `App\Enums\LinkedAccountProvider`. `faceit` appears
// on dev-seeded CS2 + Dota 2 listings (both verify via FACEIT). `steam` is now
// vestigial — no game requires it since 2026-07-06 — kept only for legacy links.
// See backend `App\Enums\LinkedAccountProvider` for the matching cases.
export type ListingPlatform = ChessProvider | 'faceit' | 'steam';

export interface Listing {
    id: number;
    game: GameId;
    platform: ListingPlatform;
    stake_amount: number;
    // M23 Phase 2 — platform fee rate at the JSON boundary (mirrors
    // `GameMatch.fee_rate`). Frontend uses this to render the pot
    // breakdown on the detail page (pot = stake × 2, fee = pot × fee_rate,
    // winner payout = pot − fee).
    fee_rate: number;
    // The single time control this chess listing is for (M41 P3a). Null for
    // non-chess games (CS2 etc. have no time control).
    time_control: TimeControl | null;
    region: string | null;
    // Array of languages the creator speaks, or null = no restriction.
    language: string[] | null;
    expires_at: string;
    status: ListingStatus;
    created_at: string | null;
    // 1 for chess (default). > 1 for team-play listings (CS2 Wingman 2v2 / 5v5).
    team_size: number;
    // null for chess; one of 'recruiting' | 'ready_checking' | 'locked' |
    // 'cancelled' for team-play. Marketplace listings only ever appear with
    // null or 'recruiting' | 'ready_checking' (Open status filter).
    lobby_state: string | null;
    // ISO-8601 deadline for the ready-check countdown. Only non-null while
    // `lobby_state === 'ready_checking'`.
    lobby_ready_check_deadline: string | null;
    // Active lobby seats (kicked_at IS NULL). 0 for chess.
    live_participant_count: number;
    // Up to 3 live participants, ordered by `joined_at`. Powers the grid-card
    // roster avatar preview. Always present; empty for chess.
    participant_previews: Array<{
        username: string;
        name: string;
        avatar_thumb_url: string | null;
    }>;
    creator: ListingCreator;
}

export interface ListingFilters {
    game: GameId;
    stake_min: number | null;
    stake_max: number | null;
    // M41 P5: the creator's VERIFIED rating bounds, game-scoped — raw Elo for
    // chess, FACEIT level (1–10) for CS2. `unrated` (only listings with no
    // rating for the game/platform/TC) is mutually exclusive with the bounds.
    skill_min: number | null;
    skill_max: number | null;
    unrated: boolean;
    time_control: TimeControl[];
    region: string | null;
    language: string | null;
    sort: ListingSort;
}

// Standard Laravel paginator shape (when wrapped by an API Resource collection).
export interface Paginator<T> {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        path: string;
        per_page: number;
        to: number | null;
        total: number;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
}

export interface ListingsIndexProps {
    listings: Paginator<Listing>;
    filters: ListingFilters;
    sorts: ListingSort[];
    games: { data: GameTile[] };
}

// Phase 1 (M4) — props for the listing detail page. `listing` is the resource
// resolved/unwrapped (no `data` wrapper), since the controller calls
// `(new ListingResource($listing))->resolve()`.
//
// `match` is populated (M6 Phase 6) only when the listing is `taken` AND the
// viewer is a participant (creator or taker). For non-participants and
// non-taken listings the controller sends `null` — the frontend uses its
// presence as the sole gate for the "View match →" link.
//
// M34 P3.1 Slice B.1 — `lobby` + `messages` arrive only when the listing is
// team-play (`team_size > 1`). Their presence signals the page should render
// the lobby UI instead of the chess detail view. Chess listings keep the
// `match` column populated for participants; team-play listings ignore it
// in favour of `lobby.match_id`.
export interface ListingShowProps {
    listing: Listing;
    match: { id: number } | null;
    lobby?: Lobby;
    messages?: { data: ChatMessage[] };
}

// Props for the create-listing form. Option lists (regions / languages /
// durations) are passed from the backend so `StoreListingRequest`'s constants
// stay the single source of truth — frontend never duplicates them.
// `balance` is the user's current `usdt_balance` as a BCMath-safe string.
//
// `activeListingsCount` + `maxActiveListings` (M6 Phase 6.5) gate the form
// when the user is at the cap — submit button disables and a banner explains
// why. Backend re-validates via StoreListingRequest::withValidator.
export interface ListingCreateProps {
    balance: string;
    regions: string[];
    languages: string[];
    durations: number[];
    activeListingsCount: number;
    maxActiveListings: number;
    // M8 Phase 5 Slice B (widened in M15 Phase 3) — every provider the user
    // has verified. Drives the chess platform picker (shown when both chess
    // providers linked) + the per-game default-platform pick.
    linkedPlatforms: ListingPlatform[];
    // M15 Phase 3 — DB-backed game catalog (Active games). Same shape the
    // homepage GameSelector consumes; drives the in-form game-tile picker.
    games: { data: GameTile[] };
    // M15 Phase 3 — per-game gate data. `providers` = which platforms the
    // game can be posted on (chess → chess_com|lichess; cs2 → faceit).
    // `verified` = the current user has at least one of those linked. The
    // form shows an inline "Link X to post" notice when verified=false for
    // the picked game.
    requirementsByGame: Record<
        GameId,
        {
            providers: ListingPlatform[];
            verified: boolean;
            allowed_team_sizes: number[];
        }
    >;
    // M40 — platform fee rate (float, from config('stakly.platform_fee_rate'))
    // powering the live Deal summary. Single source: the same value settlement
    // uses, so the in-form payout preview can't drift from the real payout.
    feeRate: number;
    // M41 P2 — the current user's own FACEIT rating, for the create-form CS2
    // preview + the in-form "your rating" note. Null when they have no FACEIT
    // link (in which case they can't post CS2 anyway).
    userFaceitRating: FaceitRating | null;
    // M41 P4 — the current user's own chess ratings, keyed platform → time
    // control, for the chess create-form live preview. A missing platform/TC
    // (or a provisional rating) renders "Unrated".
    userChessRatings: Record<string, Record<string, ChessRating>>;
}

// Tab values for the /listings/mine page (M6 Phase 6.5).
export type ListingsMineTab = 'listed' | 'all';

// Page-level props for /listings/mine. `listings` is paginated via Spatie
// query-builder; `tab` reflects the current ?tab= value (defaults to listed);
// `activeCount` + `maxActive` drive the header count chip + Post listing
// button's at-cap disabled state.
export interface ListingsMineProps {
    listings: Paginator<Listing>;
    tab: ListingsMineTab;
    activeCount: number;
    maxActive: number;
}
