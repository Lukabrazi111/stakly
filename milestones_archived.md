# Stakly Milestones — Archive

Shipped milestones. One-paragraph summary per milestone (the code is the source of truth for "how it works") + a `Decisions` block for anything architecturally load-bearing. Active + upcoming work lives in `milestones.md` alongside the cross-cutting `Architectural decisions` section.

Entries are kept in roughly shipping order (M1 → M7 predate the date-stamping convention; M11 onward carry explicit ship dates).

---

## Shipped milestones (M1 – M11)

### M1 — Design Foundation + Homepage ✅

Stakly's dark + pink/purple gradient visual system shipped: design tokens, Bricolage + Inter fonts, gradient `Button` variant + `pill` size, `MarqueeStrip`, `SiteHeader`, `SiteFooter`, `SiteLayout`, `MobileMenu`. Homepage renders Hero (typography-only, no character art), `GameSelector` (chess + 8 "Soon" tiles), `HowItWorks`. **Decisions**: dark-only design; Stakly-skin shadcn primitives at the source (`components/ui/<name>.tsx`), never per-usage; pink hover at `bg-primary/10`, focus ring at `ring-2 ring-primary/25`.

### M2 — Auth Flow ✅

Modal-only auth (`?auth=*` URL-driven), page-only destinations for reset/2FA/confirm, email verification via `MustVerifyEmail`, `ProfileMenu` + mobile account card, Inertia v3 flash + Stakly-styled Sonner toasts, custom Fortify response bindings (register / verify / resend / forgot-password / password-reset → `/?auth=login` with toast), reset-token guard, `ThrottleVerificationSend` (1/min).

### M2.5 — Pre-M3 polish ✅

Settings rendered inside `SiteLayout` with inline pill-tabs sub-nav (Profile / Security / Appearance). Starter-kit shell (`AppLayout` / `AppShell` / `AppSidebar` / `Breadcrumbs` etc.) deleted. `AuthModalProvider` no longer flashes the modal at logged-in users + strips stale `?auth=*` query.

### M3 — Listings Index ✅

Public marketplace `/listings`: filterable / sortable / paginated grid (12/page, server-controlled). Featured strip on `/` shows top 4 ending-soon. PII-safe `ListingResource`. Bybit-inspired filter bar + popover/sheet via `useIsMobile()`. Smart-ellipsis pagination with `Skeleton` loading rows. **Decisions**: project-wide URL contract via Spatie query-builder (`?filter[stake_max]=100&sort=ending_soon&page=2`); `$redirect = '/listings'` for graceful share-link UX; `lib/listings-query.ts` centralizes URL building.

### M3.5 — Wallet / Ledger Foundation ✅

Append-only Postgres ledger (`wallet_transactions`) is the source of truth for every USDT balance change. `App\Services\Wallet` exposes 6 methods (`deposit`, `withdraw`, `hold`, `release`, `payout`, `fee`) plus `balanceFor`, all funnelling through a private `record()` that wraps `DB::transaction(...)` + `lockForUpdate()` on the user row. Idempotency via optional `reference_id`. BCMath strings throughout (scale 6, matching Tron USDT precision). Platform rake credits the seeded `is_platform = true` user.

### M4 — Listing Detail + Create Flow ✅

First end-to-end money flow. Public listing detail (`/listings/{id}`), auth-gated create form, owner-only cancel — wired to real `Wallet::hold` on create, `Wallet::release` on cancel, both transactional + idempotent. Multi-select `time_control` and `language` (jsonb + `AsEnumCollection` + `whereJsonContains`). Owner-only `App\Policies\ListingPolicy::cancel`. **Decisions**: duration dropdown (not datetime picker); insufficient-balance validated twice (request + `Wallet::hold` exception); detail page renders for any status (no 404 on stale share links); stake precision pinned at `decimal:0,2`.

### M5 — User Profile ✅

Public read-only profiles at `/users/{username}`. `username` + `bio` columns, `UserController` + `UserProfileResource` (whitelist, no PII), 5 profile components. Auto-generated usernames via `Str::slug($name)` + collision-safe suffix loop. Reserved-username list (`'user'` reserved). Strict ASCII Latin name validation. **Decisions**: `username` derived at registration, immutable for now (revisit if there's a user need); `is_platform = true` users 404 on profile show.

### M6 — Match Flow (mock) ✅

The missing core loop: take listing → match created → both players play off-platform → return to confirm outcome → money settles.

**State machine** (post-M16 — the player-confirm paths in the original M6 design were removed when M16 landed):

```
Listing.Open --[take]--> Match.Pending, Listing.Taken (taker's Wallet::hold)
Match.Pending --[auto-fetched card has winner]--> Match.Settled (via SettleFromCardAction — M16)
Match.Pending --[auto-fetched card is a draw]--> Match.Settled (refund both, no fee — M16)
Match.Pending --[either opens dispute]--> Match.Disputed
Match.Pending --[4h passes with no card]--> Match.ManualReview (M16 — terminal, admin resolves)
Match.Pending --[one requests cancel, opponent accepts]--> Match.Cancelled (refund both, no fee — M10)
Match.Pending --[one requests cancel, opponent rejects]--> Match.Pending (30min cooldown on requester — M10)
Match.Disputed --[game-API returns winner]--> Match.Settled
Match.Disputed --[game-API can't determine]--> Match.ManualReview (terminal — admin resolves out-of-band)
```

> The original M6 design had player Won/Lost/Drawn confirm buttons as the primary settlement signal, with the game API as the tiebreaker on disagreement. M16 inverted that: the API is the *only* settlement signal during Pending; the player buttons were removed entirely. "Report a problem" survives as the manual dispute escalation, and "Request cancellation" remains as the cooperative early exit.

Phases 1–7 shipped: schema + policies, take + match creation, confirm UI + settlement + Inertia polling, mock game-API dispute path, listings/profile/wallet integration, listings management + Active Mode + scoped Player Hub sidebar, `Drawn` outcome, timeout resolver.

**Decisions**: 10% platform fee (`config/stakly.php` `platform_fee_rate`); 4h confirmation timeout; 1:1 listing→match; participant-only match visibility; single-confirmer rule honors the claim (Won → confirmer wins, Lost → opponent wins, Drawn → game-API arbitrates); platform does NOT pocket stakes on no-show; `MatchSettlement` service deleted in M11 — settlement lives in `app/Actions/GameMatch/`.

### M7 — Wallet UI ✅

Four pages: `/wallet` (hero balance + 3 action cards + recent activity), `/wallet/deposit` (TRC20 mock address + QR + network warning), `/wallet/withdraw` (validating form, short-circuited POST), `/wallet/history` (filter chips + paginated rows). `BalanceChip` in `SiteHeader` desktop + inline balance in `MobileMenu`. `users.tron_address` (varchar 34 unique) generated at registration via `App\Support\MockTronAddress`. **Decisions**: multi-page (not tabbed); spendable balance only in UI (held derivable from ledger); mock TRC20 addresses until M9; withdrawal short-circuits with launch-gated toast (no ledger write); `abort_if($user->is_platform, 403)` on every wallet method.

### M11 — Controller Refactor to Actions Pattern ✅ (shipped 2026-05-17)

Business logic moved from controllers + commands into `app/Actions/<Domain>/` classes. Listing: `Create`, `Cancel`, `Expire`. GameMatch: `TakeListing`, `ConfirmOutcome`, `OpenDispute`, `SettleMatch`, `SettleDrawMatch`, `ResolveDispute`, `ResolveMatchTimeout`. Controllers + artisan commands shrink to thin HTTP/CLI adapters with method-injection. Old `App\Services\MatchSettlement` deleted. **Decisions**: plain PHP classes, no package, no Repositories; `handle()` method; method-injection; Wallet + GameApi stay as primitives; pure queries (read-only index/show) stay in controllers.

---

## M8 — Match Chat + Linked Accounts ✅

The architectural keystone for multi-game support. Stakly is multi-game by vision (chess now, Dota 2 / CS2 / others later). API-only outcome verification locks us to games with good APIs. **Chat with structured dispute evidence works universally** — admin reads chat + uploaded evidence and decides, with API verification appearing as an *enriched evidence card* when a player pastes a supported game URL. Linked accounts power both the chat enrichment (M8 Phase 4 + 4b) and the eventual full automated adapters (M14).

The API is no longer the primary truth source — it's a smart link-previewer inside chat. Players coordinate and resolve via chat; admin moderates; API verification is an *accelerator*, not a *requirement*.

### Key parameters (defaults — adjust before relevant phase)

- **Real-time**: Laravel Reverb (free, self-hosted, Redis-backed). Pusher swap is a `.env` change later if needed.
- **Bio-code TTL**: 15 min. **Verify rate limit**: 6/min per user.
- **Chat retention**: forever (auditable for disputes). Indexed by `match_id`.
- **File upload**: 5MB max, image-only (screenshots). Videos hosted externally + posted as links.
- **Chat message rate limit**: 10 messages / 10s per user. Upload: 5/30s.
- **Per-game API capability**: indicator on match page ("Outcome can be auto-verified via Lichess" vs "Manual review only").

### Phases

> Estimates are focused solo dev time, not calendar time. Each phase ships something usable; commit per phase.

**Phase 1 — Linked accounts foundation (chess.com + Lichess)** ✅ shipped 2026-05-18

Bio-code paste verification for both providers in one slice. Schema: `users.{provider}_username` + `{provider}_verified_at` + `pending_verification_{provider, username, code, expires_at}`. Actions: `RequestLinkVerificationAction` (generates 16-char code) + `VerifyLinkedAccountAction` (calls provider profile API, matches code, marks verified). Services: `ChessComProfileClient` + `LichessProfileClient` (`Http::fake()`-able, chess.com needs UA header). Settings tab `/settings/linked-accounts` + `LinkedAccountsSection` on profile. Throttle middleware (`throttle:6,1`).

**Decisions**: bio-code target field is chess.com `location` (free-text, public, less disruptive than the player's `name` which is their lobby identity) + Lichess `profile.bio`. Both providers from day one — symmetric flow + UI costs almost nothing for the second. Verification is immutable until unlinked.

**Phase 2 — Reverb infrastructure + chat schema + text chat + lifecycle system messages** ✅ shipped 2026-05-18

Reverb (port 8080) + dedicated queue service in `compose.yaml` so `ShouldBroadcast` events actually reach Reverb (a `database` queue without a worker would stall in `jobs`). Composer adds `laravel/reverb`; npm adds `@laravel/echo-react` + `pusher-js` (React hooks for auto-cleanup). New `messages` table with composite `(match_id, id)` index, `attachments_json` jsonb reserved for Phase 3/4, immutable (no `updated_at`). `SendMessageAction` enforces 10/10s rate + 2000-char cap + status gate (Settled/ManualReview reject sends, Disputed allows). `MessageSent` event (`ShouldBroadcast` + `ShouldDispatchAfterCommit`, `broadcastAs(): 'message.sent'`). Channel auth via named `App\Broadcasting\MatchChannel::join` for direct unit testing.

Match page redesigned around a right-side `MatchChatPanel` (sticky `h-[600px]` on desktop, bottom-sheet on mobile via `MobileChatTrigger`). `useMatchChat` hook is a single Echo subscription serving both renders. `MatchInfoCard` (Bybit-style key:value) replaces the old separate opponent + details cards; `MatchTimestamps` strip; `MatchFaq` (6 Q&As via shadcn Accordion).

Lifecycle system messages (same slice): `PostSystemMessageAction` (`type = system`, `user_id = null`, hard-coded — un-impersonatable) wired into 7 lifecycle Actions narrating state changes ("Match started", ":name confirmed: :outcome", "Match settled. :name wins $X", etc.). Same `MessageSent` broadcast as user messages.

**Decisions**: Reverb over Pusher; private channel scope (only the two participants subscribe; admin reads via Filament M12); chat locks read-only after `Settled`/`ManualReview` (Pending/Disputed keep chat open); messages are immutable (dispute review depends on truthful logs); Enter sends, Shift+Enter inserts newline; 2000-char content cap covers regular chat + Phase 5 PGN paste evidence.

**Phase 3 Slice 1 — Image attachments** ✅ shipped 2026-05-19

Spatie Media Library on `App\Models\Message` (`match-attachments` collection, private `local` disk, ~400px contain-fit `thumb` conversion with `keepOriginalImageFormat`). `StoreMessageRequest` extended for image-or-caption-or-both. `SendMessageAction` pre-processes via `Spatie\Image\Image::load()->save()` to strip EXIF, attaches via Media Library inside the message-creation transaction. Second sliding-window rate-limit bucket: uploads 5/30s (text bucket unchanged at 10/10s). Authenticated streaming route `GET /matches/{match}/messages/{message}/attachments/{media}` (`?conversion=thumb` for preview, plain for original) re-checks the `view` policy. `App\Support\MessageAttachmentsPayload::forMessage` is the single source of truth for the `attachments` array shape consumed by both `MessageResource` (initial load) and `MessageSent::broadcastWith` (live).

Same-day polish pass: optimistic UI with `correlation_id` round-trip + retry/dismiss on failure; paste-to-upload in the chat textarea; image `width`/`height` in payload so the browser reserves the box pre-load (no scroll-shift).

React: paperclip + drag-drop overlay on `ChatPanel`, preview strip with progress, thumbnail in `ChatMessageBubble` opening a shadcn `Dialog` lightbox.

**Decisions**: single multipart endpoint (not two-step upload-then-reference); Spatie Media over raw Storage; private disk + auth-streamed route (not public disk + UUID paths — money platform's chat is sometimes dispute evidence); EXIF stripped on upload; one attachment per message; image-only (PDFs/videos rejected); 5MB cap.

**Phase 3 Slice 2 — Plain link cards** ✅ shipped 2026-05-19

Slack/Discord-style URL unfurling. `SendMessageAction::extractLinkUrls` extracts http(s) URLs (trims trailing punctuation, dedups, caps at 5, cheap pre-flight `SsrfGuard::isPlausiblySafe` with no DNS). `FetchLinkMetadataJob` (`ShouldQueueAfterCommit`, single try) per-URL `SsrfGuard::isUrlSafe` (DNS-resolved), oscarotero/embed extraction through `App\Support\SafeHttpClient` (PSR-18 wrapper around CurlClient — disables curl-level redirects, walks the chain manually with per-hop SSRF check, strips Authorization/Cookie on cross-host hops, 3-hop cap). OG images proxied via a separate manual-redirect fetch + size cap + MIME whitelist + Spatie\Image EXIF strip, stored at `link-images/{sha256}.{ext}` on the private `local` disk. `LinkImageController` + `/link-images/{filename}` route (`[a-f0-9]{64}\.(jpg|png|webp|gif)` regex rejects path traversal). `useMatchChat` dedup-by-id → replace-by-id so re-broadcast swaps the bubble in place (no scroll, no reorder). `LinkCard` React component for the unfurl.

**Decisions**: proxy images (third-party hosts never see participant IPs, EXIF stripped); any http(s) URL gets unfurled (SSRF is the real defense, not an allowlist); per-hop SSRF (library follows curl-level redirects); split `SsrfGuard::isPlausiblySafe` (no DNS, request-path) vs `isUrlSafe` (DNS-resolved, worker-path); cache per URL for 1h; single try, fail silently.

**Phase 4 — Smart link enrichment for Lichess (hybrid paste + auto-fetch)** ✅ shipped 2026-05-22

Lichess games surface as evidence cards in chat via two complementary paths:

- **Manual paste**: a player pastes a Lichess game URL. `FetchLichessGameMetadataJob` fetches by ID, cross-checks both player usernames against the match snapshot (case-insensitive, order-independent), renders verified or unverified card. Unverified URLs still render — the chat sees the game existed, the missing badge says "we can't confirm this is between the two match players."
- **Auto-fetch on first confirm**: `ConfirmOutcomeAction` captures the zero→one confirm transition; `AutoFetchLichessGameJob` queries Lichess for recent games between snapshotted usernames since `match.created_at`. If EXACTLY one decisive game (mate/resign/outoftime/timeout/cheat), posts a verified card via `PostSystemMessageAction`. Zero or multiple candidates → silent skip (let the paste path cover ambiguous cases).

`LichessGameClient` (`fetchGame`, `searchGamesBetween` — ndjson) is `Http::fake()`-able. URL detection via `SendMessageAction::extractLichessGameId` (regex with `~` delimiter; explicit denylist for reserved Lichess paths in the 8-12 char window: `training`/`analysis`/`streamer`/`practice`/`tournament`). New sibling `match_provider_snapshots` table (one row per match × side × provider; UNIQUE `(match_id, side, provider)`, index `(provider, username)`) — replaces the originally-planned flat columns on `game_matches` so multi-identifier games (CS2 Steam+Faceit, Riot ID + region) drop in cleanly at M15 without re-migrating the wide table. `TakeListingAction::snapshotProviderAccounts` batch-inserts rows for each verified provider per side. `PostSystemMessageAction` extended with optional `?array $attachments` so auto-fetch posts text + card in one call. `MessageAttachmentsPayload::gameCardEntries` filters `type: 'game_card'` entries. Frontend `GameCardAttachment` component (Crown chess tile, success-toned verified badge + `BadgeCheck` icon for a11y, per-player badges with winner highlight, lichess.org footer link); `SystemBubble` extended to render cards below the centered text pill.

Same slice: `ConfirmOutcomeAction::resolveBothConfirmed` posts a "Players' confirmations conflict. Resolving via the game record." system message before flipping the match to `Disputed` — closes a UX gap where chat silently jumped from confirmation to settlement.

**Decisions**:

- **Snapshot, don't link**: cross-check usernames against the snapshot, not live link. A player unlinking mid-match can't strip the evidence anchor — prevents "unlink to escape match" abuse.
- **Sibling snapshot table, not flat columns on `game_matches`**: chosen mid-slice once CS2 (multi-identifier) became the next adapter. Flat columns would have needed deprecation within weeks.
- **Auto-fetch is evidence, not a vote**: no yes/no UI on cards. Player confirms Won/Lost/Drawn remain the only binding signal.
- **Auto-fetch never auto-settles**: even when the API winner disagrees with player confirms, player consensus still wins (or dispute path arbitrates). M14 territory.
- **Single decisive game in window or skip**: no "best guess" — wrong-game evidence is worse than no evidence. Auto-fetch fires once per match (zero→one confirm transition); idempotency via `attachments_json` membership check.
- **Verification is binary** ✓ or not. No "verified but with caveat."

**Phase 4b — Smart link enrichment for chess.com** ✅ shipped 2026-05-22

Mirror of Phase 4 adapted to chess.com's archive-based API. `ChessComGameClient` + `ChessComGameResult` DTO: no direct game-by-id endpoint exists, so the client fetches the snapshotted player's monthly archive (`GET /pub/player/{username}/games/{YYYY}/{MM}`) and filters by URL match (paste path) or opponent + since (auto-fetch). Queries current month + previous month to handle midnight-UTC games. Parses chess.com's per-side `result` strings into the shared decisive/draw model.

`FetchChessComGameMetadataJob` (paste path) picks the creator's snapshotted chess.com username for archive lookup (either side works since both archives carry the same game record). `AutoFetchChessComGameJob` (first-confirm) has `$tries = 4` with explicit `release([5,15,45][attempt-1])` backoff on empty-archive results to outlast chess.com's 5-15s archive lag. URL detection via `SendMessageAction::extractChessComGameUrl` covers `chess.com/game/(live|daily)/{id}` + legacy `chess.com/live/game/{id}` + `chess.com/analysis/game/(live|daily)/{id}`.

`ConfirmOutcomeAction::dispatchAutoFetch` routes by `listing.platform` to either `AutoFetchLichessGameJob` or `AutoFetchChessComGameJob` — both still require the relevant provider's snapshot on both sides. `LichessGameApi` renamed to `ChessGameApi` and made provider-agnostic (reads the card's `provider` field as a discriminator, looks up the matching snapshot). `config/stakly.php` default driver flipped `'lichess'` → `'chess'`. Frontend `describeWinner` extended with chess.com vocabulary (`checkmated`, `resigned`, `abandoned`, `agreed`, `repetition`, `50move`, etc.) alongside Lichess's (`mate`, `resign`, `outoftime`, etc.) — one card renderer handles both providers.

**Decisions**:

- **Single arbitration driver (`ChessGameApi`) covers both chess providers** via the card's `provider` discriminator. No parallel `ChessComGameApi` driver + chain wrapper.
- **Retry-on-empty for chess.com only**. Lichess auto-fetch is one-shot (real-time API); chess.com archives lag a few seconds, so the chess.com job releases with backoff (~65s total wait across 4 attempts).
- **Paste-path picks the creator's chess.com snapshot for archive queries**. Either side's archive works (game appears in both); convention is creator first.

**Phase 5 Slice A+B — Linked-account gates + `listings.platform` binding** ✅ shipped 2026-05-22

Marketplace participation now requires a verified chess link. Take + create are blocked at both the Action layer (sentinel `'not_linked'`) and the UI layer (disabled CTAs with platform-named copy + redirect to `/settings/linked-accounts` on bypass). The listing now carries a `platform` value (chess_com | lichess) and the gates check the user is verified on THAT specific platform — Alice with only Lichess can't take Bob's chess.com listing because they have no shared playing surface.

Slice A shipped first as the permissive version (`User::hasVerifiedChessLink()` → any chess provider unlocks both create + take); Slice B immediately tightened the gates to per-platform once `listings.platform` landed. Both ship in the same commit window — the permissive check is now obsolete in the create + take Actions (they check the listing's specific platform), but `hasVerifiedChessLink()` remains as a convenience signal for the "has any link at all" UX surfaces (the unlinked-user landing notice on `/listings/create`).

Backend: `listings.platform` migration (varchar 16, default `'chess_com'`, cast to `LinkedAccountProvider`). `Listing` model fillable + cast. `ListingFactory` defaults to 50/50 random platform + new `forLichess()` / `forChessCom()` states. `StoreListingRequest` validates `platform` as required + enum-member. `TakeListingAction` + `CreateListingAction` check `$user->{$listing->platform->value}_verified_at !== null`. Controllers map the sentinel to a redirect to `/settings/linked-accounts` with platform-named toast. `HandleInertiaRequests` shares `auth.user.has_chess_link` (any-link convenience) + `auth.user.linked_platforms` (ordered list, for per-platform UI). `ListingResource` exposes `platform`.

Frontend: create form picker (shown when user has multiple linked providers; auto-selected when only one). Listing detail page Take button has a new disabled "Link {platform} to take" branch with CTA link; existing balance/owner-inactive branches gated to ALSO require the matching platform. `ListingSeeder` chains `->withLichess()->withChessCom()` so seeded users participate in both halves of the marketplace.

**Decisions**:

- **Single platform per listing** beats multi-platform listings. Cross-platform players literally can't play each other; making them try and then explaining the failure at confirm time is worse UX than blocking the take.
- **Permissive gate (Slice A) was a stepping stone**, immediately superseded by platform-specific. The two-slice approach kept the diff understandable.
- **Default to `chess_com`** for the column. Pre-existing seeded listings stay valid (default value). Fresh listings pick at creation.

**Phase 5 Slice C — Match-page dispute UX polish** ✅ shipped 2026-05-22

The existing `OpenDisputeButton` + `OpenDisputeAction` (already wired in M11) re-skinned with mild Bybit-style entry copy: trigger now reads "Report a problem"; confirmation modal sharpens to "An admin will review and decide who gets the pot." Gate widened to always-visible during `Pending` (was previously gated on at least one confirm) so ghosting and pre-play cheating concerns can be reported symmetrically with confirm-disagreement.

`ResolveDisputeAction::flipToManualReview` (extracted helper) posts a second system message after the existing "Game API could not determine a winner…" narration: "Submit evidence in chat — screenshot, game URL, or PGN. An admin will review." The follow-up carries a `{type: 'dispute_prompt'}` attachment marker (shipped through `MessageAttachmentsPayload::disputePromptEntries`) which the React `SystemBubble` reads to render a warning-toned variant (warning border + `TriangleAlert` icon) instead of the default muted `Megaphone` treatment. Single insertion point in `ResolveDispute` covers both manual `OpenDisputeAction` and auto-dispute (`ConfirmOutcomeAction::resolveBothConfirmed`) paths since both flow through it.

`MatchInfoCard` gains a "Verification" row: `ShieldCheck` icon + "Auto via Lichess" / "Auto via chess.com" depending on `listing.platform`. Required adding `platform` to the listing column projection in `GameMatchController` + `UserController` (otherwise the `select` drops it and the resource crashes) and surfacing it through `GameMatchResource` + new `MatchListing.platform` TS field.

**Decisions**:

- **"Report a problem", not "Open dispute"**. Bybit-style mild wording avoids escalating tone at the entry point. The confirmation modal uses stronger language since the user is past the soft-prompt point.
- **Pending-only for the first cut**. Once a match is `Settled` the button is hidden. Post-settle disputes mean clawing back from a winner who may have already withdrawn — operationally heavier, deferred until M12 ships an admin panel that can resolve them. The full retrospective path is a Bybit-style 24h cooling-off where settled payouts aren't withdrawable for ~24h and either party can dispute → `ManualReview` without clawback (money was never spendable in the window). The Pending button covers the *acute* case (catch problems before money moves); cooling-off is the *retrospective* case for M12.
- **Always-visible during `Pending`** (was gated on confirm). Covers "we disagree," "opponent ghosted," and "I think they cheated" symmetrically. Spurious reports cost nothing — `ResolveDispute` → API → `Unknown` → `ManualReview`; admin reviews, no money moves prematurely.
- **Evidence prompt fires on `ManualReview` entry, not every `Disputed` flip**. If `ChessGameApi` resolves the dispute within seconds (the typical happy path on chess), an earlier "submit evidence" prompt would be contradictory — match settles via the API anyway. Gating on `ManualReview` keeps the prompt always action-relevant.
- **No `/listings` platform filter chip** (dropped from the original Slice C scope). Keeping mixed Lichess + chess.com listings visible — with disabled "Link {platform} to take" CTAs on listings the user can't take — converts unlinked users into linked accounts. Hiding the listings hides the link-acquisition prompt.

### Not in M8

- **Filament admin panel** — M12. Until that lands, disputes still resolve via the `ChessGameApi` → mock fallback path (M14 Slice A). Chat is *additive* in M8.
- **Auto-resolution without admin oversight** — full M14, gated on adapter maturity + dispute volume signal.
- **Chat anti-abuse** (off-platform deal detection, rate limits beyond basic, report-user, blocked words) — M13.
- **Voice / video chat in-app** — not on the table; externally-hosted clips covered via Slice 2 link cards.
- **Read receipts, typing indicators, message reactions, edit/delete, mentions, DMs** — none today. Open if a real user pulls for one.

---

## M10 — Mutual Match Cancellation ✅ shipped 2026-05-24

The fourth resolution path for a Pending match — sibling to "both confirm," "open dispute," and "4h timeout." Either player proposes via the **Request cancellation** button on the Pending action card; the opponent sees an inline warning-toned banner at the top of the match page with the requester's reason in a quoted block + **Decline / Accept and refund** buttons. On accept, the match flips to `Cancelled`, the listing flips to `Cancelled`, both stakes are refunded via `Wallet::release` (idempotent on `cancel-refund-{creator,taker}:{match_id}`), and a system message narrates the close-out. On reject, the request closes, the requester enters a 30-min per-user cooldown, and the match stays Pending.

**Schema** (`game_matches`): 5 new nullable columns — `cancelled_at`, `cancellation_requested_by` (FK users, nullOnDelete), `cancellation_requested_at`, `cancellation_rejected_at`, `cancellation_reason` (varchar 200). `MatchStatus::Cancelled` enum case added; M6 state-machine doc updated to include both cancel-accept and cancel-reject arrows.

**Actions** in `app/Actions/GameMatch/`: `RequestCancellationAction`, `AcceptCancellationAction`, `RejectCancellationAction`. Each is row-locked, idempotent where appropriate (accept short-circuits with `'already_cancelled'` on a re-call), and posts a system message via `PostSystemMessageAction`. Sentinel-string returns map to flash toasts in the controller adapters (`'requested'` / `'cancelled'` / `'rejected'` / `'race_lost'` / `'request_missing'` / `'self_{accept,reject}_forbidden'` / `'already_cancelled'`). Same refund-both conservation pattern as `SettleDrawMatchAction`: `-A_stake + -B_stake + +A_release + +B_release = 0`.

**Policy**: `GameMatchPolicy::requestCancellation` (participant + Pending + no-open-request + per-user 30-min cooldown), `acceptCancellation` / `rejectCancellation` (participant + Pending + open-request-exists + NOT the requester — defense in depth against bypass-policy callers).

**Routes**: `POST /matches/{match}/cancellation` (`matches.cancellation.request`), `/accept` (`.accept`), `/reject` (`.reject`). `RequestCancellationRequest` FormRequest validates `reason: nullable|string|max:200` and normalizes whitespace-only input to `null` in `prepareForValidation`.

**Frontend** (`components/match/`):
- `RequestCancellationButton` — subordinate inline button (sibling to `OpenDisputeButton`) with Handshake icon. Modal opens a **hybrid radio list of 5 preset reasons + "Other"** with conditional free-text textarea (200-char limit + live counter). Selection required to submit. Disabled state with tooltip shows remaining cooldown minutes.
- `CancellationRequestBanner` — inline warning-toned banner above the Confirm card while a request is open. Two variants from one component: viewer-is-requester (waiting state, reason echoed back, no actions) vs viewer-is-responder (requester's reason quoted + Decline / Accept-and-refund buttons).
- `CancellationSummary` — terminal banner in the action slot for `status === 'cancelled'`. Muted tone (no winner gradient), Handshake icon, "Both stakes refunded — $X returned to each player" + "Cancellations don't count toward your match record" + optional reason quote.
- `match/show.tsx` wires all three, hides the Request + Report-a-problem button pair while a request is open (banner takes over the action surface). `cooldownRemainingFor(match, viewerId)` helper computes the requester's cooldown locally — no clock subscription needed since the page polls every 8s during Pending.
- `MatchesFilterChips` gains a `Cancelled` chip on `/matches` (separate mental category from `Settled`, expected to be reasonably common in early-days play).

**Resource shape**: `GameMatchResource.cancellation` ships `{ requested_by_id, requested_at, reason, rejected_at, cancelled_at }` — all nullable. Frontend infers UI state from combinations. `requested_by_id` (not a nested player object) is intentional — saves an eager-load since the FE can look up name client-side from the already-loaded creator/taker.

**Decisions**:

- **Pending-only.** Once a match flips to `Disputed` (API has been invoked) or `Settled` (money's moved), cancellation is off the table. From those states the dispute / settlement path is the only exit.
- **One open request at a time per match.** Policy + Action both gate on `cancellation_requested_at !== null`.
- **30-minute per-user cooldown after rejection** keyed on `cancellation_requested_by`. Bob being mid-cooldown does not gate Alice (different requesters; cooldown is personal).
- **Request expires with the match timeout** (no separate expiry job). The 4h confirmation timer is the master clock — neither side responds, the regular timeout resolver runs.
- **Reason field stays out of the chat system message** (M10 Phase 3 — security tweak). The Action posts a neutral "X requested to cancel the match.". The reason surfaces only inside the structured banner UI on the opponent's screen, which sidesteps the M13 chat-anti-abuse bypass vector (system messages aren't filtered like user messages).
- **Hybrid reason input (radio list + Other)** beats free-text-only for discoverability and beats radio-only for flexibility. Preset paths carry safe canned strings; the "Other" textarea covers the long tail. Selection is required — picking a reason is low cost and helps the opponent understand. Empty "Other" persists as the literal `"Other"` so the opponent reads it as "didn't want to specify" rather than a blank field.
- **No fee on cancellation.** Mirrors the draw outcome — both stakes released, no platform rake.
- **Cancellation doesn't count toward player record.** Distinct from a played draw — explicitly logged as `Cancelled` (not `Settled` with null winner) so future stats / reputation surfaces can treat them differently.
- **Listing flips to `Cancelled` on accept** (not back to `Open`). UNIQUE constraint on `game_matches.listing_id` means one listing → at most one match ever; reopening would let a second match land on the historical record. Creator can create a new listing if they want to keep playing.
- **`confirmed_outcome` columns NOT cleared on cancel.** If Alice had clicked Won before the cancel, the historical record of her claim survives — useful for forensics if a dispute about the cancellation itself arises later. The terminal `Cancelled` status prevents these columns from being acted on (status guard in `confirm` policy).
- **"Take back my cancellation request"** not built. Bybit doesn't support it either; KISS until someone asks.

### Not in M10

- **Unilateral cancellation** (one player cancels without consent). The only one-sided exit during Pending remains "open dispute" — the API decides.
- **Partial refund / negotiated split.** Cancellation refunds both stakes equally; players who want to split unequally should play it out or dispute.
- **Cancellation during Disputed or ManualReview.** Once the game API has been invoked, only the API or admin can resolve.
- **Cancellation by listing creator before take.** Already exists via `ListingController::cancel` — that's a separate path that operates on `Listing` (not `GameMatch`) and doesn't need a peer's agreement.

---

## M16 — API-only outcome resolution ✅ shipped 2026-05-24

Replaced M6's player-self-report confirm flow entirely. Match outcomes come from the game API — never from "I won / I lost / Drawn" button clicks. The auto-fetch + `SettleFromCardAction` pipeline is the only settlement path during Pending; the explicit `Report a problem` button is the manual escalation; `Request cancellation` (M10) is the cooperative early-exit when no game gets played.

**Backend (Phase 1)**: deleted `ConfirmOutcomeAction`, `ConfirmRequest`, `GameMatchController::confirm` method + route, `GameMatchPolicy::confirm`. New `App\Actions\GameMatch\SettleFromCardAction` — row-locked, Pending-guard, branches on the card's `winner_color` (null → `SettleDrawMatchAction`, set → snapshot lookup → `SettleMatchAction`). Unmappable winners log + no-op rather than throw. The auto-fetch jobs (`AutoFetchLichessGameJob` + `AutoFetchChessComGameJob`) widened the candidate filter from `isDecisive()` to `isDecisive() || isDraw()` (drawn games settle as draw via SettleDrawMatch); after posting the card they invoke SettleFromCard immediately. `ResolveMatchTimeoutAction` collapsed from 145 → 75 lines: timed-out Pending matches flip straight to ManualReview (the Phase 2 cron has been retrying every 5 min for 4h — no game incoming). `creator_confirmed_outcome` / `taker_confirmed_outcome` columns kept nullable for historical audit (deprecated in the model docblock).

**Trigger plumbing (Phase 2)**: new `App\Actions\GameMatch\DispatchAutoFetchAction` is the single funnel — gated on `status === Pending` + both-sides snapshot present. Wired into 3 callsites: `GameMatchController::show` (page-visit), `SendMessageAction` (chat-send), `AutoFetchPendingMatches` cron command at `everyFiveMinutes()->withoutOverlapping()` scanning the `[now-4h, now-10min]` window. Both AutoFetch jobs implement `ShouldBeUnique` keyed on match.id to dedupe race-spam from the layered triggers.

**Frontend (Phase 3)**: deleted `confirm-buttons.tsx`. New `WaitingForGameCard` component with two `<AnimatePresence>`-driven states — "Looking" (warning spinner, watched username pair, "Last checked Ns ago" derived from the 8s poll tick) and "Found" (success icon, "Game found — settling now…" hand-off until next poll catches the Settled flip). FAQ rewritten — 4 of 6 Q&As were obsolete (referenced the dead confirm flow or the M10-overturned no-cancellation rule). `Match` TS type lost `creator/taker_confirmed_outcome` + `MatchOutcome`, gained `snapshots: MatchSnapshots`. `ChatGameCardAttachment.provider` widened from `'lichess'` to `'lichess' | 'chess_com'` (chess.com auto-fetch was already setting this; type now matches).

**Lichess stream consumer (Phase 4)**: new `App\Console\Commands\LichessStreamCommand`. Long-lived consumer of Lichess's `POST /api/stream/games-by-users` NDJSON stream (public endpoint — no OAuth required, contrary to original M16 design assumption). Subscribes to the distinct Lichess usernames across all Pending-Lichess matches (up to Lichess's 300-user cap). On any `mate` / `resign` / `draw` / `outoftime` / etc. event the daemon dispatches `AutoFetchLichessGameJob` for the matching Pending match. Curl-based with `CURLOPT_WRITEFUNCTION` for per-chunk line-buffered NDJSON parsing; returning `-1` from the callback cleanly aborts curl for reconnect when the 30s user-list refresh detects drift. Exponential-backoff-with-jitter reconnect (2s → 60s cap) on connection drop; `CURLOPT_LOW_SPEED_TIME = 60s` safety net catches hung connections. Cross-platform safety filter: the lookup requires `listing.platform = lichess` so a Lichess game between two players who happen to be on a chess.com Stakly match doesn't trigger Lichess settlement. Compose sidecar `lichess-stream` mirrors the `queue` + `reverb` pattern with `restart: unless-stopped`.

**Tests (Phase 5)**: deleted `GameMatchConfirmTest` (24 obsolete tests) + `Message/AutoFetchDispatchTest` (7 tests for the dead first-confirm trigger). Rewrote `Message/SystemMessageTest` (settle/draw/timeout tests now exercise the surviving Actions directly), both `AutoFetch{Lichess,ChessCom}GameJobTest` files (handle() signature gained `SettleFromCardAction`, added draw-card cases, asserted Settled status flips). Full rewrite of `MatchesResolveTimeoutsTest` for the straight-to-ManualReview behavior. New `SettleFromCardActionTest` (11 tests), `AutoFetchPendingMatchesCommandTest` (8 tests), `LichessStreamCommandTest` (12 tests — exercises the pure `dispatchFromEvent` decision logic, not the curl loop). Page-visit + chat-send dispatch triggers added to `GameMatchShowTest` + `Message/SendMessageTest`. Promoted `pendingMatch()` helper to `tests/Pest.php` (was in the deleted GameMatchConfirmTest). Suite ended at 691 / 0 failures / 2,744 assertions.

**Decisions**:

- **API is the only outcome source.** Player Won/Lost/Drawn confirms removed entirely. `Report a problem` (which routes through `OpenDisputeAction` → `ResolveDisputeAction` → `ChessGameApi`) is the manual escalation. `Request cancellation` (M10) is the cooperative early-exit.
- **Chess.com is polling-only forever** — no webhook exists in their Published Data API. The 5-15s archive lag is handled by the existing `AutoFetchChessComGameJob` retry-on-empty chain (~65s total wait across 4 attempts).
- **Lichess stream consumer is an OPTIMIZATION, not a hard dependency.** If the `lichess-stream` sidecar dies, the Phase 2 cron at 5-min cadence still catches the match within minutes. No frozen-match scenario.
- **No Lichess OAuth.** The endpoint is `security: []` — public. Avoids an admin Lichess account that would need 2FA / token rotation / ban-risk management. If Lichess ever requires auth, adding a Bearer header is a one-line change.
- **300-user Lichess cap** is per the Lichess spec. Daemon truncates + logs a `warn` on overflow. Multi-stream split (partition usernames across N parallel connections) is the scaling path when we get close — but we don't build it preemptively because (a) it's a real complexity jump and (b) Lichess may not allow many parallel streams from one IP, which would need research first. **Trigger to build multi-stream**: sustained Pending-Lichess username count ≥200 over any 24-hour window. Daemon already logs a `warn` at this threshold (`MULTI_STREAM_TRIGGER_USERS`) so the signal surfaces in `docker compose logs lichess-stream` before any user-visible impact. 200 usernames ≈ 100 simultaneous matches → 100-username buffer below the cap, enough to research Lichess's multi-stream tolerance + ship the split before settlement degrades to cron-only for overflow.
- **4h timeout → ManualReview** (no final API re-dispatch). The cron has been trying for 4 hours; one more attempt at the timeout boundary would be redundant and would block the timeout-cron worker for up to 65s.
- **Draws auto-fetch + auto-settle.** Both `LichessGameResult::isDraw()` and `ChessComGameResult::isDraw()` qualify a game as a settlement candidate; a drawn card has `winner_color = null` which routes SettleFromCard to `SettleDrawMatchAction` (refund both, no fee).
- **Cross-platform safety in the stream consumer.** The DB lookup explicitly filters `listing.platform = lichess` so a Lichess game between two players who happen to have a chess.com Stakly match doesn't auto-settle the wrong match.
- **`confirmed_outcome` columns kept nullable** post-M16 for historical audit. A future cleanup migration drops them.
- **`MatchOutcome` PHP enum kept** — still used by the model casts on the kept-for-audit columns + by `ChessGameApi` to map card winner colors. The frontend TS type was dropped entirely (no UI surface uses it).

### Not in M16

- **Per-user Lichess OAuth.** No OAuth at all (admin or per-user). The endpoint is public; no upgrade path needed.
- **Chess.com webhooks.** None exist; polling is the only option for that platform.
- **Re-introducing player confirm as an opt-in.** No "I want to override the API" surface. If the API is wrong (cheating, account swap), the path is `Report a problem` → ManualReview → admin (M12).
- **Push notifications.** The page-visit + chat-send pattern is pull-based; players see results when they return. Push is a separate UX project.
- **Dropping the deprecated `confirmed_outcome` columns immediately.** Kept for audit; future migration handles the drop.

---

## M14 Slice A — Chess card arbitration ✅ shipped 2026-05-22

Pulled forward from the broader M14 milestone (still active in `milestones.md`) because the mock arbitration was paying the wrong player when a Lichess card showed a different winner — a visible "the system is broken" symptom every time a dispute hit during dev.

`App\Services\GameApi\ChessGameApi` implements `GameApi`. Reads the most-recent auto-fetched card (`source: 'auto_fetch'`) off the match's chat — provider-agnostic (handles both Lichess and chess.com via the card's `provider` field) — and returns the named winner with `Confirmed` confidence. Maps `winner_username` to a Stakly `user_id` via snapshotted handles (case-insensitive). Falls through to `MockGameApi` for no card, race window (auto-fetch hasn't completed), paste-only cards, or unmappable winners (defensive).

`AppServiceProvider::bindGameApi` registers `MockGameApi` as its own concrete + binds `GameApi::class` to `ChessGameApi` (wrapping `MockGameApi` as fallback). `config/stakly.php` default driver: `'chess'`. `tests/Pest.php` `mockGameApi()` helper resolves `MockGameApi::class` directly so existing tests using `forceWinner()` keep working through the wrapper's fallback path.

Originally landed Lichess-only as `LichessGameApi`; Phase 4b extended to chess.com cards and renamed to `ChessGameApi`. Full M14 (FACEIT, OpenDota, Riot, plus no-admin-fallback policy) still lives behind the original trigger: M12 admin path in use + dispute volume signal.

---

## M14 Phase 1 — Per-match audit trail + admin visibility ✅ shipped 2026-05-29

Closes the "why is this match in ManualReview?" gap. Pre-Phase-1, every dispute investigation started with `grep` against ephemeral logs — the pipeline ran in production conditions with no in-app record of what it tried, when, or why it failed. M14 Phase 1 captures the auto-fetch pipeline's reasoning as queryable data inside the app: every dispatch, every fetch, every skip lands as one `match_auto_fetch_attempts` row, and admins read the trail straight from the match's Filament page.

**Schema** — `match_auto_fetch_attempts` table (append-only, no `updated_at`). Columns: `match_id` (cascade-on-delete FK to `game_matches`), `provider`, `outcome`, `outcome_reason`, `attempt_number`, `winner_username`, `candidates_count`, `error_message`, `latency_ms`, `created_at`. Three indexes: `(match_id, created_at)` for the per-match timeline, `(outcome, created_at)` for outcome-wide rollups, `(provider, outcome, created_at)` for provider-specific health queries. `App\Enums\AutoFetchOutcome` (Matched / NoMatch / Ambiguous / Error / Skipped) cast on the model. `GameMatch::autoFetchAttempts()` ordered oldest→newest so the timeline reads top-down.

**Single write point** — `App\Actions\GameMatch\RecordAutoFetchAttemptAction`. Mirrors the Wallet pattern: every audit row goes through one Action so the write surface stays auditable. Trims `error_message` to 2000 chars at the boundary (full message stays in the log mirror). DB insert failures log + return null rather than cascade into pipeline failure — losing an audit row beats losing a settlement. Pairs every DB row with a `Log::info` / `Log::warning` line (warning on `error` outcomes, info on all others) so operators tailing either surface see the same record. `DispatchAutoFetchAction` writes skips for `not_pending` and `snapshot_missing` before reaching the jobs. Both `AutoFetchLichessGameJob` and `AutoFetchChessComGameJob` write `matched` / `no_match` / `ambiguous` / `error` rows with `latency_ms` measured around the HTTP call.

**Admin surfaces** — `GameMatchInfolist::autoFetchHistorySection` renders a per-attempt timeline inside the match View page (inline HTML with colored outcome badges, latency, and per-outcome detail strings — `Matched` shows the winner, `NoMatch` shows the candidate count + reason, `Skipped` shows the reason). `PipelineHealth` widget on the Filament dashboard (`StatsOverviewWidget` with 4 stats): auto-settlements (24h count + 7d sparkline), pipeline errors (24h count + 7d sparkline, danger ≥10/day), avg provider latency (7d vs prior 7d trend — lower = success), pipeline attempts (24h volume + per-outcome breakdown). 30s polling.

**Diverges from the original spec on the widget shape** — original called for 7d/30d success-rate percentages + avg time-to-settle (Pending → Settled) + top no_match reasons grouped by provider. Shipped 24h-counts + 7d-latency-trend instead. The 24h focus matches how an operations dashboard actually gets read at launch ("what's broken right now?") and raw counts give clearer signal than ratios at low volume. The percentage / time-to-settle / top-failure-reasons views can land as a separate slice if real telemetry shows they're needed. Also: `no_match` badge color is gray (muted), not warning — `no_match` is the expected outcome on most ticks (the game hasn't been played yet), warning would cry wolf.

**Tests** — `DispatchAutoFetchActionTest` (skip path coverage), `RecordAutoFetchAttemptActionTest` (write + log mirror + truncation + insert-failure swallowing), `AutoFetchLichessAuditTrailTest` + `AutoFetchChessComAuditTrailTest` (per-job outcome coverage), `Admin/GameMatchResourceTest` (Filament timeline rendering), `Admin/PipelineHealthWidgetTest` (widget stats + time-window queries). 63 tests, 186 assertions.

Commit: `527dd1e`. Files touched: 21 (1995 insertions). Foundation for Phase 2 (reliability) — the audit rows are the substrate every retry / circuit-breaker decision will read from.

---

## M14 Phase 2 — Reliability ✅ shipped 2026-06-06

Turns provider failures from silent log-and-swallow events into a classified-retry-or-fail decision tree. Pre-Phase-2, every HTTP error caught a single `ProviderUnavailableException` and silently dropped (audit row written, retry only at the next 5-min cron tick). Post-Phase-2, transient errors retry within the job, rate-limited errors honor the provider's `Retry-After`, permanent errors fail-fast into `failed_jobs`, and a per-provider circuit breaker pauses dispatch when error rates spike.

**Slice 2a — `ProviderError` hierarchy.** Replaced `ProviderUnavailableException` with `App\Services\Provider\Exceptions\ProviderError` (abstract) → `TransientProviderError` (5xx / connect / timeout) + `RateLimitedError` (429, carries optional `?CarbonInterface $retryAt`) + `PermanentProviderError` (4xx-non-429 / malformed JSON). Both Game clients (`LichessGameClient`, `ChessComGameClient`) classify responses via a private `classifyResponseError(Response $response, string $context)` helper. Profile clients (Lichess + chess.com) renamed their throws to `TransientProviderError` for parity (no per-status differentiation yet — future-extensible). 14 references to the deleted `ProviderUnavailableException` migrated. **Diverges from original spec** ("transient / permanent / **ambiguous**") — rate-limited gets its own class because its retry strategy is provider-driven via `Retry-After`, and "ambiguous" is better modeled as an audit-row `outcome` value than an exception class.

**Slice 2b — Job retry policy.** `AutoFetchLichessGameJob` `$tries: 1 → 4` (initial + 3 retries) with `$uniqueFor = 240`. `AutoFetchChessComGameJob` `$tries: 4 → 7` (existing 4-slot no_match retry chain + 3 HTTP error retries share the budget) with `$uniqueFor = 360`. Both define `backoff(): [5, 15, 30]` and `retryUntil(): match.created_at + M16 timeout` so the retry chain can't outlive the match's confirmation window. New catch shape: `PermanentProviderError` → write audit + `$this->fail($e)` (no retry, lands in `failed_jobs`); `TransientProviderError` + `RateLimitedError` → write audit + re-throw (Laravel retries per backoff). Removed the catch-all `Throwable` swallow so programming bugs surface immediately. ChessCom job's no_match retry chain (`RETRY_DELAYS = [5, 15, 45]`) and HTTP error retries share `$tries=7` — a flaky provider eats into archive-lag retry budget, which is the right semantic.

**Slice 2c — Rate-limit header awareness.** New `App\Services\Provider\RateLimitHeaderParser::parseRetryAt(Response): ?CarbonInterface`. Honors `Retry-After` first (RFC 9110: integer seconds OR HTTP-date), falls back to `X-RateLimit-Reset` (numeric, heuristic: values past year-2000 epoch are unix timestamps, smaller are seconds-from-now). Both Game clients populate `RateLimitedError::retryAt` from this parser. Both auto-fetch jobs gained a dedicated `catch (RateLimitedError $e)` block before `catch (ProviderError $e)`: if `retryAt` is set + in the future + retry budget remains, call `$this->release($delay)` with the provider-supplied delay (overrides the job's default `backoff()`); otherwise fall through to throw. Uses `now()->getTimestamp()` (not PHP `time()`) so Carbon's `setTestNow` mocking carries through tests.

**Slice 2d — Circuit breaker per provider.** New `App\Services\Provider\ProviderCircuitBreaker` — sliding 10-min window of `[ts, success]` attempts in cache, trips when ≥5 attempts AND failure rate >50%, opens for a 5-min cooldown, attempts list cleared on trip (prevents immediate re-trip on stale signal after cooldown). `recordFailure` no-ops while open (defensive). `DispatchAutoFetchAction` constructor-injects the breaker and short-circuits with an audit row reasoned `circuit_open` between the existing `not_pending` and `snapshot_missing` checks. Both auto-fetch jobs accept a 5th `handle()` arg (the breaker) and call `recordSuccess` after a successful HTTP call (regardless of candidate count — health tracks provider availability), `recordFailure` after each error catch. New `App\Filament\Widgets\ProviderCircuitBanner` with `canView()` short-circuit — renders only when at least one chess provider's circuit is open. Red banner shows provider name + cooldown countdown. Thresholds (50% / 5 attempts / 5 min / 10 min window) are starting defaults — re-tune after first month of real telemetry.

**Tests** — `ProviderErrorTest` (hierarchy), `RateLimitHeaderParserTest` (12 parser branches), `ProviderCircuitBreakerTest` (11 tests: trip / cooldown / per-provider isolation / no-op-while-open), `ProviderCircuitBannerTest` (`canView` + Livewire render), updates to client tests + audit trail tests + dispatch action test. Phase 2 added ~50 tests across the four slices.

Commits: `feat(provider): M14 Slice 2a — structured ProviderError hierarchy`, `feat(provider): M14 Slice 2b — auto-fetch job retry policy`, `feat(provider): M14 Slice 2c — honor Retry-After / X-RateLimit-Reset`, `feat(provider): M14 Slice 2d — per-provider circuit breaker`.

---

## M14 Phase 3 — Coverage ✅ shipped 2026-06-06

Fills in the silent-skip edge cases the pipeline had left as "stays Pending forever, eventually flips to ManualReview." Each slice paired a research/decision step with the implementation; decisions written into `milestones.md` before each implementation slice landed.

**Slice 3a — Aborted-game policy (decision).** Researched Lichess (`status: 'aborted' | 'noStart'`) and chess.com (`result: 'abandoned'` on both sides) abort semantics. Decided **cooperative-exit refund**: aborted games settle as a draw, both stakes refunded. Reasoning: mirrors M10's mutual-cancellation philosophy; abuse window is narrow (Lichess only allows abort during moves 0-1, chess.com requires both players to abandon); the `completion_rate_30d` trust signal penalizes serial aborters naturally; cleaner UX than leaving the match stuck in Pending.

**Slice 3b — Aborted-game implementation.** Added `LichessGameResult::ABORTED_STATUSES = ['aborted', 'noStart']` + `isAborted()`. Added `ChessComGameResult::ABORTED_RESULTS = ['abandoned']` + `isAborted()`. Refactored `filterCompleted` in both jobs to a **two-pass** filter: primary = decisive/draw, fallback to aborted only when there's no primary candidate. The two-pass rule prevents an aborted game from poisoning a window that has a real played-out result — decisive games always win when both are present (the original "1 decisive + 1 aborted → posts decisive" test still passes). Aborted games settle via the existing `SettleFromCardAction` draw path (winner_color = null → both stakes refunded). Card payload carries the original `aborted` / `abandoned` status so chat UI can render an "aborted, refunded" banner if it wants.

**Slice 3c — Multi-candidate disambiguation.** Replaced the silent `count > 1 → ambiguous` skip with a deterministic picker: `pickSettleableCandidate(array $candidates)` filters by listing's `time_control` array, then sorts surviving candidates by `|lastMoveAt - match.created_at|` (or `endedAt` for chess.com), tie-breaking on lexicographic game id. Returns `null` when no candidate matches the time-control. Audit row's `candidates_count` retains the pre-disambiguation count so the timeline reader sees "found N, picked 1." When picker returns null: `outcome=ambiguous` + `outcome_reason='time_control_mismatch'`.

**Slice 3d — Single-candidate time-control enforcement (reverted 2026-06-06).** Initially shipped: picker called for all candidate counts; single-candidate match with TC mismatch → ambiguous, stay Pending. Reverted same day after live testing surfaced the problem — Lichess returns `correspondence` for casual no-clock games and `bullet` for sub-180s estimated games, neither of which sit in Stakly's `TimeControl` enum (blitz / rapid / classical only). Outcome: real played-out games were silently rejected because their speed didn't map to the listing's TC. The picker stays in the codebase (Slice 3c still uses it for multi-candidate disambiguation), but `handle()` only calls it when `count > 1`. Single-candidate path is back to pre-3d behavior: take the game, post the card, settle. The sandbag-via-time-control hole 3d was meant to close is real but tiny — addressing it correctly requires either expanding the `TimeControl` enum or making listing-creation UX teach users which time controls Stakly actually accepts. Both are M-something-else, not M14.

**Tests** — Added 16 tests across Slices 3b / 3c / 3d covering: aborted classification (Lichess + chess.com), two-pass filter (decisive beats aborted), single-candidate TC mismatch (blitz-listing + bullet-game → ambiguous), multi-candidate picker (closest by time + tie-break by id), multi-candidate TC mismatch. Several existing ambiguity tests updated to force `time_control=['classical']` to preserve "ambiguous" semantics under the new picker.

Commits: `feat(provider): M14 Slice 3b — aborted games settle as cooperative-exit refund`, `feat(provider): M14 Slice 3c — multi-candidate disambiguation`, `feat(provider): M14 Slice 3d — single-candidate time-control enforcement`.

---

## M14 Phase 4 — Dispute fast-path ✅ shipped 2026-06-06

The original M14 intent slimmed to chess-only flag-gated wiring.

**Slice 4a — `OpenDisputeAction` → `ResolveDisputeAction` (chess, flag-gated).** New `config('stakly.dispute_fast_path_enabled')` (default `false`, env override via `STAKLY_DISPUTE_FAST_PATH_ENABLED`). `OpenDisputeAction` constructor now injects `ResolveDisputeAction`; after the after-commit notifications fire, a private `runFastPathIfEnabled()` helper checks two gates — flag is on AND `listing.game === Game::Chess` — before calling `ResolveDisputeAction::handle($match)` synchronously. Chess gate widens in M15 when FACEIT / OpenDota / Riot adapters ship. When the fast-path fires, the match transitions directly from Disputed to Settled (Confirmed / Drawn) or ManualReview (Unknown) on the same HTTP request — player sees "Report a problem" → resolution in <1s.

**Slice 4b — Flag flip after Phase 1 metrics (open checkpoint).** No code — calendar checkpoint. Once Phase 1's `PipelineHealth` widget shows ~2 weeks of stable auto-fetch (>95% success, no provider-side outages), flip the flag on in dev → observe → flip in prod. Not blocked on anything else in M14; waiting on real telemetry. Tracked here in the archive rather than in `milestones.md` since M14 is functionally complete and the checkpoint doesn't need active milestone surface area.

**Tests** — 5 tests in `tests/Feature/GameMatch/DisputeFastPathTest.php`: flag-off-default (status stays Disputed), flag-on-confirmed (Settled to winner), flag-on-draw (Settled, no winner), flag-on-unknown (ManualReview), flag-on-non-chess (chess gate prevents fast-path). Existing OpenDispute tests continue passing — default flag preserves M12 Phase 3's "admin handles all disputes" behavior unchanged.

Commit: `feat(provider): M14 Slice 4a — flag-gated dispute fast-path`.

---

## M12 — Filament admin panel + chat-driven dispute resolution ✅ shipped 2026-05-24

Pulled forward from "pre-launch gate" because chat-first dispute resolution needs admin tooling. Without M12, M8's chat sat alongside the existing `MockGameApi` dispute path — useful but not the primary mechanism. M12 makes chat the source of truth for disputes; the game API becomes one input among many that an admin weighs.

**Phase 1 — Install Filament + admin auth** ✅ shipped 2026-05-24

Filament 5 installed via `composer require filament/filament:"^5.0"` + `php artisan filament:install --panels`. Admin lives at `/admin/*` (Livewire + Alpine + Filament's Tailwind config, separate from the Inertia + React user app — doesn't share Stakly's pink/purple design, per user direction). Admin role gate via Spatie permissions: `User implements FilamentUser` with `canAccessPanel()` enforcing two gates in order — `is_platform` blocked unconditionally (defense in depth — the platform user holds the rake and must never log in even with an accidental role grant), then `admin` role check. `AdminUserSeeder` reads `ADMIN_EMAIL` / `ADMIN_PASSWORD` / `ADMIN_NAME` env with dev fallbacks; idempotent via `firstOrCreate` on role + user. `UserFactory::admin()` state for tests. 8 feature tests in `AdminPanelAccessTest.php`.

**Phase 2 — Match resolution panel** ✅ shipped 2026-05-24

`App\Filament\Resources\GameMatches\GameMatchResource` at `/admin/disputes`. Default `SelectFilter` pre-selects Disputed + ManualReview (the queue); admin can lift the filter to all statuses for context lookups. Sorted desc by `created_at`. Eager-loads `listing.user`, `taker`, `winner` to avoid N+1. Read-only — `canCreate()` returns false; no Edit page. View page renders five sections: Match metadata · Creator profile · Taker profile · Chat history (custom `ChatHistoryEntry` infolist component + Blade view rendering text bubbles, system messages, image thumbnails via the existing `matches.messages.attachment` route, game-card + link-card payloads from `attachments_json`) · Admin resolution history (only renders if rows exist).

Three header actions on the View page: Settle to creator (success/green) · Settle to taker (success/green) · Draw — refund both (warning/amber). Each requires a `Textarea::make('reason')->required()->maxLength(1000)`, requires confirmation, hidden via `visible()` on terminal statuses (Settled / Cancelled). Race-loss errors surface as Filament danger notifications.

`match_admin_resolutions` audit table — immutable (`created_at` only, no `updated_at`) — with `match_id` cascade · `admin_user_id` restrict · `action` string (`settle_to_creator` / `settle_to_taker` / `settle_draw` via `MatchAdminResolutionAction` enum) · nullable `winner_user_id` restrict · `reason` text. Wrapped by `App\Actions\GameMatch\Admin\AdminSettleToWinnerAction` and `AdminSettleDrawAction` which write the audit row in the same DB transaction as the underlying `SettleMatchAction` / `SettleDrawMatchAction` call (atomicity: row not written on failure).

`SettleMatchAction` + `SettleDrawMatchAction` guards relaxed to also accept `ManualReview` (was Pending + Disputed only — admin is the legitimate `ManualReview` resolver). `GameMatchPolicy::view` scoped admin bypass — admins pass `view` so they can stream chat attachments via the existing player route; bypass is `view`-only (admins don't get cancellation/dispute capabilities from the player UI). 19 new tests (`AdminSettleActionsTest` + `GameMatchResourceTest`).

**Phase 3 — Switch dispute resolver** ✅ shipped 2026-05-24

`OpenDisputeAction` no longer dispatches `ResolveDisputeAction` / `MockGameApi`. It just flips the match to `Disputed`, posts a system message (`'Dispute opened by {name}. Please post any evidence (screenshots, game URLs, PGN) in this chat — an admin will review.'`) with the `dispute_prompt` attachment marker, and returns. Money stays escrowed until an admin clicks Settle/Draw in the Phase 2 Filament panel.

`ResolveMatchTimeoutAction` was already feeding `ManualReview` (not the API) post-M16 — no change needed in Phase 3. `ResolveDisputeAction` + `MockGameApi` retained as-is in the codebase for the existing test suite and future M14 automated arbitration. Conceptual model post-Phase-3: **`Disputed`** = player-triggered admin review (via "Report a problem" button), **`ManualReview`** = timeout-triggered admin review (4h window expired without API-verified game record). Both surface in the same admin queue; the status distinction encodes context only.

Controller toast simplified: single warning toast `'Dispute opened — an admin will review and resolve this match.'`. Frontend `match/show.tsx` banner copy rewritten — header is now `'Admin review pending'` for both statuses with status-specific body text. `OpenDisputeButton` confirmation copy updated to reflect admin-resolution flow.

**Polish iteration** ✅ shipped 2026-05-24

After live-testing the Phase 2 panel, two polish slices landed on top:

**Layout + chat rebuild (high-impact)** — `GameMatchInfolist` restructured: top-banner Match section (6-column compact grid: Match # · Status · Game · Platform · Stake (each) · Pot total · timestamps · Winner with trophy icon), two-column Grid for Creator + Taker side-by-side, full-width Chat history, full-width audit log. Winner gets a gold `Heroicon::Trophy` on their card's section header when Settled. Empty linked-account rows hide via `->hidden(fn ($state) => blank($state))`. `ChatHistoryEntry` Blade view rebuilt: consecutive messages from the same author collapse into one bundle (Slack-style, one header per burst), role color-coded left border (creator cyan, taker rose, system gray) PAIRED with a text role badge (UX rule: don't convey info by color alone), timestamps right-aligned + muted with bundle range when spans multiple minutes, attachment images in bordered hover containers linking to full-size in a new tab, dashed-border empty state.

**Pot math + urgency + cross-link (medium-impact)** — Added Platform fee + Winner payout entries beside Pot total (reads `config('stakly.platform_fee_rate')` for the rate; full breakdown so admin doesn't mental-math who's owed what). `Dispute opened` field promoted to a colored badge with urgency tiers: green <1h (fresh), amber 1–6h (aging), red 6h+ (stale), gray on terminal matches. `Open as participant` header action — gray icon button (`heroicon-o-arrow-top-right-on-square`) that links to the player-side `/matches/{id}` page in a new tab for verifying what players actually see.

### Decisions

- **Filament's design ≠ Stakly's design — by choice.** Admin panel uses Filament defaults (Livewire + Alpine + Filament Tailwind config) rather than the Stakly pink/purple system. Saves visual styling time on a tool only the operator sees; preserves Filament's component ergonomics; doesn't compete with the player app for design attention. Documented as the intended trade-off.
- **`is_platform` users blocked from admin panel even with the role.** Defense in depth: a misconfigured seeder or accidental role grant on the rake-holder must NOT let it log in. `canAccessPanel()` checks `is_platform` BEFORE the role check.
- **Audit row + Settle in the same DB transaction.** `match_admin_resolutions` rows are written from inside the same `DB::transaction` as the wallet movement. If the Settle throws (terminal status race, conservation invariant violation), the audit row rolls back. The trail is exactly the money movements that succeeded — no orphan rows.
- **`ManualReview` is admin-settleable, not terminal.** Pre-M12 the Settle actions rejected `ManualReview` outright with "admin tools must take a different path." Phase 2 made the Filament panel that path, so the guard accepts `ManualReview` as well as Pending + Disputed.
- **Admin doesn't write in chat (yet).** Phase 2 ships read-only chat. Admin's resolve flow includes a required-reason textarea that lands in the audit table, plus the auto-posted system message after settlement narrates the outcome to players. If real disputes show admin needs to ask for more evidence, a Phase 4 ("request evidence" system message button) or full admin-in-chat lands later. Validating need before building.
- **No real-time admin notifications in M12.** Filament's default polling is fine for current dispute volume. Email/push when a new dispute opens lands as a separate ask if volume justifies.
- **Bulk resolution actions deliberately out.** One match at a time — admin reviews evidence before paying. Bulk actions are a footgun on money-moving flows.

### Follow-up backlog

Ideas captured during M12 build + polish that aren't worth doing now but should land later as Stakly's ops surface grows. Pull individual items into a slice whenever they become valuable.

**Dispute review workflow**

- **Request-evidence action.** A 4th resolve button that doesn't settle — posts a system message in chat ("Stakly support needs the chess.com game URL — please post within 48h or this will be settled as draw") with a configurable deadline. Lets admin gather more info without choosing a side. Useful when chat evidence is thin but the dispute isn't yet "irrecoverable."
- **Inline admin notes** on a match. Private notes admins write to each other ("waiting on legal", "this user has 3 prior reports") — not visible to players, separate from the resolution audit log. Just a `match_admin_notes` table + a notes panel in the View page.
- **Admin-writes-in-chat** (full support panel). Admin posts as "Stakly Support" with a verified badge; players reply in normal chat. Decided in M12 Phase 2 design discussion to defer until real disputes show we need it — most disputes resolve fine on chat evidence already posted. Revisit if "I'd settle this but I need one more piece" becomes a recurring admin frustration.
- **Partial refund tool.** Currently the "draw" action refunds both stakes fully. Nuanced cases (one player clearly forfeited but other played in bad faith) might warrant 70/30 splits. Wallet primitives already support arbitrary amounts; just needs an action UI + audit shape.

**Admin productivity**

- **Audit log as its own Filament resource.** Move `match_admin_resolutions` from the inline HTML render on the View page to a proper `MatchAdminResolutionResource` at `/admin/resolutions`. Filterable by admin, action, date range. Useful for self-audit ("what did I resolve this week?") and team-audit ("who's settling to creator most often?").
- **Aging-dispute reminders** (cron-driven). Every N hours, fire a notification for any dispute still open beyond a threshold (e.g. 4h). Separate from the open-event notification — catches the "I missed it the first time" case. Needs a scheduled task + dedup to avoid spamming the same dispute every cron tick.

> Dashboard widgets + real-time admin notifications were already promoted to **M17** (sibling archive entry).

**Player context in dispute review**

- **User history sidebar** in Creator / Taker cards: "X disputes opened, Y won, Z lost", "wallet balance", "matches played in last 30d", "open reports against this user" (depends on M13). Gives admin "is this a habitual disputer?" context without leaving the page.
- **Quick links to game APIs.** Buttons in the chat history that take admin straight to chess.com / Lichess game search for the snapshotted usernames. Saves the copy-paste step when admin wants to manually verify a claim.
- **Provider snapshot view.** Show the snapshotted username from `match_provider_snapshots` (what they were linked as at match creation), not just current linked accounts. Matters when a player unlinked and relinked a different account post-match.
- **Listing context popover.** Quick view of the original listing (description, time control, language, region) for context — currently the admin has to leave the page to see the listing.

**Filtering + search**

- **Better search.** Currently the matches list is sortable but not searchable. Add search by player username, player email, or stake range. Existing filter only covers status.
- **Audit log CSV export.** Download `match_admin_resolutions` filtered by date range — useful for any future accounting or operational review.

---

## M17 — Admin operational tooling ✅ shipped 2026-05-24

Replaced Filament's placeholder dashboard (`AccountWidget` + `FilamentInfoWidget`) with a real ops surface: at-a-glance health stats + real-time bell-icon notifications when something needs admin attention. Pulled forward ahead of M13 because M12 just shipped — admin previously had no signal that a dispute opened without manually refreshing `/admin/disputes`.

**Phase 1 — Dashboard widgets**: `OpsOverview` widget (consolidated after live-testing showed 4 per-stat widgets each rendered full-width and stacked tall). One `StatsOverviewWidget` with `getColumns() => 2` for a 2x2 scorecard. Urgency-first ordering: Open disputes (action item) · Matches today (volume) · Platform earnings this month (revenue trend) · Active users 7d (engagement trend). Top row = "right now," bottom row = "trends." 30s polling. Per-stat queries are private methods on the widget; `getStats()` reads as a recipe. 10 Livewire-driven feature tests in `DashboardWidgetsTest.php` — includes `staleListingAndMatch()` helper for ActiveUsers fixtures since factory chains auto-create users that pollute the active count.

**Phase 2 — In-panel real-time notifications**: `notifications.data` column migrated to `jsonb` (Postgres requirement for Filament's `data->>'format'` bell-icon query). `->databaseNotifications()` + `->databaseNotificationsPolling('30s')` enabled in `AdminPanelProvider`. `App\Actions\Admin\NotifyAdminsAction` broadcasts Filament notifications via `sendToDatabase($admins, isEventDispatched: true)` to users with the `admin` Spatie role (excludes `is_platform`). Uses `whereHas('roles', ...)` not Spatie's `role()` scope so it gracefully no-ops if the role isn't seeded. Triggers wired into `OpenDisputeAction` ("Dispute opened — match #N", warning) + `ResolveMatchTimeoutAction` ("Match auto-flagged — #N", danger). Both fire AFTER the DB transaction commits — broadcast events are more reliable after-commit, and rolled-back disputes don't ghost-notify. 7 feature tests covering role scoping, graceful no-op, lifecycle hooks (success + race-loss paths).

**Polish iteration**: `$slug = 'disputes'` on `GameMatchResource` for the pretty `/admin/disputes` URL; widget + notification URL builders use `GameMatchResource::getUrl()` (survives slug renames).

### Decisions

- **One consolidated widget, not four.** Filament renders multiple `StatsOverviewWidget`s as full-width per widget, forcing a tall vertical stack. Consolidating into one widget + overriding `getColumns()` produces the Stripe/Linear-style scorecard layout.
- **`isEventDispatched: true` on `sendToDatabase`.** Triggers Filament's broadcast event so notifications appear without a refresh.
- **After-commit lifecycle hooks.** `OpenDisputeAction` + `ResolveMatchTimeoutAction` fire `NotifyAdminsAction` after the DB transaction commits — guarantees the notification only fires on actually-committed disputes (no rolled-back ghost notifications).
- **`whereHas('roles', ...)` over `role()` scope.** Spatie's `role()` throws `RoleDoesNotExist` if the role isn't seeded — annoying in fresh test setups. `whereHas` returns empty instead.

### Phase 3 (deferred) — Email backup

Out-of-panel email alerts for admins not in the panel. Deferred until real ops shows in-panel alone is insufficient. Trigger to revisit: an admin reports missing a real dispute because they weren't logged in. Until then, in-panel + browser-tab habit is the coverage. Sketch when picked up: mail dispatcher to every admin on `NotifyAdminsAction` fire (alongside the in-panel notification, not instead); per-admin opt-out; optional Slack webhook variant.

---

## M18 — Profile expansion ✅ shipped 2026-05-25 → 2026-05-26

Multi-slice profile content expansion: avatar + bio + linked-accounts schema normalisation + stats hero + completion-rate chip on profile. **Closed out 2026-05-27** when the originally-planned Slice B.2 (more-info modal) + Slice B.3 (listing-row chip) were superseded — M19 Phase 2 deleted the completion-rate chip from the hero as redundant with the Data Overview tile, leaving nothing for B.2 to modal-open or for B.3 to render on listing rows. Former Slice C (repeat-pair widget + win-rate gradient bar) and the originally-scoped Phase 4 (share button + OG meta) absorbed into M19 Phase 3 + Phase 5 and shipped there.

### Phase 1 — Editable identity + avatar (2026-05-25)

The smallest unit of "I'm a real person, not a bot." Schema: `users.bio` (varchar 500). Avatar via Spatie Media Library (`profile-avatar` collection on `User`, web-safe MIME types, ~2 MB cap, automatic 512×512 + 128×128 thumbnail conversions). Client-side crop modal via `react-image-crop` (~10 KB MIT-licensed, the standard React choice for circular avatar cropping). `/settings/profile` extended to manage avatar + bio. `UserProfileResource` exposes `avatar_url` + `avatar_thumb_url`. Avatars are public-by-nature so they live on the public disk (`storage/app/public/`) — separate from chat attachments which need authenticated streaming. Public profile renders the avatar in a circular frame with a magenta glow on hover; fallback to gradient-initials when no upload.

**Phase 1 polish — propagation + remove avatar**: `ListingResource` + `GameMatchResource` (creator / taker / winner) expose `avatar_thumb_url`; 6 list components (chat-message-bubble, match-info-card, match-list-row, listing-row, listing-card, profile-match-row) render `<AvatarImage>` when present, falling back to initials. `DELETE /settings/profile/avatar` route + ghost-variant "Remove" button on the settings page (visible only when an avatar exists, no preview staged). Idempotent.

### Phase 2 — Stats hero (2026-05-25)

Two-tile stats row on the public profile: **Total matches** (settled-only count) + **Total volume staked** (sum of the user's own stake across settled matches, not pot total). Third tile (**Win rate**) visible to owner only — computed `wins / (wins + losses)` (draws excluded from denominator), rendered as `67%` (or `—` when no decided matches) with `W·D·L` breakdown underneath. Single CASE-aggregation Eloquent query. Empty-state ("No matches yet") uses dashed-border tile variant. All stats are all-time.

**Why win rate is owner-only**: showing it publicly creates a farming vector — strong players hunt low-win-rate opponents. Skill matching is already handled at the listing layer (`skill_min` / `skill_max`); the chess.com / Lichess rating reachable via link-out is the *public* skill signal.

Broader visual restyle (hero section, Active Mode pill, verification badges) deferred to M19's full redesign.

### Phase 3 Slice A — link-out + schema normalisation (2026-05-25)

Verified chess handles link out: chess.com / Lichess usernames on the public profile are now anchors to the external profile (`https://www.chess.com/member/{user}` / `https://lichess.org/@/{user}`), opening in a new tab with an `ExternalLink` icon. The click-through is the public skill signal — Alice sees the full external rating + history + activity rather than an inline rating, avoiding API-integration / cache / refresh-job complexity.

Linked-account schema normalised out of `users`: `chess_com_username` / `chess_com_verified_at` / `lichess_username` / `lichess_verified_at` and 4 `pending_verification_*` columns moved into two new tables — `linked_accounts` (UNIQUE(user_id, provider) + UNIQUE(provider, username)) and `pending_verifications` (UNIQUE(user_id), TTL-bound, plaintext code). New `LinkedAccount` + `PendingVerification` models; `User` keeps backwards-compat accessors that read off the eager-loaded `linkedAccounts` relation so callers (`UserProfileResource`, Filament infolists, controllers) don't need to migrate at once. Done while pre-real-users so M15 multi-game expansion adds provider rows instead of widening the `users` table.

### Phase 3 Slice B.1 — completion rate chip on profile (2026-05-26)

Pivoted mid-design from the originally-scoped dispute/cancellation rate badges. The Bybit P2P model — composite "completion rate" + 3-free-cancellation buffer + listing-row display + "more info" modal — fixed the original design's flaws: (1) cancellation rate badge punished the cooperative-exit feature (mutual cancellation is *good* behavior); (2) dispute predicate marked both parties of a dispute, including the victim; (3) threshold colors (green/amber/red) were calibrated on pre-launch guesses.

`UserController::show` aggregation reworked: drops `TRUST_MIN_SAMPLE`, adds `FREE_CANCELLATIONS_PER_PERIOD = 3`. Single SQL pass computes `settled_30d` / `settled_lifetime` / `cancellations_30d` / `cancellations_lifetime` / `disputes_lifetime`. Formula: `settled / (settled + max(0, cancellations - 3))` for the 30-day rate; lifetime has no buffer. Null when denominator is 0. `ProfileTrust` TS interface; `ProfileShowProps` extended. `CompletionRateChip` component — pill `{rate}% · {n} matches`, prefers 30d rate, falls back to lifetime, hides when `settled_lifetime === 0`. Click stub for the Slice B.2 modal (in-flight). Mounted between `ProfileHeader` and `StatsCard` on `users/show.tsx`. 9 new feature tests covering: empty-user nulls, single-match 100%, 3-free buffer absorbs, 4th cancellation pulls rate to 83%, other-party cancellations don't count, 31-day-old match drops 30d / stays lifetime, lifetime has no buffer (5/9 = 56%), `disputes_lifetime` catches disputed-then-settled + currently-disputed, pending matches excluded.

**Cleanup ships landed alongside Slice B.1**:
- `MatchOutcome` enum removed + `creator_confirmed_outcome` / `taker_confirmed_outcome` columns dropped from `game_matches` (orphaned after M16; pre-real-users so just edited the migration + `migrate:fresh`).
- M16 stale-comment sweep: `MatchStatus` docblock, `GameMatchPolicy` class doc, `PostSystemMessageAction` docblock, `match-timer.tsx` (prop comment + function docstring + aria-label), `match/show.tsx` (the "4-hour confirmation window" comment + the obsolete "Reverb deferred to M10" line).
- Restrict-delete FKs on `listings.user_id` and `wallet_transactions.user_id` (was cascade). Users with escrowed money or ledger history are now undeletable at the DB level. Three new tests in `WalletTest.php` using the `DB::transaction(fn() => $u->delete())` SAVEPOINT pattern to recover from the FK violation inside the outer test transaction.

### Decisions

- **Avatar default is initials-on-gradient** for users who don't upload. Keeps the look consistent.
- **Bio is plain text** with line breaks, escaped on render. Length cap 500. Heavier anti-abuse (URL stripping, link sanitization) is M13.
- **Live rating display considered + dropped.** Link-out covers the trust need without an API integration / cache / refresh job.
- **Schema normalisation done now**, pre-real-users — cheapest moment. M15 multi-game expansion adds provider rows without re-migrating.
- **Win rate is owner-only.** Public win rate would invite strong players to hunt weak ones. Chess.com / Lichess rating is the public skill signal.
- **Completion rate = single composite metric**, not per-failure-mode rates. See the architectural decision in `milestones.md`.
- **3-free cancellations per 30 days on the 30d rate; no buffer on lifetime.** Mutual cancellation stays usable cooperatively in the short term; lifetime is the unvarnished record.
- **No "ManualReview-stuck" counted as incomplete.** Admin always settles MR eventually; counting MR-in-flight as incomplete would penalize users for admin latency. Considered + dropped.
- **Restrict-delete FKs** on listings + wallet_transactions. Users with escrowed money / ledger history are undeletable at the DB level. The right safety net for a custodial money platform.

### Deferred from M18 (still load-bearing)

- **Player-to-player reviews / ratings after matches.** Deferred pending a coercion-resistant design. The straightforward "rate every match 1–5 stars" pattern invites coercion ("give me 5 stars or I'll dispute") on a P2P money platform. Trigger to revisit: a design that mitigates that pressure (anonymized aggregation, scoped to large-volume users, etc.). When reviews are eventually added, the Bybit-style thumbs-up/down summary (`👍 98 / 👎 0`) slots into a "more info" modal alongside completion rate.
- **Achievement badges / gamification.** Tempting but feels off-brand for a money platform. Revisit if usage data shows users want it.
- **Activity feed / follow graph.** Stakly isn't a social network; defer indefinitely.
- **Account deletion / data export.** Belongs in a separate compliance-focused milestone — user-owned scope per the no-legal-concerns rule.
- **Skill progression chart (rating over time).** Would need to snapshot ratings into Stakly DB rather than fetch live. Defer to a future "stats deepening" slice.

---

## M19 — Profile page redesign + management hub ✅ shipped 2026-05-27

Bybit-inspired information architecture (header → trust → tabbed activity → owner-only management) wearing Stakly's dark + pink/purple identity. Same URL, single page, dual mode: visitors see the read-only public sections, owner sees those plus a clearly demarcated owner-only block. Mode-switching keyed off `auth.user.id === profile.id`. Game-agnostic from day one so M15's multi-game expansion drops adapters in without re-layout.

### Phase 1 — Visual restructure foundation

Profile-page cards switched from translucent `bg-card/60` to full-opacity `bg-card` + consistent border treatment so they visibly float above `bg-background`. Page-level layout grid + `gap-6` between sections. System-wide card token sweep stayed out of scope.

### Phase 2 — Hero redesign

`ProfileHeader` revamped: larger avatar (`size-20 md:size-24`) with magenta glow on hover, display-font name, member-since pill, bio prominent. Verification chips with platform-tinted borders (chess.com brown, Lichess gray; FACEIT orange / Riot red / Steam blue tokens prepped in code comments for M15). Linked accounts as horizontally-scrolling chip strip on mobile. Edit-profile button stays top-right (owner-only).

**Deviations from spec, decided during testing:**

- ~~Completion rate chip moved into the hero row~~ → removed. The Data Overview tile below already shows the same info; chip in hero felt redundant. `CompletionRateChip` component deleted entirely (this also moots the originally-planned M18 Phase 3 Slice B.2 modal + Slice B.3 listing-row chip — there's no chip to modal anymore).
- ~~Active / Inactive mode pill (owner-only) in hero~~ → removed. The toggle stays on `/listings/mine` via existing `ActiveModeToggle`; surfacing it in hero added clutter. `ActiveModePill` component deleted.

### Phase 3 — Trust strip + Data Overview

`TrustStrip` component below the hero as a dedicated row, not mixed into the stats grid. Three trust tiles (completion 30d / completion lifetime / disputes lifetime) plus a conditional repeat-pair callout above ("You've played N settled matches against this player") gated to authenticated viewers on someone else's profile with 2+ shared matches. Backend repeat-pair = single COUNT join to `listings`, both pair directions. Stats grid stays 2-up visitor / 3-up owner. Win-rate gradient bar beneath W/D/L in the WinRateTile (own-profile only, brand-gradient width-proportional). Game-agnostic — headline stats stay unified across games.

### Phase 4 — Tabbed activity section + MineTabs migration

Three-tab section (Match History default | Open Listings | Reviews placeholder) via newly-installed shadcn Tabs primitive, Stakly-skinned at the source: brand pink underline (`after:bg-primary`), `bg-primary/15` active fill, `bg-primary/10` hover, `ring-2 ring-primary/25` focus, `dark:` variants dropped (Stakly is dark-only). Two variants — `default` pill + `line` underline. Lives in `components/ui/tabs.tsx`. Tab state syncs to `?tab=` via pushState + popstate (auth-modal-provider pattern). Pure client-side switching — data is already loaded by the controller, no re-fetch on tab click.

`MineTabs` migrated to the same primitive (was hand-rolled `<button role="tab">`) for cross-page consistency. Kept `router.get()` re-fetch (data differs per tab) + added optimistic local state + `preserveState: true` so the underline transition completes smoothly.

**Phase 4 extras (not in original spec):**

- "My profile" link enabled in `ProfileMenu` (avatar dropdown) + `MobileMenu` (was disabled with "Soon" badge); both link to `/users/{auth.user.username}`.
- Profile link added to `PlayerSidebar` as the top item (lucide `User`). Active when `url === /users/{auth.user.username}`.
- **Layout switch on profile:** own-profile renders inside `PlayerHubLayout` (sidebar visible), visitor view stays in `SiteLayout`. Bybit P2P-User-Center-style integration. Picked by `auth.user?.id === user.id`.
- **Width normalization:** profile + `/wallet/history` migrated `max-w-4xl` → `max-w-5xl` to match `/listings/mine`, `/matches`, `/wallet`. Padding standardized to `px-4 py-10 md:px-6 md:py-14` across the player hub. `/wallet/deposit` + `/wallet/withdraw` kept at `max-w-lg` (narrow forms, intentional).
- **Seeder rework (`MatchHistorySeeder`):** every marketplace user now gets 4–6 matches via a skill-tier cycle (`index mod 4` → strong / balanced / balanced / casual). Visiting any random profile shows realistic stats (29–73% win rates) instead of accidental 100% from tiny opponent-only samples.

### Phase 5 — Share profile + OG meta (owner-only block)

Trimmed scope from the original spec — privacy toggles + Notifications/Blacklist placeholder tabs dropped (see Decisions). Owner-only "Your account" block below the public tabs, visible only when `auth.user.id === profile.id`, subtle `bg-secondary` shading. Single block, not sub-tabbed.

- **Share profile button** (`ShareProfileButton`) — popover with QR code via existing `qrcode.react` + copy-URL with Sonner toast. Mirrors wallet/deposit QR treatment and `AddressDisplay` copy pattern.
- **Open Graph + Twitter card meta** on `/users/{username}` via Inertia `<Head>`. SSR (via `@inertiajs/vite`) puts the tags in the initial HTML so crawlers see them. `og:title` = "{name} on Stakly", `og:type` = "profile", `og:url` and `og:image` absolute (relative paths break crawlers). `og:image` points at `apple-touch-icon.png` as a placeholder — swap to a 1200×630 branded card at `public/og-default.png` when one lands.

Two feature tests added: og payload shape + absolute-url enforcement.

### Phase 6 — Polish (Slices A + B + C)

**Slice A — Empty states.** Threaded `isOwnProfile` from `users/show.tsx` down to `MatchHistorySection`, `ListingsSection`, and `StatsCard`. Owner empty states get inviting CTAs ("Browse the marketplace →" linking to `/listings`, "Create your first listing →" linking to `/listings/create`); visitor empty states stay descriptive. Stats card empty state collapses the previous two repeating "No matches yet" dashed tiles into a single card with one CTA.

**Slice B — Interaction polish + mobile audit.** Fixed the `outline` button variant in `components/ui/button.tsx` at the source — was leaking shadcn defaults (`bg-background`, `border-input`, `hover:bg-accent` = purple), now uses Stakly tokens (`border-border/60`, `bg-card/60`, `hover:border-primary/40 hover:bg-primary/10` = pink wash). Fix at source improves 9 callsites uniformly. Tab content fade-in on switch via `tw-animate-css` `data-[state=active]:animate-in fade-in-0 duration-200`. Mobile audit: code-review pass at 375px — profile page already responsive, no code changes needed.

**Slice C — Accessibility.** Focus rings normalized to `ring-primary/25` across `ProfileMatchRow` + `ProfileListingRow` (were full-opacity `ring-primary`, inconsistent with the rest of the app). `aria-hidden="true"` on decorative icons (Trophy / Handshake / X result chips, Clock meta icons, Share2 / Check / Copy in the share popover). Empty-state CTA arrows wrapped in `<span aria-hidden="true">` so screen readers don't announce "right pointing arrow." Keyboard nav across tabs handled natively by Radix (Arrow / Home / End). Contrast spot-check: `text-foreground` on `bg-card` ≈ 17:1 (WCAG AAA pass); `text-muted-foreground` on `bg-card` ≈ 5.2:1 (AA pass).

### Decisions

- **Owner-only block uses `bg-secondary`, not `bg-card`.** Visual differentiation from the public sections without being garish — reads as "this section is for you."
- **Privacy toggles deferred.** Hiding completion rate fights the marketplace-trust pitch on a money platform. Ship if/when real users ask AND the request is genuine privacy (not concealing a poor record). No schema, backend, or UI in M19. See `feedback_trust_signals_default_on` in memory.
- **Placeholder tabs for Notifications + Blacklist dropped.** Placeholder UI advertising vaporware adds noise + a maintenance cost for zero value today. M20 + M21 will own their UI surfaces end-to-end (likely `/settings/notifications` and `/settings/blacklist`). See `feedback_no_placeholder_ui` in memory.
- **Multi-game readiness from day one.** Every game-aware component dispatches by `Game` / `LinkedAccountProvider` enum so M15's FACEIT / Riot / Steam adapters drop in without re-layout. M19 ships only chess renderers (the only functional game today), but the contracts exist.
- **`hover:shadow-glow-sm` reserved for the avatar.** Glow on every clickable surface would dilute the brand signal. Buttons + chips get the lighter pink-wash hover.
- **Static branded OG image deferred** until a 1200×630 PNG asset exists. Apple-touch-icon (180×180) is the placeholder — links still render with an image in Discord / Telegram, just smaller than the OG standard.
- **No tab-switch animation via `motion`.** CLAUDE.md's "tw-animate-css for Radix primitives" rule overrode the spec's `motion` suggestion. Lighter code, no AnimatePresence wrapping, no risk of fighting Radix.
- **Per-game `MatchRow` dispatcher deferred to M15.** Chess is the only renderer needed today; the per-game-shape work belongs with the multi-game adapter milestone.

### Not in M19

- **Notifications feature itself** — M20 owns the UI surface end-to-end.
- **Blacklist feature itself** — M21 owns the UI surface end-to-end.
- **Privacy toggles** — deferred pending real user demand + the genuine-privacy check.
- **Reviews feature** — deferred pending a coercion-resistant design. M19 ships only the public-section placeholder tab.
- **Per-game stat splits** — composite rate stays unified; splits can ship as a slice later if usage data calls for it.
- **System-wide card surface token sweep** — separate polish slice after M19 validates the look.
- **Custom OG image per user** (dynamic server-rendered card with stats) — Phase 5 ships a static placeholder; per-user dynamic OG is future polish.
- **Profile editing forms (avatar / bio / linked accounts / security / password)** — those stay at `/settings/*`; M19's owner-only block is share + OG only, not duplicating settings.

---

## M22 — Listings page trust + clarity ✅ shipped 2026-05-27 → 2026-05-28

Compared to Bybit's P2P listings, `/listings` rows had a "should I trust this stranger" gap: skill range + game format but no signal of whether the seller was experienced, reliable, or even verified on the listing's platform. M22 closed that gap in three phases — trust signals + verified-platform chip on the row, visual hierarchy sharpening (stake prominence, time-left urgency colors, column headers), and a Take button that telegraphs eligibility before click.

### Phase 1 — Trust signals on the row

New `App\Services\SellerTrust` service with `forBatch(array $userIds)` doing ONE join of `game_matches` + `listings` to aggregate per-user `[rate_30d, settled_lifetime]` (PHP-side attribution to handle creator-OR-taker cleanly). Mirrors `UserController::show` formula (3-free cancellation buffer on 30d, none on lifetime). Convenience `attachTo(iterable $listings)` wraps it for controllers, attaching `seller_trust` as a transient model attribute. Wired into `ListingController::index` + `show` + `mine` and `HomeController::index`. `ListingResource` exposes `creator.completion_rate_30d` (int|null) + `creator.settled_lifetime` (int).

`SellerTrustMeta` component renders inline meta text under the creator name (Bybit-style "503 Order(s) | 91%" pattern) — `{rate}% · {n} matches`, with native title-attribute tooltip. Hides entirely when `settled === 0`. Lives in the creator block, not the badges row, so it reads as continuation of the creator metadata.

`VerifiedPlatformChip` shows the listing's required platform (chess.com brown / Lichess gray, same tokens as profile-page `VerificationChip`). Lives in the badges row.

**Earned cross-platform badge** (added within Phase 1 scope): `creator.verified_providers: ListingPlatform[]` exposed via eager-loaded `user.linkedAccounts`. The green `BadgeCheck` icon in `SellerTrustMeta` appears ONLY when `verified_providers.length >= 2` — cross-platform credential as an earned signal, not decoration. Future-proof for M15 (chess.com + FACEIT or any combination of 2+ providers earns it).

Tests: 7 new in `ListingIndexTest` covering payload shape, 0-match defaults, 30d-window math, 3-free buffer, N+1 guard (query-log assertion on the join shape), and verified-providers lists for cross + single platform creators.

### Phase 2 — Visual hierarchy

- **Stake prominence**: row stake bumped `text-2xl → text-3xl` to match the featured card and anchor the row visually rather than visually competing with the Take button.
- **Time-left urgency colors**: new `getTimeUrgency(isoString): TimeUrgency` helper returning `'expired' | 'critical' | 'warning' | 'normal'` at 15m / 1h thresholds. Applied to row + card time-remaining indicator via a `urgencyTone` switch (destructive < 15m, warning < 1h, muted otherwise). `isEndingSoon` kept for simpler surfaces (`listings/show`, `mine-listing-row`).
- **Column header strip**: desktop-only (`hidden md:flex`) labels above the listings list — Player · Match · Ends in · Stake — aligned to the row column widths.

### Phase 3 — Take button eligibility states

New `TakeButton` component reads `auth.user` via `usePage()` + `openLogin` via `useAuthModal()`. Early-return on guest so TypeScript narrows `user` to non-null for the rest. Four branches:

- **Guest viewer** → gradient pill "Sign in to take" with `onClick={openLogin}` opening the modal in place (no page transition).
- **Owner** → outline pill "Manage" linking to `/listings/mine`. Same size/shape as Take so the owner row's column matches ordinary-Take rows.
- **Wrong-platform** (`!user.linked_platforms.includes(listing.platform)`) → outline pill "Link {platform} to take" linking to `/settings/linked-accounts`. Forced `rounded-full` because the outline variant's base is `rounded-md`.
- **Eligible** → gradient pill "Take" linking to listing detail (current behavior preserved).

Used in `listing-row.tsx` + `listing-card.tsx`. Each surface passes its own layout className (`w-full md:w-auto` for the row, `relative mt-auto w-full` for the card's flex-column bottom-pin). `listings/show.tsx` deferred — the detail page already has its own elaborate eligibility tree (owner-inactive, insufficient-balance, Take dialog with confirmation) that doesn't compress without losing features. M23 picks up the detail page polish.

### Decisions

- **Trust as Bybit-style inline meta, not chip.** The trust info reads as continuation of the creator metadata next to region, not as a separate badge alongside listing badges.
- **Trust chip hides on 0-match users.** A "—% · 0" chip is noise. Better to absent the signal entirely until the player has a track record.
- **Earned badge = cross-platform, not gamification.** Single green check, single threshold (`>= 2 providers`). Future-proof for M15 without inviting an achievement system. See [[feedback_no_placeholder_ui]] family — we add badges that mean something, not visual flair.
- **No "online now" / live presence dots.** Stakly's `is_active_mode` already signals "available to play"; live presence would require Echo channels for low payoff.
- **No "Fast settler" badge.** Stakly settles automatically via auto-fetch; no per-user release speed to measure.
- **Stake is the visual anchor, not the Take button.** Bybit's price is the biggest thing in the row; ours should be too.
- **Don't refactor `UserController::show` to use `SellerTrust` helper yet.** Phase 1 duplicates the formula in `UserController` + `SellerTrust`; consolidation can come later if drift becomes a real concern.

### Not in M22

- **Sort-by-trust / filter-by-completion-rate.** Could land later if usage shows users want to slice by trust. Don't speculate.
- **Repeat-pair callout on listing rows.** Profile page shows it; adding to rows is visual noise. Defer until users ask.
- **Per-game row shapes.** Chess is the only game today. M15 brings per-game renderers.
- **Listings detail page polish.** Detail page deferred to M23.

---

## M23 — Listings detail page polish ✅ shipped 2026-05-28

M22 lifted `/listings` to a Bybit-class marketplace; the detail page was still on shadcn-defaults — sparse creator card, `bg-card/60` translucent surfaces, no time urgency tier, no pot breakdown. M23 brought parity with the listings row aesthetic and added the details a detail page is uniquely positioned to show: bio, member-since, linked-account chips that click out to external profiles, and pre-take pot/fee/payout math so a viewer knows exactly what they're committing to before clicking Take.

### Phase 1 — Creator card uplift + visual parity

`ListingResource` creator block gained `bio`, `member_since`, `linked_accounts` ({provider, username}[]). The new `linked_accounts` carries both the provider AND the username because the detail-page chip strip needs the username to click out to each external profile — distinct shape from `verified_providers` (provider IDs only, used by the M22 SellerTrustMeta badge). `ListingController::show` widened its user column whitelist to `id,name,username,is_active_mode,bio,created_at`; other surfaces (index, mine) keep the narrow whitelist since they don't render bio/member-since.

`listings/show.tsx` creator card refactored to a profile-card analogue: `AvatarImage` fallback added (was always falling back to initials even when avatar uploaded), `SellerTrustMeta` inline under name (Bybit-style), then a chip strip with `VerifiedPlatformChip` (listing's required platform) + per-linked-account `VerificationChip` (clickable, external) + Joined-{month-year} pill, then bio with `whitespace-pre-line`.

Match-details card: surface token `bg-card/60` → `bg-card` (all three detail-page cards), added Language cell to the grid, `getTimeUrgency` from M22 Phase 2 paints Expires field amber < 1h / destructive < 15m (replaces the binary `isEndingSoon` boolean), and a muted "Posted {Medium-Date}" foot line. Region + Language moved OUT of the creator card inline meta into the match-details grid — cleaner "who vs what" separation.

Tests: 3 new in `ListingShowTest` covering full creator payload (cross-platform creator with bio), null-bio fallback, and empty-linked-accounts fallback. Suite 775 / 3309.

### Phase 2 — Stake action card breakdown

`ListingResource` exposes top-level `fee_rate: float` (mirror of `GameMatchResource::fee_rate`) — single config-sourced rate, FE computes pot = stake × 2, fee = pot × fee_rate, winner_payout = pot − fee. Listings page surfaces don't currently render the breakdown but the resource shape stays consistent across index / show / mine for the cost of one float per listing.

`listings/show.tsx` stake card: hero stake number unchanged; new `StakeRow` helper renders Your stake / Opponent stake / Pot total (bold) / Platform fee (muted, with `−$X.XX` minus sign) / Winner payout (gradient accent — same idiom as `MatchInfoCard`'s `Row`). Breakdown gated on `isOpen && !isOwner`: owners already know the numbers, non-Open listings hide pre-take math (match page owns the post-take view), participants get "View match →" instead. Single `my-6 border-t` divider between breakdown and CTA; take-area wrapper switches `mt-6` on/off based on whether the divider supplies the separator.

CTA polish for the wrong-platform branch: replaced the old disabled-gradient + separate hint link with a single clickable outline pill "Link {platform} to take" linking to `/settings/linked-accounts`. Matches the M22 Phase 3 `TakeButton` idiom (`asChild Link`, forced `rounded-full`).

Tests: 1 new asserting `listing.fee_rate` matches config. Suite 776 / 3317.

### Mobile fix (post-Phase-2)

Single root cause for two symptoms — CSS grid items default to `min-width: auto` (content-min-size), so any wide child (chip strip, breakdown row) grew the grid cell past viewport on mobile, making the whole page horizontally scrollable. The chips at the card edge clipped mid-pill; the stake card's row values disappeared off-screen on the right.

Fix: `min-w-0` on both grid cells in `listings/show.tsx` (left column + right aside) lets them shrink to viewport, so inner `overflow-x-auto` / wrap finally works. Same fix applied to `components/profile/profile-header.tsx` where the chip strip exhibited identical clipping (`Joined ...` pill truncated at card edge).

While there: chip strips on BOTH `listings/show.tsx` and `profile-header.tsx` switched from `flex-nowrap overflow-x-auto sm:flex-wrap` (horizontal scroll on mobile) to plain `flex-wrap` everywhere. The horizontal-scroll-on-mobile pattern wasn't worth the visual cost of mid-pill clipping at the card boundary; wrapping shows every chip cleanly at the cost of a slightly taller card.

### Decisions

- **Detail page gets MORE info than the row, not less.** Row is scan; detail is consider. Completion-rate meta + bio + linked-accounts strip + pot breakdown all live on the detail page where Bob has time to read.
- **Region + Language moved into match-details grid, out of creator card.** User chose this in a Phase 1 design question — cleaner "who vs what" separation (region/language describe the listing, not the identity). The match-details grid is the natural home.
- **`fee_rate` exposed top-level on `ListingResource`, not computed inline.** Mirrors `GameMatchResource` idiom; trivial wire cost (one float per listing) buys consistent resource shape + single config source for the rate.
- **Take dialog stays.** The confirmation dialog ("You're about to stake $X USDT. Once it starts, your stake is locked...") is good UX — M23 polishes around it, doesn't replace.
- **No `TakeButton` component refactor on the detail page.** The detail page's eligibility tree has unique branches (insufficient-balance, owner-inactive, take-dialog confirmation) that don't fit the shared component. M22 Phase 3 deferred this intentionally; M23 kept the deferral. The wrong-platform branch IS now visually aligned with `TakeButton` (single outline pill) without sharing the component.
- **Chip strips wrap, don't horizontal-scroll, on the listing detail + profile pages.** The clipping-mid-pill failure mode at mobile widths wasn't worth the consistent-card-height payoff. Detail-page can now show 4 chips comfortably (platform + 2 linked accounts + Joined) without clipping; profile-page benefits from the same fix.

### Not in M23

- **Match-history sidebar of the creator on the detail page.** "This player's recent matches" could live in the left column but the data load + visual cost isn't worth it before users ask. Defer until requested.
- **Live other-listings strip** ("More from this player"). Same reasoning.
- **Take-time prediction** ("Average time to start: 6m"). Bybit-style metric; we don't track this. Defer indefinitely.
- **System-wide card token sweep.** Detail-page-only here; other pages stay on their tokens until a dedicated audit slice.
- **System-wide chip-strip wrap audit.** Only the two surfaces with the visible clipping issue got fixed (listings detail + profile header). Other chip strips can be revisited if the same failure mode appears.

---

## M24 — Game catalog (admin-managed posters) ✅ shipped 2026-05-28

The homepage `GameSelector` row went from a hardcoded React array of icon-tinted placeholders to a DB-backed catalog of vertical poster tiles, admin-managed via Filament. Art, ordering, status, and new-game additions now flow through `/admin/games` without code changes. Visual treatment matches the mmrangels reference (~3:4 vertical posters, hover lift + magenta glow on selected, "Soon" badge for non-Active tiles).

### Backend slice

`games` table (slug unique, display_name, poster_path nullable, position int, status string, timestamps). `App\Enums\GameStatus` (`Active` | `ComingSoon` | `Disabled`) replaces the two-boolean approach — single source of truth, can't contradict itself. `Game` model + factory + observer-free cache invalidation via `booted()`: `creating` hook auto-appends new rows to `MAX(position) + 10` so admin never sees a position field; `saved`/`deleted` busts `homepage:games`. `Game::forHomepage()` scope returns Active + ComingSoon ordered by position (Disabled hidden). `Game::hasBackendIntegration()` is true only when status is Active AND slug matches an `App\Enums\Game` enum case.

`GameSeeder` mirrors the existing row (chess Active + cs2/dota2/valorant/lol/pubg/apex/rocket-league/overwatch ComingSoon), seeding `poster_path` against the pre-existing `public/images/games/*.{jpg,png}` files. Filament uploads land in `storage/app/public/games/` via the standard `disk('public')` symlink; `GameResource` (HTTP) normalizes both source styles — root-relative `/images/...` paths pass through unchanged, disk-relative `games/...` paths get `/storage/` prepended.

`HomeController::index` queries `Game::forHomepage()->get()` wrapped in `Cache::remember('homepage:games', 1h, ...)`. The cached value is the RESOLVED resource array (`GameResource::collection(...)->resolve()`), not the Eloquent Collection — caching the Collection round-trips through the Postgres cache driver's serialize path and crashes on `__PHP_Incomplete_Class` when read back. Stored as a plain array of dicts, it round-trips cleanly.

### Filament admin

`App\Filament\Resources\Games\GameResource` is a `--simple` resource (single ManageGames page). Form fields: display_name, slug (unique + alpha-dash + custom rule), poster `FileUpload` (`disk('public')`, `directory('games')`, `imageEditor()`, resize to 600×900 via `imageResizeMode/Width/Height`, min-dimension guard `480×640`), status select. **No position field on the form** — auto-append covers create, drag-to-reorder covers edit. **Form is fully static** (no `->live()` anywhere) — earlier iterations used `->live(onBlur: true)` on display_name to auto-populate slug, but that fired a Livewire roundtrip on field-blur which dismissed the Status select dropdown on its first open. Dropping auto-slug eliminated the flicker; admin types both fields (kebab-case slug is trivial for < 20 games).

Table uses `reorderable('position')` for drag-to-reorder. Filament's `reorderTable` issues a raw SQL `update` with a CASE expression — it **bypasses Eloquent model events**, so the model's `saved`-hooked cache bust doesn't fire on reorder. Explicit `afterReordering(fn () => Cache::forget(Game::HOMEPAGE_CACHE_KEY))` on the table closes that gap.

Server validation rule: `status = Active` only allowed when `App\Enums\Game::tryFrom($slug) !== null`. Admin can add coming-soon display tiles freely; flipping one Active still requires the enum case in code (M15 per-game adapter work).

### Frontend slice

`GameSelector` rewritten to consume a `games` prop instead of its hardcoded `GAME_TILES` const. Lucide icon imports + `GameTileId` union + `GAME_TILES` export all removed. `selectedSlug` is now `string` (the slug from the DB row). Tiles render `<img src={poster_path}>` full-bleed with `object-cover object-center`, `loading={index < 3 ? 'eager' : 'lazy'}`, hover scale `group-hover:scale-105` (`motion-reduce` opt-out), `border-[3px]` thickness, magenta glow via the new `--shadow-arena-card-glow` token. Tiles without a `poster_path` fall back to a default magenta-gradient + `display_name` overlay so admin adding a game before the art lands doesn't break the page.

`welcome.tsx` reads `games: { data: GameTile[] }` from Inertia, defaults `selectedSlug` to the first tile's slug, filters the featured-listings strip on `tile.status === 'active'` (only chess returns real listings today).

### Glow token surgery

Mid-build the user bumped the global `shadow-glow` utility to a beefier value to make the arena tiles pop. That leaked to every consumer of `shadow-glow` / `shadow-glow-sm` (listings rows, wallet cards, profile avatars, chat link cards, auth inputs — ~15 surfaces) as a heavy purple halo. Reverted both global utilities to the original soft `0 0 18px -7px` haze and added `--shadow-arena-card-glow: 0 0 19px -3.5px var(--gradient-glow)` as a component-specific CSS variable. The arena cards reference it inline via `shadow-[var(--shadow-arena-card-glow)]` per CLAUDE.md's "component-specific shadow values live as CSS variables" convention; everyone else keeps the soft halo untouched.

### Tests

10 new Pest tests across `tests/Feature/HomeIndexTest.php` (+4: prop order, Disabled excluded, whitelisted shape, admin-upload URL resolution) and `tests/Feature/GameModelTest.php` (+6: `forHomepage` + `ordered` scopes, `hasBackendIntegration`, cache bust on save / delete, slug uniqueness). Suite 786 / 3380.

### Decisions

- **Game model = display catalog; `App\Enums\Game` = backend identity.** Two parallel representations on purpose — admin can add tiles without code changes, but a tile only becomes Active (players can actually stake on it) when both layers agree. The slug column is the join key. Future M15 adapter work adds enum cases; admin then flips Active.
- **Status enum, not two booleans.** `Active` | `ComingSoon` | `Disabled`. Two-boolean schemas can contradict (`is_active=true` and `is_coming_soon=true`); the enum forbids the impossible state.
- **`position` is a sort key, not a 1-indexed row slot.** Seeder uses 10/20/30/.../90 so inserts have gaps. The admin form **does not expose** position — auto-append (`MAX(position) + 10` in the `creating` hook) handles new rows; drag-to-reorder handles changes. Typing a number into a "position" field confused the user (typed 3, expected slot 3, got slot 1 because 3 < 10).
- **Static Filament form (no `live()`).** Reactivity on inputs fires Livewire roundtrips on field-blur, which can dismiss freshly-opened Selects. For a < 20-row catalog the auto-slug convenience wasn't worth the dropdown flicker.
- **Cache the resolved array, not the Collection.** `Cache::remember` of an Eloquent Collection crashes the Postgres cache driver's unserialize path with `__PHP_Incomplete_Class`. The resolved resource array (plain dicts) round-trips cleanly.
- **Filament reorder bypasses Eloquent events.** `reorderTable` uses raw SQL for atomicity. Cache-bust must hook `afterReordering` on the table, not rely on `saved`.
- **Arena card glow lives in its own CSS variable.** Bumping the global `shadow-glow` utility leaked to every consumer (listings, wallet, avatars). `--shadow-arena-card-glow` keeps the arena-row treatment isolated.
- **WebP conversion deferred.** Filament v5 dropped `imageResizeOutputFormat()`; the replacement is a custom `saveUploadedFileUsing` callback with Intervention/Image. For ~9 posters at 600×900, original JPG/PNG is fine. Revisit if homepage perf budget demands it.
- **Coming-soon click is inert.** No tooltip, no "notify me when live" lead capture. Adds surface area without clear payoff today.

### Not in M24

- **Hooking `listings.platform` / `matches.game` to `games.id`.** That's M15 — when the first non-chess game lands its adapter, the listings + matches schema migrates to a foreign-key relationship on `games`. Phase 1 leaves listings untouched.
- **"Notify me when live" lead capture per coming-soon tile.** Marketing slice if/when pre-launch interest capture becomes a priority.
- **Admin RBAC per-resource.** Existing Filament panel auth (admin user gating) is sufficient. Per-resource roles only if multiple admins eventually need scoped access.
- **Filament "preview row" page** for visual-consistency check before publishing. Overkill for a solo dev managing < 20 tiles; revisit if mismatched posters become an actual problem.
- **WebP conversion pipeline** (see Decisions). Lives as a future-polish slice, not blocking.

---

## M25 — Lichess OAuth (StaklyBot bot account) ✅ shipped 2026-05-29

Authenticated every outbound Lichess request as the registered `StaklyBot` account. Pre-M25 calls were anonymous — landed in Lichess's anonymous rate-limit bucket and were untraceable to a known client. Post-M25 they carry `Authorization: Bearer <token>` from a personal access token stored in `.env` as `LICHESS_API_TOKEN`, exposed via `config('services.lichess.token')` (Laravel convention for third-party tokens — sits alongside Mailgun / Postmark / Stripe rather than in the `stakly.*` business-config namespace).

Three call sites updated in lockstep — `LichessGameClient` (both `fetchGame` and `searchGamesBetween`), `LichessProfileClient::fetchProfile`, and `LichessStreamCommand::openStream` (curl `CURLOPT_HTTPHEADER`). Each grew a small private helper (`lichessHeaders()` / `buildStreamHeaders()`) that returns the base headers and conditionally appends `Authorization` only when the token is a non-empty string. The stream command's helper is `public` rather than `private` so the header-build logic is testable in isolation without spinning up curl — mirrors the same exposed-for-testability convention `dispatchFromEvent` already uses.

Setup steps documented inline in `config/services.php` (where to log in, where to generate the token, what scopes to tick — `none`, all the endpoints we hit are public reads). `.env.example` carries a placeholder + short comment so onboarding picks it up. 11 new Pest tests in `LichessAuthHeaderTest`: per call site, asserts the header is sent when the token is configured AND omitted when the token is null OR an empty string; one cross-call consistency test seeds a single token value and confirms all four surfaces send the same bearer verbatim. Suite 786 → 835.

### Decisions

- **Bot handle: `StaklyBot`.** Names itself as automation to Lichess support, separate from any personal account. Suspension blast radius limited to Stakly; token leak doesn't expose a human's chess account.
- **Config namespace: `services.lichess.token`, not `stakly.lichess_token`.** Matches the Laravel convention for third-party API tokens (Mailgun / Postmark / Stripe live there). `config/stakly.php` is reserved for Stakly business config (platform_fee_rate, chess_com_user_agent).
- **Anonymous fallback always allowed.** When the env var is unset (casual dev without the secret), every helper omits the `Authorization` header entirely. Wire shape exactly matches pre-M25 so existing `Http::fake()` assertions in other test files keep passing; no env dependency for dev or CI. Empty string treated the same as null — never send a literal `Bearer ` header that would identify us as a misconfigured client.
- **Scopes: none.** The four endpoints we hit (`/game/export/{id}`, `/api/games/user/{username}`, `/api/user/{username}`, `/api/stream/games-by-users`) are public reads that work with any valid token regardless of scope. Granting unused scopes (`msg:write`, `bot:play`, `challenge:write`) would only widen blast radius if the token ever leaks.
- **No chess.com equivalent.** Chess.com's Published Data API has no token / OAuth mechanism — their auth model is `User-Agent` containing a contact email, which `ChessComGameClient` already sends via `config('stakly.chess_com_user_agent')`. The two providers reach the same end state via different mechanisms.
- **Rate-limit header parsing deferred.** Lichess doesn't reliably emit `X-Ratelimit-*` headers on the read endpoints we use; a 429 response surfaces as `ProviderUnavailableException` → M14 P1 audit row with `outcome=error` → visible in `PipelineHealth`. The pipeline already catches the only signal that matters today. Add active header parsing later if Lichess starts sending consistent data.

### Not in M25

- **Chess.com authentication.** Their API has no token mechanism (see Decisions).
- **Lichess Bot API (`bot:play` scope / `/api/bot/*`).** Stakly observes games, doesn't play them. Account-upgrade-to-bot is one-way and would lock the account out of human play.
- **OAuth user delegation.** Each end user proves Lichess ownership via the existing M8 bio-code flow; we never need to act as the user on Lichess, only read public game data. Per-user OAuth tokens would add infrastructure (per-user storage, refresh tokens, revocation handling) for zero new capability.
- **Stream-only settlement path.** Stream is an optimisation over the 5-min cron + page-visit + chat-send triggers; the multi-trigger layering survives so a stream outage isn't a frozen-match scenario.

## M26 — Filament-managed CMS pages + global SSR + full-site i18n ✅ shipped 2026-05-29 → 2026-06-05

Moved About / Privacy / Terms / Support from hardcoded React pages to admin-editable database-backed content (`pages` table with `(slug, locale)` uniqueness, Filament `PageResource` with `MarkdownEditor` + signed-URL preview, locale-aware `forSlugWithFallback` resolver behind a `Cache::rememberForever` layer with event-driven invalidation). Then enabled global Inertia SSR (Path A) — the Docker `ssr` sidecar runs `inertia:start-ssr` against `bootstrap/ssr/ssr.js`, six render-time hydration hazards were fixed (timer / cooldown / sidebar / tab / auth-modal state), and a shared `PageMeta` component now drives per-page canonical + og + twitter tags on every public surface. Finally added full-site i18n: locale-prefix routing (`/{en|ka|ru}/*` everywhere, unprefixed → 301 redirect with cookie-aware target), `SetLocale` middleware + global `URL::defaults` fallback (covers admin / queue / console contexts that bypass middleware), Wayfinder `setUrlDefaults` on the client, `HandleInertiaRequests::share()` exposing `locale` / `availableLocales` / `translations` as **closures** so the request-locale binding is correct, `useT()` / `useLocale()` / `useAvailableLocales()` hooks, `LocaleSwitcher` dropdown in `SiteHeader` + `MobileMenu`, and 13 page-by-page extraction slices (D-1..D-13) covering listings, profile, match, wallet, notifications, settings, auth, BannedBanner, validation/flash/errors. `lang/en.json` ended at ~790 keys; `ka.json` / `ru.json` are content backlog (Laravel falls back to the key when a translation is missing, so the site stays usable while copy is written). New Inertia-rendered error pages (403/404/500/503) replace Symfony defaults; 419 / 422 stay on Inertia's default reload + inline-field-error handling.

### Decisions

- **URL pattern: path prefix everywhere.** `/en/listings`, `/ka/listings`, `/ru/listings` — same shape as M26 P1's CMS routes. SEO-correct multilingual pattern; unprefixed paths 301 to the user's remembered cookie locale (else `en`).
- **Laravel-native bridge, NOT `react-i18next`.** `lang/*.json` is the single source of truth for both PHP `__('key')` and React `t('key')`. Backend strings (validation, M20 emails) work out of the box. Swap to `react-i18next` later if ICU plural rules or lazy-loaded locale bundles become necessary — call sites change, translation files port cleanly.
- **`URL::defaults(['locale' => …])` MUST be seeded globally, not only by middleware.** `AppServiceProvider::boot()` sets the default so Filament admin / queue workers / console / Octane all get it. `SetLocale` middleware overrides per-request via `array_merge`. Without the global default, routes inside Filament resources crash with "Missing parameter: locale" because middleware never fires there.
- **Never call Wayfinder generators at module-top-level scope.** They run before `setUrlDefaults` and fall back to the literal `'$locale'` placeholder. Build nav arrays inside the component body. Active-state matching uses `walletIndex().url` for both `href` and `matchPrefix`, not hardcoded strings.
- **Inertia shared props that depend on the request locale must be closures.** Eager values capture the default `en` because Inertia's `share()` fires before route-level middleware sets the locale.
- **Admin (Filament) is exempt from translation.** `/admin/*` stays English-only — single-language, internal, reduces admin training. M12 disputes, M24 games, M26 pages, M30 users, M31 wallet ledger, M32 listings all bypass extraction.
- **User-generated content stays in its source language.** Listing notes, bios, chat messages — shown as typed. Machine translation is future polish if scale demands.
- **Detection: no `Accept-Language` sniff.** First visit always lands on `en`; user picks via switcher, cookie remembers. Avoids surprise redirects, simpler edge cases with VPNs / bots / crawlers / SEO.
- **CMS schema baked `locale` from day one.** `pages.UNIQUE(slug, locale)`. Adding a second language is a content task (one INSERT per slug-locale pair), not a migration.
- **Hand-coded routes per CMS page, not a wildcard `/p/{slug}` catch-all.** `/privacy`, `/terms`, `/about`, `/support` are first-class destinations with brand value.
- **Global Inertia SSR (Path A), not Blade-only for CMS.** SEO works on every Inertia page — homepage, listings index, listing detail, profile — not just the three CMS pages.
- **Vite hot routing overrides the SSR sidecar.** `Inertia\Ssr\HttpGateway::dispatch()` checks `Vite::isRunningHot()`. If `public/hot` exists, SSR goes to Vite's `/__inertia_ssr` endpoint (which isn't wired) and silently falls back to client rendering. For local SSR verification, kill `npm run dev` and `rm public/hot` first.
- **Inertia v3 `withExceptions->respond()` for 403/404/500/503 only.** 419 (CSRF) stays on the default reload toast — a full page would be the wrong UX for in-flight form expiry. 422 (validation) untouched — field errors render inline. Debug mode + non-Inertia (`curl` / direct asset) requests bypass the Inertia render so Whoops still works and JSON consumers don't get HTML.

### Not in M26

- **Page versioning / draft history.** Single live row per `(slug, locale)` + `updated_at` is sufficient signal; if legal needs an audit trail of changes, revisit then.
- **Rich-text editor with image uploads.** Markdown is enough for these pages. If a future content type needs images, that's a separate decision.
- **Wildcard `/p/{slug}` routing.** Hardcoded routes per page keep the URL space disciplined.
- **Localised admin UI.** Filament admin stays English-only regardless of content language.
- **Translation labor (`lang/ka.json` / `lang/ru.json` body content).** Engineering treats those files as drop-in; writing the actual Georgian / Russian copy is a content backlog, not a code task.
- **Per-locale `validation.php` overrides.** Laravel's bundled validation translations cover `en`; `ka` / `ru` fall back to English for now (Laravel ships defaults for ~50 locales, but not Georgian — would need community translation). Add when a real Georgian / Russian user complains.
- **`og-image.png` asset.** The meta tag is wired; the 1200×630 brand-design file isn't dropped yet. Without it, link previews show title + description but no image. Brand-design task, not code.

## M27 — In-app notifications + action-required UX + sound ✅ shipped 2026-06-02

The synchronous in-app channel that complements M20 (email, asynchronous). Today Stakly has no player-facing notification surface — if Bob takes Alice's listing while she's offline, and Alice visits Stakly later without checking email, she has no visible signal anything happened until she navigates to `/matches`. M27 closes that gap with a bell in `SiteHeader` + real-time push via Reverb + a sound for high-priority events. Symmetric on the admin side — `OpsOverview` gets SLA-aware surfaces so old disputes can't be ignored accidentally.

The `notifications` table already exists (it shipped with M12's `NotifyAdminsAction` for the Filament admin bell). M27 reuses that table via Laravel's `database` notification channel; M20 layers `mail` channel on top later.

### Design decisions taken into this milestone

- **Real-time via Reverb + Laravel Echo + private per-user channel.** Already in the stack. No polling fallback. Sub-second push is what makes sound notifications usable — a 30s polling delay would feel broken.
- **Sound default is narrow, sound preference is per-event.** Defaults to ON for `listing_taken` only (`PlayerNotification::SOUND_DEFAULT_EVENT_TYPES`) — the only event where the user is reliably away from the page and needs to come back NOW. The other 4 configurable events (`match_settled`, `match_manual_review`, `dispute_opened`, `cancellation_requested`) default OFF, but the user can opt them in via /settings/notifications Sound checkboxes. The 4 informational events (`listing_expired`, `dispute_resolved`, `cancellation_accepted`, `cancellation_rejected`) are non-configurable + always silent — pings, not signals.
- **Multi-tab sound coordination via `BroadcastChannel`.** If a user has multiple Stakly tabs open, only the first tab to receive the broadcast plays the sound — the others suppress. Prevents triple-ding when one event lands. (Phase 2 work.)
- **Notification classes designed to support `mail` channel from day one** even though M27 only lights up `database` + `broadcast`. M20 wires the Blade templates later without touching dispatch sites or class signatures.
- **Sound toggle lives on the preferences page (M27 Phase 5)**, alongside per-event in-app and email toggles. Default: `ListingTaken` sound ON for everyone, no other event has a sound toggle because no other event plays sound.
- **Mandatory events cannot be silenced.** "Settled — you won/lost" and "Cancellation request awaiting response" are operational, not informational — turning them off would let users miss money-affecting events. UI greys those toggles.
- **Coexist with Filament admin bell in the same `notifications` table** via the `type` discriminator. Filament reads `data->>'format' = 'filament'`; player notifications have `type = 'App\Notifications\XxxNotification'` and no `format` key, so each consumer queries its own subset. No schema changes, no migration of existing admin notifications.
- **`ShouldQueue` from day one.** `broadcast` channel hits Reverb over HTTP — a sync dispatch on a Reverb hiccup would fail the originating user's action (e.g. TakeListing). Queued notifications process in 100-500ms via Redis worker; user-action state (match created, listing taken) remains synchronous.
- **After-commit dispatch, never inside transactions.** Mirrors the existing `OpenDisputeAction::notifyAdminsOfDispute()` pattern — inside the transaction, a notification would fire even on rollback, pointing the bell at a match that "didn't happen." Settle Actions return an outcome marker from their `DB::transaction` closure; the outer `handle()` dispatches notifications after commit.

### Phases

**Phase 1 — Notification dispatch infrastructure** ✅ Shipped 2026-05-30

Scope expanded from 7 → 9 notification classes during P1 (added `DisputeResolvedNotification` so the admin-intervened path reads distinctly from auto-settle, and `ListingExpiredNotification` since the existing `ExpireListingAction` scheduler had no user signal beyond a wallet ledger row).

- [x] `App\Notifications\PlayerNotification` abstract base — handles `via(['database','broadcast'])`, `toDatabase()`, `toBroadcast(BroadcastMessage)`, assembles uniform payload `{event_type, title, body, action_url, related_id}`. Subclasses implement 5 abstract methods. `ShouldQueue` so Reverb hiccups don't fail user actions.
- [x] 9 concrete notification classes — `ListingTaken` (creator), `MatchSettled` (both), `MatchManualReview` (both), `DisputeOpened` (opponent of opener), `DisputeResolved` (both), `CancellationRequested` (opponent), `CancellationAccepted` (original requester), `CancellationRejected` (original requester), `ListingExpired` (creator).
- [x] After-commit dispatch wired into 11 Actions:
    - `TakeListingAction` → `ListingTakenNotification` to creator
    - `SettleFromCardAction` → `MatchSettledNotification` to both (won/lost branch + draw branch)
    - `AdminSettleToWinnerAction` → `MatchSettledNotification` to both (admin manual settle of ManualReview)
    - `AdminSettleDrawAction` → `MatchSettledNotification` to both (admin manual draw)
    - `ResolveDisputeAction` → `DisputeResolvedNotification` to both (Confirmed + Drawn branches) OR `MatchManualReviewNotification` to both (Unknown branch)
    - `ResolveMatchTimeoutAction` → `MatchManualReviewNotification` to both (alongside existing admin Filament bell)
    - `OpenDisputeAction` → `DisputeOpenedNotification` to opponent (alongside existing admin Filament bell)
    - `RequestCancellationAction` → `CancellationRequestedNotification` to opponent
    - `AcceptCancellationAction` → `CancellationAcceptedNotification` to original requester
    - `RejectCancellationAction` → `CancellationRejectedNotification` to original requester
    - `ExpireListingAction` → `ListingExpiredNotification` to creator
- [x] `SettleMatchAction::computeWinnerPayout($stake)` public static helper so calling Actions can render payout in `MatchSettledNotification` / `DisputeResolvedNotification` body without duplicating the bcmul chain.
- [x] Tests: 14 Pest tests in `tests/Feature/Notifications/PlayerNotificationsTest.php` — one per (Action, expected_notification, expected_recipient) tuple.
- [x] Existing 877 tests stay green — Action signature changes (transaction return shape on `SettleFromCardAction`, `ResolveDisputeAction`, cancellation Actions) are internal; public `handle()` signatures unchanged.

Gotchas / what we learned:

- **`routes/channels.php` already has the `App.Models.User.{id}` channel auth** — Laravel auto-creates it in the starter kit. No additional channel registration needed for broadcast notifications.
- **`Inertia\Ssr\HttpGateway::dispatch()` Vite-hot routing was a separate concern** (M26 P3 gotcha) — unrelated to notification broadcasts which go directly to Reverb.
- **Postgres `LIKE` on the `type` column doesn't work cross-driver** — `\%` in a Postgres LIKE pattern means literal `%` because `\` is the default escape character, so `'App\\Notifications\\%'` matched nothing. Switched the player/admin discriminator to `whereNotNull('data->event_type')` (every `PlayerNotification::payload()` emits `event_type`; Filament admin rows don't). Database-agnostic + survives namespace refactors.
- **CSRF for the bell's mutation endpoints uses the `XSRF-TOKEN` cookie**, not a `<meta name="csrf-token">` tag. Inertia/Laravel already set the cookie; bell's `postJson()` helper reads it and sends as `X-XSRF-TOKEN` header. No meta tag was added.
- **`SoundPriority` enum was wrong abstraction — removed during P5 testing.** Original P1 design declared a per-class `SoundPriority` enum (`Urgent | Soft | None`); the frontend hook gated playback on `priority !== 'none'`. P5 then exposed per-event Sound checkboxes — but those checkboxes were silently no-ops on 4 of 5 events because the classes still returned `None`. Two unrelated concepts had collided: backend "event urgency" and the user's chime choice (the user-facing values `classic / soft / ding` even share the word "soft" with the enum but mean an audio file). Fix: deleted the enum, the abstract `soundPriority()` method, and the `sound_priority` payload field. Sound playback is now gated only by (a) user's `notification_sound !== 'off'` and (b) the per-event `notification_sound_map[event_type]` preference. Default audibility lives in `SOUND_DEFAULT_EVENT_TYPES = ['listing_taken']` — same outcome for new users, but the rest of the events now respect the user's checkbox.

**Phase 2 — Bell UI in `SiteHeader` + real-time + sound** ✅ Shipped 2026-06-02

- [x] Bell icon + unread count badge in `SiteHeader` (auth-gated — anonymous visitors see no bell). Popover on desktop, Sheet on mobile via `useIsMobile`.
- [x] Dropdown with last ~15 notifications, each linking to its `action_url`. "Mark all read" affordance. "View all" → full notifications page. Optimistic local-state mark-read on click.
- [x] Full notifications page at `/notifications` — paginated list, all notifications, expanded card rows with event icon + title + body + timestamp + read indicator. All / Unread filter chips with filter-aware optimistic mark-read. Mark-individual (click) + mark-all controls.
- [x] Laravel Echo subscribed to `App.Models.User.{id}` via `useEchoNotification` (kept the default channel rather than the originally-drafted `private-users.{id}` — the channel auth already exists in `routes/channels.php`, zero overrides needed). On broadcast: increment badge + prepend dropdown entry + invoke sound playback hook.
- [x] Sound assets in `public/sounds/` — `classic.mp3`, `soft.mp3`, `ding.mp3` committed (royalty-free chimes; the user picks which one plays via /settings/notifications).
- [x] `useNotificationSound` hook reads the user's `notification_sound` choice off Inertia share, plays the matching file via `new Audio('/sounds/${choice}.mp3').play()`. Multi-tab coordination via `BroadcastChannel('stakly:notification-sound')` — claiming tab posts `{type:'claim', at:ts}`; tabs receiving a claim within 500ms suppress.
- [x] Browser autoplay policy handled implicitly — by the time a notification lands, the user has interacted with Stakly at least once.
- [x] **`NotificationProvider` context** mounted in `SiteLayout` holds the unread count (initial from `auth.user.unread_notifications_count`, bumped on broadcast, cleared on bell open) and the `lastBroadcast` Notification (signal for the dropdown to prepend). The Echo subscription lives in an inner `AuthedNotificationProvider` so the hook only mounts for authed users. Re-syncs from Inertia share on every navigation (server is authoritative on multi-tab mark-read).

**Phase 3 — Action-required banners on match pages** ✅ Shipped 2026-06-02

Full-width status banners at the top of `match/show.tsx`, mutually exclusive by status. Visible whenever the player views the match page, independent of bell state — the banner is the source of truth for "what does this player need to do here."

- [x] **`CancellationRequestBanner`** — Pending matches with an open cancellation request. Two viewer-aware variants in one component: `RequesterWaitingBanner` (Clock icon, "Cancellation request sent" + opponent name) for the requester, `RespondBanner` (Handshake icon, requester name in title, Accept/Decline buttons with processing states) for the opponent. Both render the reason in a card below the body. Shipped pre-M27 as part of the cancellation flow.
- [x] **`AdminReviewBanner`** — Disputed + ManualReview matches. Three copy variants via a single `resolveCopy(match, viewerId)` helper:
    - `manual_review` → "Match flagged for admin review" (auto-flag, no human opener).
    - `disputed` + viewer opened it → "You reported a problem" + escrow + evidence prompt.
    - `disputed` + opponent opened it → "{Opener name} reported a problem" + same prompt. Opener resolved client-side from `match.dispute.opened_by_id`, matched against `creator.id` / `taker.id`.
    - All three carry a "Post evidence in chat" outline button that dispatches a `stakly:focus-chat` window event.
- [x] **Chat-focus mechanism via `window` custom event `stakly:focus-chat`** — banner fires the event; `ChatInput` always listens and focuses + scrollIntoView's its textarea; `MobileChatTrigger` listens only when `useIsMobile()` is true and opens the Sheet first, then re-fires the event 250ms later so the freshly-mounted inner `ChatInput` catches it. `open` guard breaks the re-dispatch loop.
- [x] **`GameMatchResource` exposes `dispute.opened_by_id` + `dispute.opened_at`** so the frontend can do the viewer-aware split. Backend columns existed since dispute flow shipped; just weren't on the resource.
- [x] **Tests**: 4 new Pest feature tests in `tests/Feature/GameMatchShowTest.php` cover the dispute resource shape — fresh match nulls, Disputed opened by creator (creator id surfaced), Disputed opened by taker (taker id surfaced), ManualReview (null opener — auto-flag). UI rendering assertions are not part of this slice because Stakly's test suite is Pest-only; the React side has no Vitest/RTL setup. The component is small, pure, and exercised manually + via the resource-shape contract above.

P3 follow-up slices (also 2026-06-02):

- [x] **Dispute opener-claim** — the "Report a problem" dialog captures the disputing player's reason + an optional evidence file (image OR PDF). On submit, `OpenDisputeAction::postOpenerClaim` posts a USER-authored chat message owned by the disputing player, tagged with a new `dispute_opening` attachment marker. Closes the fairness gap of "opponent sees a banner but doesn't know what's being claimed" — and gives admin an anchor message to read first if the dispute escalates to ManualReview. Reason or evidence is required (either-or, mirrors chat's `required_without` pattern); reason is uncapped on min length to keep filing friction low. `ChatMessageBubble` renders a `⚠ Reason for dispute` warning-toned pill above the user's bubble when the marker is present. New tests in `GameMatchOpenDisputeTest.php` cover the require-either rule, evidence-only path, PDF acceptance, and unsupported-mime rejection.
- [x] **Live page sync via `MatchLiveUpdater`** — any `PlayerNotification` whose `related_id === match.id` triggers a `router.reload()` on the match page. Closes the UX gap where admin-resolved settlements left the banner, status chip, action card, and shared `auth.user.usdt_balance` stale until the user manually refreshed. Covers admin settle (both branches), admin draw, dispute resolve (Confirmed/Drawn/Unknown), opponent-opens-dispute, opponent-requests-cancellation, cancellation-accepted/rejected — every match-state-mutating notification path already in M27 P1.
- [x] **Chat universally accepts PDFs** (started as dispute-only, generalized). `StoreMessageRequest::ALLOWED_MIMES` now includes `pdf`, dropped the `image` rule. New shared `SendMessageAction::attachFileTo` static helper branches by mime — images go through the existing EXIF-strip + dimension-capture pipeline, PDFs get a direct media store. Used by both chat sends AND dispute-opener evidence (replaced the earlier private `attachEvidence` duplicate). `MessageAttachmentsPayload::mediaEntries` (renamed from `imageEntries`) emits `type: 'image'` vs `type: 'file'` based on the media mime so the same payload shape works for both. Frontend chat-input, chat-panel drag-drop, and a new `OptimisticAttachment` component branch by mime: image previews via blob URL, PDFs render a `FileText` icon tile with name + size. `Message::registerMediaCollections::acceptsMimeTypes` extended to `application/pdf` (defense in depth at the storage layer).
- [x] **Admin Filament chat-history Blade now renders PDFs as download tiles.** `ChatHistoryEntry` gained `attachmentIsImage()` / `attachmentName()` / `attachmentSizeLabel()` helpers; the Blade template branches by mime so non-image media gets a clickable document-icon tile linking to the file (was previously a broken `<img>` with alt "Chat attachment").
- [x] **Fix: `dispute_opening` marker was being stored but never serialized to the frontend.** `MessageAttachmentsPayload::forMessage()` was missing `disputeOpeningEntries()`. Without this, the warning-toned "Reason for dispute" pill never rendered for anyone. Added the entry method + updated the `forMessage` spread.
- [x] **Fix: long PDF filenames broke the dispute dialog layout.** `DialogContent` is a CSS grid; unbreakable filename text was forcing the grid track wider than the dialog's `max-w-lg`, defeating the inner `truncate`. Added `min-w-0` on the dialog body wrapper + `min-w-0 overflow-hidden` on the file tile so the truncate engages reliably.

Gotchas / what we learned:

- **Mobile chat-focus needs a re-dispatch.** On mobile, `MobileChatTrigger`'s ChatInput isn't mounted while the sheet is closed — the in-input event listener doesn't exist yet, so a direct dispatch from the banner would no-op. The trigger listens for the same event, opens the sheet (state change → render → ChatInput mounts), and re-fires the event after a 250ms delay so the now-mounted listener picks it up. The `if (open) return` guard short-circuits the re-fire on the second pass so we don't loop.
- **`useIsMobile()` gating on the trigger's listener is required** — without it, on desktop the trigger's listener would still fire and `setOpen(true)` the Sheet (which renders via portal regardless of the `lg:hidden` wrapper), causing the sheet's ChatInput to ALSO claim focus and steal it from the always-mounted desktop ChatInput.
- **React 19 forwards refs through function components by default.** No `forwardRef` needed on the Textarea primitive — passing `ref={textareaRef}` to `<Textarea>` flows through to the underlying `<textarea>` via `{...props}`. Saved a primitive rewrite.
- **`NotificationProvider` is mounted inside `SiteLayout`, NOT at app root.** Pages that want to read `useNotificationContext()` must call it from a component RENDERED inside `<SiteLayout>{...}</SiteLayout>` — not from the outer page component. The outer page is the provider's PARENT in the tree, so a context read there returns the default empty value. Fix pattern: extract a tiny child component (e.g. `MatchLiveUpdater`) and render it inside the return tree of `<SiteLayout>`. First attempt at the live-update bridge was silently a no-op for exactly this reason — symptom was "the chime plays + the bell badge bumps, but the page doesn't refresh" because chat updates go through `useMatchChat`'s own Echo subscription (mounted inside the chat panel, also inside SiteLayout).
- **Spatie media `acceptsMimeTypes` is collection-level defense in depth.** When extending mime support at the form-request layer, you must ALSO extend `Message::registerMediaCollections::acceptsMimeTypes()` — otherwise Spatie rejects the upload at storage time. Symptom was "validation passes, no exception thrown, but the Message ends up with zero media." Easy to miss because it's silent.
- **Grid items size to min-content by default; long unbreakable text expands grid tracks past the parent's max-width.** `DialogContent` is a CSS grid (`grid w-full max-w-lg`); a PDF filename like `529978784udII46jNzRq…pdf` has no whitespace, so its min-content equals its full pixel width, which inflates the grid track and defeats any inner `truncate`. Fix: `min-w-0` on the grid item lets it shrink below content size, then the inner `truncate` engages. Cheap insurance to add at `min-w-0` on every direct child of a `Dialog`/`Sheet`/`Popover` content wrapper that might hold variable-width content.
- **`MessageAttachmentsPayload` is two-pronged.** Media (Spatie collection) iterates `getMedia(...)` once and emits per-media entries. JSON markers (`attachments_json`) iterate the array once per marker type and emit per-marker entries. When you add a new marker type (`dispute_opening` was the gotcha), you have to add it to BOTH `forMessage()`'s spread AND a dedicated `xxxEntries()` method. Skipping the entry method silently drops the marker from the broadcast payload — the message persists fine, the frontend just never sees the discriminator.

**Phase 4 — Admin SLA surfaces** ✅ Shipped 2026-06-02

- [x] **`OpsOverview` → new "Aging disputes (≥6h)" stat** alongside the existing "Open disputes" stat. Stat value = count of Disputed + ManualReview matches whose aging timestamp is ≥6h old. Description + color escalation:
    - 0 aging → green / "No aging disputes"
    - 1+ in 6h–12h window → amber / "N between 6h–12h"
    - 1+ over 12h → red / "N over 12h"

    The two dispute stats render side-by-side on row 1 of the dashboard (`getColumns() = 2`) so admins see "total" + "aging" together.
- [x] **Aging timestamp = `COALESCE(dispute_opened_at, updated_at)`** — Disputed matches have `dispute_opened_at` set, and ManualReview routed from a Dispute-Unknown branch also has it. ManualReview matches from match-timeout (no dispute event ever fired) fall back to `updated_at`, which corresponds to when the status flipped to MR. One consistent aging field across both statuses.
- [x] **`GameMatchesTable` default sort: newest first** (`created_at DESC`). The earlier P4 iteration tried "oldest unactioned at top" via a COALESCE expression, but admin browsing UX consistently wants the most recent at the top — the SLA cues live in the Age column's color badge + the OpsOverview "Aging disputes" stat, not in the row ordering. Click-sort on the Age column gives admin oldest-first when they want to triage.
- [x] **Per-row age badge** — the existing `dispute_opened_at` column is relabeled "Age" and rendered as a colored `->badge()` with the same SLA scale as the OpsOverview widget (success / warning / danger at the same 6h / 12h thresholds). Non-dispute rows (when admin widens the filter past the default) render `'gray'` and a `—` placeholder. Color resolver is `GameMatchesTable::ageBadgeColor()` — kept inside the table class so the SLA scale lives in one place.
- [ ] (Deferred) Slack / Discord webhook to admin channel when a dispute crosses the 12h `danger` threshold without action. Out of scope for now; opens a follow-up if email-to-admin pings prove too quiet in practice.
- [x] **Tests**: 4 new Livewire tests in `tests/Feature/Admin/DashboardWidgetsTest.php` covering the aging-disputes stat (zero / between-6h-12h / over-12h / MR-from-timeout fallback to updated_at). 1 new test in `tests/Feature/Admin/GameMatchResourceTest.php` asserting `assertCanSeeTableRecordsInOrder([oldest, middle, newest])` for the default-sort behavior.

Gotchas:

- **Filament 4 `defaultSort()` accepts a Closure.** When the sort key isn't a simple column (we need `COALESCE(dispute_opened_at, updated_at)`), pass a `fn (Builder $q) => $q->orderByRaw(...)` instead of column name + direction. The closure form is documented but easy to miss; the column-name form would have required a virtual column on the model.
- **`getColumns(): int` controls the stats-row wrap.** Adding a 5th stat to a 2-column grid produces a 2 / 2 / 1 layout (the last stat alone in row 3). Acceptable here because "active users" sits alone on row 3 cleanly. If we add another stat later, bump to 3 columns or shuffle the pairing.

**Phase 5 — Preferences UI (shared surface with M20)** ✅ Shipped 2026-06-02

Final design landed after three iterations (icon-tile + custom Switch → single combined card → Dribbble-style **matrix grid**, which is what shipped).

- [x] New `/settings/notifications` page — sub-header with title + two pill bulk-action buttons (Switch off all / Email only — one-shot client-side state mutations, respect mandatory In-app), then **three cards** using a shared row pattern: card header strip (title + description) + divider + event/choice rows underneath.
    - **Match activity** card — Listing taken, Match settled (locked), Cancellation requested (locked).
    - **Disputes & moderation** card — Dispute opened, Match flagged for review.
    - Each event row = event name (+ Lock icon for mandatory events with tooltip "Required — affects your money. Can't be silenced.") + 3 checkbox+label columns: **In-app / Sound / Email**.
    - **Sound** card — 4 radio rows (Off / Classic / Soft / Ding) with contextual lucide icons (`VolumeX`, `Bell`, `Music`, `BellRing`); each non-Off row has a ▶ preview button.
- [x] Checkboxes use a custom `components/ui/checkbox.tsx` rebuilt on the **native peer pattern** — `<input type="checkbox" className="peer sr-only">` + sibling box `<span>` + sibling lucide `<Check>`, all wrapped in a `relative inline-flex`. State driven entirely by Tailwind `peer-checked:` / `peer-focus-visible:` / `peer-disabled:` modifiers. 16px box, no motion. Public API (`checked` / `onCheckedChange` / `disabled` / `aria-label`) is shadcn-compatible.
- [x] Per-event preferences scoped to the 5 main events: `listing_taken`, `match_settled`, `match_manual_review`, `dispute_opened`, `cancellation_requested`. The other 4 (`listing_expired`, `dispute_resolved`, `cancellation_accepted`, `cancellation_rejected`) always fire and aren't user-configurable — they're after-the-fact informational pings, not signals that need a mute toggle. `PlayerNotification::CONFIGURABLE_EVENT_TYPES` is the source of truth.
- [x] Email column is freely togglable. The backend records the preference today; delivery activates with M20 (no UI placeholder gating — just an honest "set it now, mailer ships later" model).
- [x] Schema: `notification_preferences` table — `user_id` FK cascade, `event_type` string, `in_app` + `sound` + `email` booleans, UNIQUE `(user_id, event_type)`. Per-event `sound` toggle gates whether the chime fires for that event; the global `users.notification_sound` choice (Off/Classic/Soft/Ding) decides which file plays. Lazy default policy in code instead of seeding rows on user creation — `PlayerNotification::defaultPreference($eventType)` returns the default (sound defaults ON only for `listing_taken`); `User::getNotificationPreference` returns the DB row if present, else the default. Avoids backfill and keeps the table sparse.
- [x] Sound choice picker — `users.notification_sound` string nullable column on users. Four valid values: `off | classic | soft | ding` (validated server-side via `Rule::in(PlayerNotification::SOUND_CHOICES)`). `useNotificationSound` reads `auth.user.notification_sound` (defaults to `'classic'` when null) and skips playback entirely when the choice is `off`. The per-event `sound` preference is shared as `auth.user.notification_sound_map` and checked by `NotificationProvider` before calling the hook — `false` (explicitly muted) suppresses, `true` or missing plays normally. Per-sound preview button on the settings page plays the file directly via `new Audio(url).play()`. Sound files (`public/sounds/{classic,soft,ding}.mp3`) committed alongside P2.
- [x] Backend enforcement: `PlayerNotification::via()` returns `[]` for muted optional events (suppresses both database + broadcast channels). Mandatory events bypass the preference unconditionally.
- [x] Cleanup: the abandoned `components/ui/switch.tsx` and `components/ui/radio-group.tsx` (built for earlier P5 iterations) are deleted — both confirmed unreferenced before removal.

### Cross-milestone notes

- **M20** plugs into M27's notification classes by writing Blade email templates + wiring SMTP config. The dispatch layer is reused as-is. M20's preferences UI piggybacks on M27 Phase 5's page.
- **M9 (chain integration)** will add `DepositConfirmedNotification` and `WithdrawalProcessingNotification` when it lands. The pattern is established by M27.
- **M13 (chat anti-abuse)** can add `MessageFlaggedForReviewNotification` (admin-side) when it ships, using the same dispatch pattern.
- **M14** ManualReview escalation already exists via `ResolveMatchTimeoutAction` → `NotifyAdminsAction`; M27 P4 surfaces it as an SLA-tracked dashboard widget rather than just a single admin bell ping.

### Not in M27

- Mobile push (APNS / FCM). Web push (browser Notification API) is also out of scope — `Notification.requestPermission()` introduces a permission-prompt UX that's worth handling deliberately, not bundling into the in-app milestone.
- SMS notifications. Different channel, different milestone if ever needed.
- Email channel. M20 owns that end-to-end; M27 just makes sure the dispatch layer supports it without rework.
- Notification analytics / read-rate tracking. Premature.
- Per-tab focus-aware sound suppression (i.e. "don't ding the tab the user is actively looking at"). The `BroadcastChannel` coordination already prevents the triple-ding case; layering "is this tab focused" on top is polish that can wait for user feedback.

---

## M29 — Editable username (with cooldown + reservation) ✅ shipped 2026-06-03

Registration auto-derives the username via `Str::slug($name)` — users have no direct control over their handle at signup. M29 gives them a one-per-month rename path, with the guardrails a money platform needs: a 30-day cooldown on a per-user basis, a 30-day reservation on the released handle so nobody can impersonate the original holder, a hard block while the user has an in-flight match or open dispute, and a 301 redirect from the released handle to the current owner during the reservation window so old bookmarks + indexed URLs stay alive.

Full name remains freely editable (no cooldown, no audit). It's display-only — not a route key, not a reputation key, none of username's weight.

### Design decisions taken into this milestone

- **30-day cooldown.** Once renamed, the user can't rename again for 30 days. Matches GitHub / eBay precedent. Stops "rename mid-match to dodge a dispute" abuse.
- **30-day reservation on the released handle.** Old handle goes into `username_history` and can't be reclaimed by anyone — including the original owner — during the window. Mitigates impersonation: if Alice renames `alice-pro → alice-new`, nobody can grab `alice-pro` for 30 days.
- **In-flight match blocks rename.** Any `GameMatch` where the user is participant and status ∈ {Pending, Disputed, ManualReview} blocks the field. One blocker key (`in_flight_match`) covers all three sub-states.
- **M9 withdrawal blocker deferred.** No withdrawal model exists yet (chain paused). Clean spot to add when M9 resumes.
- **Active listings DO NOT block.** Listings link to user by FK id, not handle. The 30-day URL redirect covers shared listing-detail links during the window.
- **Old URL → current owner redirect.** During the 30-day reservation, hitting `/users/{old-handle}` 301-redirects to the current owner's profile via the route's `missing()` callback querying `UsernameHistory::reserved()`.
- **Atomic rename via `ChangeUsernameAction`.** Row-locks the user inside a transaction, re-checks blockers + availability, writes the reservation row, bumps `username_changed_at` — closes the validate-then-act race.
- **Lowercase normalization at the FormRequest layer.** `prepareForValidation` lowercases the input (`Alice-Pro` → `alice-pro`) so the storage / display invariant holds without surprising the user with a rejection.
- **Full name stays freely editable.** Already worked via the existing `ProfileController::update`. No cooldown, no audit — `name` doesn't have the load that `username` does.

### Phases

**Phase 1 — Drop `@` from listings username display** ✅ shipped 2026-06-03

Two-line tweak in `components/listings/listing-row.tsx`: removed the `@` prefix from both the rendered username and the `aria-label`. Companion fix bundled in: gave the Take CTA wrapper a fixed `md:w-44` and added a matching placeholder column to the desktop header strip in `pages/listings/index.tsx`, so the `Ends in` / `Stake` header labels sit over their data columns instead of drifting over the Take button.

**Phase 2 — Schema + model** ✅ shipped 2026-06-03

- `users.username_changed_at` (nullable `immutable_datetime`) — cooldown anchor.
- `username_history` table — `id`, `user_id` FK (`nullOnDelete` so history survives a hard-delete and keeps the reservation timer), `username` (indexed), `released_at` (indexed).
- `UsernameHistory` model — `user()` BelongsTo + `scopeReserved()` (rows where `released_at > now`).
- `User` model — constants `USERNAME_CHANGE_COOLDOWN_DAYS = 30` / `USERNAME_RESERVATION_DAYS = 30`, the `RESERVED_USERNAMES` list lifted up from `CreateNewUser` so both registration and rename share it, `usernameHistory()` HasMany, and three helpers: `canChangeUsername()`, `usernameChangeAvailableAt()`, `usernameChangeBlockers()`.

**Phase 3 — Backend update path + tests** ✅ shipped 2026-06-03

- `ProfileUpdateRequest` extended — username rule is `sometimes|required|min:3|max:30|regex:^[a-z0-9]+(?:-[a-z0-9]+)*$|unique`. Auto-lowercase via `prepareForValidation`. An `after()` callback layers the domain checks: reserved-word rejection, reservation rejection, cooldown + in-flight-match blocker messages. `sometimes` so existing PATCH payloads that omit username still pass.
- `App\Actions\Profile\ChangeUsernameAction` — `DB::transaction` + `User::lockForUpdate()`, re-checks blockers + reserved-words + uniqueness + reservation, writes the old handle to `username_history` with `released_at = now() + 30 days`, updates `users.username` + `users.username_changed_at`. No-op when the submitted value equals the current handle.
- `ProfileController::update` — pulls `username` out of validated, runs the action when it differs from current.
- 27 Pest feature tests in `tests/Feature/Settings/UsernameChangeTest.php` — happy path, history-row shape (`released_at = now + 30d`), lowercase normalization, idempotent same-value submit, 8 format edge cases (leading / trailing / consecutive hyphens, underscore, space, dot, too short, too long), reserved-word rejection, uniqueness rejection, reservation window (own + others), reservation expiry, cooldown enter / exit, in-flight match / dispute / manual-review blockers, terminal-match non-blockers, User-model helpers.

**Phase 4 — Frontend UI** ✅ shipped 2026-06-03

- `HandleInertiaRequests::share` exposes `auth.user.username_edit: { can_change, available_at, blockers }`.
- `resources/js/types/auth.ts` — `username_edit` typed on `User`.
- `pages/settings/profile.tsx` — username field above name, lowercase on input, disabled when `!can_change`, helper text branches (cooldown date / in-flight-match message / default format hint). Server-side errors flow into `InputError`.
- Confirmation dialog intercepts submit when the field is dirty + allowed. Cancel keeps form open; Confirm fires `submitForm()`. Quotes before/after handles so the user sees what they're changing.
- Live preview — `ProfilePreview` reads `data.username` (not the saved value) so the handle on the preview card updates as the user types.

**Phase 5 — Old-URL redirect during the reservation window** ✅ shipped 2026-06-03

- `routes/web.php` — `users.show` route's `missing()` callback queries `UsernameHistory::reserved()->where('username', $handle)->whereNotNull('user_id')`. If found, 301 → the current owner via `redirect()->route('users.show', ['user' => $historyRow->user], 301)` (binds via `getRouteKeyName() = 'username'`, so picks up the user's current handle).
- 7 Pest feature tests in `tests/Feature/UsernameRedirectTest.php` — in-window redirect, expired-window 404, chained-rename behavior (verifies the 30-day cooldown == 30-day reservation symmetry so only the most recent prior handle stays redirected), orphan history rows (user hard-deleted) → 404, unknown handle → 404, current owner of a previously-released handle renders normally (no redirect loop), stale history row with past `released_at` doesn't trigger redirect.

### Cleanup decision (post-ship)

- **No scheduled cleanup of `username_history`.** Volume is bounded by the 30-day cooldown (max ~12 rows / user / year), each row is ~80 bytes, and the rows retain audit value beyond the reservation window (who used to be called X, useful for support tickets and abuse investigations). Easy to add a daily prune-after-1-year Artisan command later if it ever matters; no schema lock-in by waiting.

### Not in M29

- Pick-your-own-username at registration. Considered when discussing whether to remove rename entirely; landed on keeping rename (Option A) since registration auto-derives via `Str::slug($name)` and users need *some* way to fix a bad handle. A pick-at-registration flow stays an option later if rename ever proves too noisy.
- "Formerly known as alice-pro" trust signal on the profile. The data exists in `username_history`; rendering it is a future trust-signal decision, not engineering scope today.
- Username change audit log shown on the profile. The data is in `username_history` — surface it if a use case appears.
- Cleanup / pruning job. See cleanup decision above.

---

## M30 — Admin user management ✅ shipped 2026-06-03 → 2026-06-04

A first-class user moderation + support surface inside Filament. Today the admin panel has zero user UI — moderation, investigation, manual interventions all require Tinker queries. The first time a real user files a support ticket or a chat-abuse report surfaces, the admin needs to investigate without dropping to the shell. M30 closes that gap.

This is the single biggest support gap in the panel today. For a custodial money platform with player-to-player chat, the longer it takes to act on abuse, the worse it gets.

### Design decisions taken into this milestone

- **Always-rendered wallet invariant on the view page.** Compares `users.usdt_balance` to `SUM(wallet_transactions)` and renders a success/danger badge. Should always pass — exists to catch a regression early when investigating a problem user.
- **Ban toggle requires a reason in both directions.** Initial ban captures *why*; lifting captures *why now*. Months later we want a single trail of "what happened" without cross-referencing.
- **Ban actually does something day one — four enforcement guards land in M30.** The spec was originally "M30 ships the column, M21 wires the enforcement" but that leaves the toggle informational. Instead, M30 wires the four guards that actually *stop the bleed*: (1) `ListingController::create + store` rejects banned users so no new abuse-vector listings land; (2) `ProfileController::update` rejects banned users so they can't evade by changing name / avatar; (3) `ChangeUsernameAction` gains `banned` as a blocker on top of cooldown + in-flight-match, so rename isn't an evasion path; (4) listing-marketplace scopes filter `where('users.banned_at', null)` so existing listings disappear from the public board. M21 still owns chat-send-block, take-listing-block, polished "you've been suspended" page, blacklist (user-to-user) UI, and multi-account anti-evasion.
- **No delete action.** Hard-deleting users breaks FK chains across listings, matches, messages, wallet transactions. `banned_at` is the correct mechanism. If a user requests data deletion under privacy law, that is a user-owned legal call, not an engineering action.
- **Mandatory 2FA on admin role.** New middleware on `/admin/*` gates access on `two_factor_confirmed_at IS NOT NULL` for users with the `admin` Spatie role. Unenrolled admin → redirected to Fortify's existing two-factor-authentication enrollment page with a flash notice. Hardens the admin panel against credential phishing now that admin can impersonate any user. Uses Fortify's existing TOTP infrastructure — no new auth surface, just a route guard.
- **Decision: admin 2FA challenge on every login bridges to Fortify (Phase 6), not a plugin swap.** P3's middleware enforces 2FA *enrollment* before reaching the panel, but Filament's built-in `->login()` form bypasses Fortify's pipeline, so the on-every-login TOTP prompt doesn't fire. The `stephenjude/filament-two-factor-authentication` plugin was evaluated 2026-06-03 and rejected: (a) its `TwoFactorAuthenticatable` trait collides method-name-wise with Fortify's, so installing it requires removing Fortify's 2FA *app-wide* — not localized to admin; (b) Stakly's `/settings/security` is Inertia/React but the plugin's 2FA setup is Livewire, so adopting it routes every user (not just admins) through Filament/Livewire for 2FA setup; (c) `spatie/laravel-passkeys` is a hard composer dep for an unused feature. DIY bridge instead — custom Filament `Login` subclass detects admins with 2FA, bounces to a Stakly-styled `/admin/two-factor-challenge` Inertia page that validates codes via Fortify's existing `TwoFactorAuthenticationProvider`, plus a defense-in-depth middleware that catches the same condition on direct panel hits. Reuses Fortify's TOTP setup at `/settings/security` unchanged; ~200 LOC + tests.
- **User-facing ban feedback fires across three channels (P4).** When admin bans a user, the user MUST learn about it through (a) a persistent banner on every Stakly page they touch, (b) an in-app notification through M27's `PlayerNotification` pipeline (bell + `/notifications` page + real-time Reverb push), and (c) email. Production-grade: any single channel can fail (email in spam, user not on site for the bell push, banner missed because user is reading via email) — together the three guarantee the message lands. Same three channels on unban for symmetry.
- **Ban-feedback banner is non-dismissible.** Banned users shouldn't be able to hide the explanation of why they can't act on the platform. Sticky at top of every page, destructive tone, includes the reason from `user_moderation_logs` + a link to the CMS Support page.
- **Reason source of truth for ban feedback: `user_moderation_logs.reason`.** The banner reads the latest row where `action = 'ban'` via a new `auth.user.banned_reason` field exposed through `HandleInertiaRequests::share()`. The notification snapshots the reason in its own payload so old notifications keep showing the original reason even if a later ban/unban cycle changes "latest."
- **Decision: use `stechstudio/filament-impersonate` for the auth-swap, build the audit + reason + expiry + Stakly banner on top.** Initial plan was custom-built ("minimizing third-party auth packages"). Reversed after 2026-06-04 research: the package is actively maintained (v5.5.0 released 2026-05-26, Filament 4+5 composer constraint), publishes `EnterImpersonation` / `LeaveImpersonation` events that map cleanly to our audit-row writes, uses the same `impersonated_by` session key the spec called out, and ships authz hooks (`User::canImpersonate()` / `canBeImpersonated()`) that absorb the `is_platform` / banned / self guards. We keep ownership of every Stakly-specific concern (audit table, reason capture, 30-min expiry, branded banner) while delegating the security-sensitive session-guard swap to battle-tested code. Net ~120 LOC vs ~200 with less risk on the auth path.
- **Impersonation requires password re-entry inside the start modal.** Initial spec routed start through Fortify's `password.confirm` route middleware. Discarded because the package's action runs inside Livewire (not an HTTP POST that survives a redirect to `/user/confirm-password` + bounceback). Replaced with a `current_password` Laravel validation rule on a password field inside the impersonate modal — admin types their password every single time (stricter than Fortify's 3-hour freshness window), single-modal UX, no redirect dance.
- **Impersonation auto-expires after 30 minutes.** `started_at` on the audit row; middleware compares against `now` and force-exits past 30 min. Prevents "admin walked away from the desk" scenarios.
- **Impersonation banner rendered in `app.blade.php`.** Persists across every page the impersonating admin lands on — Stakly app pages, auth pages, error pages, even `/admin` if they navigate there. Single source of truth, no React provider plumbing. Shows "Viewing as @username · Exit" with the exit button always one click away.
- **Impersonation reason required at start.** Free-text field on the start modal ("Investigating Alice's wallet-history bug"). The audit row's reason is the answer to "why did admin X impersonate user Y three weeks ago?" — timestamp alone is too thin.
- **Impersonation exit returns to wherever the admin started from.** The package stores `impersonate.back_to` in session at start (default: the referring URL, which for our flow is the user's admin view page). The exit route reads and clears it. Same end state as the original spec — the admin lands back on the user they were investigating.
- **Impersonation blocked for `is_platform` users, banned users, and self.** Three guards expressed via the package's `User::canImpersonate()` (admin gate) + `User::canBeImpersonated()` (target gate) — the action is hidden in the UI AND the package's internal `canImpersonate()` re-checks before calling `enter()`, so defense-in-depth holds even if a stale-cache click slipped through.

### Phases

**Phase 1 — Schema + `UserResource` scaffold + index page** ✅ shipped 2026-06-03

Migrations for `users.banned_at` + the append-only `admin_impersonations` audit table; `Filament/Resources/Users/` folder; index page with 3-column search + 4 filters, `is_platform` excluded, `canCreate() = false`. `AdminImpersonation` model landed here too (was originally scoped to P5 — cleaner alongside the migration). Pest tests cover admin-only access + the filters / search / sort + no-create gate.

**Phase 2 — View page + non-impersonate actions + ban enforcement** ✅ shipped 2026-06-03

Schema: append-only `user_moderation_logs` (`action: ban | unban`, `UPDATED_AT = null`). `UserInfolist` with 7 stacked sections including an always-rendered wallet invariant badge that compares `users.usdt_balance` to `SUM(wallet_transactions.amount)`. Header actions: View as visitor / Verify email / Reset 2FA / Ban toggle (reason required both directions; DB transaction wraps `banned_at` flip + audit row write).

`App\Support\BanGuard` helper centralises `isBanned()` / `rejectionMessage()` / `supportUrl()` across the four enforcement surfaces (listing create+store, profile update, username rename blocker, marketplace scope). 14 Pest tests across `UserResourceActionsTest` + `BanEnforcementTest`.

Two calls flagged in the ship report: skipped the parallel `banned-user store-listing` test (the create-form test already proves the controller-level guard); switched flash style from `->with('toast', ...)` to `Inertia::flash('toast', ...)` because the former tripped `assertRedirect`'s session-error inspection.

**Phase 3 — Mandatory 2FA for admin role** ✅ shipped 2026-06-03

`App\Http\Middleware\RequireAdminTwoFactor` registered in `AdminPanelProvider::authMiddleware` — redirects admins without `two_factor_confirmed_at` to `/settings/security` with a toast flash. Non-admins fall through to Filament's `canAccessPanel` 403. `UserFactory::admin()` + `AdminUserSeeder` stamp the column so existing tests + local dev + CI don't trip the gate. Production checklist: operator re-enrolls real 2FA after first login.

Gotcha documented in tests: `/settings/security` is itself behind Fortify's `password.confirm` (`confirmPassword: true` in `config/fortify.php`), so the real flow is `/admin → /settings/security → /user/confirm-password → /settings/security → enrolls 2FA`. 5 Pest tests assert the full chain.

**Phase 4 — User-facing ban feedback (banner + bell + email)** ✅ shipped 2026-06-03

P2 shipped enforcement (banned users can't act) but only a generic flash on guarded actions. P4 closes the explanation loop across three channels so the user can't miss it.

- `BannedBanner` in `SiteLayout` above `SiteHeader`. Reads `auth.user.ban` ({reason, banned_at}) lazy-loaded via `User::latestBanLog` (HasOne with `latestOfMany`) only when `banned_at !== null` — unbanned users skip the join entirely.
- `AccountBanned` / `AccountRestored` extend `PlayerNotification` but override `via()` to fan out `['database', 'broadcast', 'mail']` unconditionally. Bypasses parent's preference flow because moderation can't be silenced. Mail uses `MailMessage` greeting/line/action with Laravel's default `notifications::email` Blade layout — custom branded templates deferred to a polish pass.
- Real-time banner refresh: `NotificationProvider` calls `router.reload({ only: ['auth'] })` on the `account_banned` / `account_restored` broadcast.
- Dispatch sits outside the DB transaction (`ViewUser::banToggleAction`) so a queue/notification failure doesn't roll back the moderation write.
- 9 Pest tests in `BanNotificationTest` (44 assertions) cover dispatch, channels, mail content, Inertia share, ban→unban→ban chain.

Support CTA is `mailto:support@stakly.com` (dedicated `/support` page deferred to M21).

**Phase 5 — Impersonate action + audit + banner** ✅ shipped 2026-06-04

`stechstudio/filament-impersonate` v5.5 handles the session-guard swap + leave route + Login/Logout teardown. We layer Stakly concerns on top:

- `User::canImpersonate()` (admin role only, `is_platform` excluded) + `User::canBeImpersonated()` (`is_platform` excluded, banned excluded). The package's action checks both before allowing start. Self-impersonation is also blocked by the package's own guard.
- `App\Filament\Resources\Users\Actions\ImpersonateUserAction` extends the package action with two modal fields the upstream skips — a `current_password`-validated password field (typed every time, no Fortify freshness shortcut) and a 1000-char `reason` Textarea. `before()` stashes the reason on the session so the listener can persist it. Danger color + finger-print icon. Registered as the 5th `ViewUser` header action.
- `App\Listeners\RecordImpersonationStart` writes the `admin_impersonations` row on `EnterImpersonation` (admin id, target id, reason from session, started_at, ip, user_agent). `RecordImpersonationEnd` stamps `ended_at` on the impersonator's active row on `LeaveImpersonation`. Both registered in `AppServiceProvider::boot()`.
- `App\Http\Middleware\HandleImpersonationExpiry` (global `web` group) checks `started_at` vs 30-min cap every request; calls `Impersonation::leave()` past the threshold + flashes an info toast. Cheap no-op when no impersonation is active.
- `resources/views/partials/impersonation-banner.blade.php` reads `Impersonation::isImpersonating()` and renders an amber fixed-top bar with `Viewing as @username` + Exit link. Included unconditionally in `app.blade.php` so it shows across Stakly pages, error pages, auth pages, and even `/admin` if the impersonated user clicked their way there (target won't have admin role → Filament 403). Package's own banner stays on for the panel-internal context.
- One audit-integrity edge case left open: if the admin force-logs-out from the impersonated session (vs clicking Exit), Laravel's `Logout` event fires the package's `clear()` path which doesn't dispatch `LeaveImpersonation`. The row stays open. Treat any row older than `started_at + 30 min` with `ended_at IS NULL` as orphaned (ended at the 30-min mark via the middleware's policy). Acceptable today; a future `php artisan impersonations:close-stale` scheduled task could tighten this if the operational case shows up.
- 13 Pest tests in `tests/Feature/Admin/AdminImpersonationTest.php` cover visibility (`is_platform` / banned / self / normal), validation (missing reason / missing password / wrong password), successful start (audit row + auth swap + session marker), banner render on a public page (and absence when not impersonating), exit (row closure + admin restoration), and 30-min middleware (both above- and below-threshold).

**Phase 6 — Admin 2FA challenge on every login (bridge to Fortify)** ✅ shipped 2026-06-03

Build-time research found Filament 5 ships **native multi-factor authentication** in `Filament\Auth\Pages\Login::authenticate()` — it iterates registered `MultiFactorAuthenticationProvider`s, swaps the login form to a challenge form, re-runs validation on submit, and rate-limits at 5 attempts per user. The original spec (custom login subclass + dedicated route + Inertia page + controller + middleware + rate limiter) collapses to a single provider class.

- `App\Filament\MultiFactor\FortifyAppAuthentication` (5 contract methods, ~120 LOC) bridges Filament's MFA hook to Fortify's existing 2FA columns. `isEnabled` reads `two_factor_confirmed_at`. `getChallengeFormComponents` returns `OneTimeCodeInput` + recovery `TextInput` with toggle. Validation rules call into Fortify's `TwoFactorAuthenticationProvider::verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)` and `replaceRecoveryCode(...)`. `getManagementSchemaComponents` returns `[]` so the Filament panel doesn't leak its own 2FA setup UI — admins enroll at `/settings/security` (Inertia/React), same as every other user.
- Registered in `AdminPanelProvider` via `->multiFactorAuthentication([FortifyAppAuthentication::make()])`. No custom login subclass, routes, controller, Inertia page, rate limiter, or middleware.
- `RequireAdminTwoFactor` middleware (P3) stays — it enforces 2FA *enrollment* before reaching the panel; P6 enforces 2FA *challenge* on every login. Complementary, both run.
- 8 Pest tests in `AdminTwoFactorChallengeTest` cover the full Livewire MFA flow including recovery code consumption + the admin-without-2FA fallthrough. TOTP codes generated per-test via `(new Google2FA)->getCurrentOtp(...)` — Fortify encrypts the secret via its own encrypter, not a model cast.

No "I lost my TOTP device" cancel button: admins close the tab (no session leak, they're not logged in yet) and contact support, who uses the existing `reset_2fa` action in `ViewUser` (P2) to wipe the columns. Recovery codes remain the in-band escape.

### Not in M30

- Bulk actions (bulk ban, bulk verify). Solo-dev support cadence doesn't need bulk operations.
- Chat-send-block + take-listing-block enforcement of `banned_at`. Those land in M21 (blacklist + safety) — same column, additional guards inside `SendMessageAction` + `TakeListingAction`.
- Polished "your account has been suspended" full-page landing (separate from the banner). M30 P4 ships the persistent banner + in-app notification + email — those cover the "user knows they're banned and why" surface area. A dedicated suspension-landing page (the experience when banned users click the banner's CTA or hit a guarded route) is still M21's scope alongside the broader appeals UX.
- Multi-account / IP-evasion detection for banned users. Lives with M21's anti-evasion scope.
- User-to-user blacklist UI (Alice blocks Bob). Different mental model — admin ban vs user-driven block. M21 owns the user-driven version.
- Admin action audit log beyond `admin_impersonations` + `user_moderation_logs`. M30 P2 + P4 cover the two highest-leverage audit surfaces (ban actions, impersonation sessions). Broader action coverage — a generic `admin_actions` table logging every Filament action across every resource — is its own milestone; revisit when a second-admin scenario, internal-audit requirement, or specific compliance need drives the shape.
- Notification preference / linked account mutating on behalf of the user. View-only on the user page is enough; changes to those values should still go through the user-facing settings flow.
- Admin session timeout / IP allowlist / email-on-admin-login. Adjacent admin-hardening ideas; mandatory 2FA covers the highest-leverage threat (credential phishing). Layer more on if a concrete incident drives it.
- Support / read-only admin role with restricted resource access. The `admin` Spatie role is the only privileged role today. When a real support hire happens, add a `support` role + per-resource Filament policies (read-only on UserResource / WalletTransactionResource, no impersonation, no ban, no 2FA reset). Doesn't block M30; revisit when there's a second person in the admin panel.
- Built-in support ticket system / contact form. The CMS Support page covers the contact channel today (admin writes whatever — email / Discord / form). A dedicated ticket queue is its own milestone if volume justifies.



## M31 — Admin wallet ledger ✅ shipped 2026-06-04

Read-only audit visibility into every money movement on the platform — the "where did my $12.50 go?" support tool for the custodial money platform. Filament resource over `wallet_transactions`. Two phases shipped in one day.

### Design decisions

- **Read-only resource.** `canCreate / canEdit / canDelete = false`. Every money write continues to go through `App\Services\Wallet` to preserve the `users.usdt_balance == SUM(wallet_transactions.amount)` invariant asserted in `WalletTest`. No "create transaction" / "adjust" / "transfer" actions — no such domain trigger exists today, and adding one would be a footgun.
- **`HasColor` + `HasLabel` on `WalletTransactionType` enum.** Filament's TextColumn::badge() + infolist TextEntry auto-style each case. Deposit/Payout=success, Withdrawal=danger, EscrowHold=warning, EscrowRelease=info, Fee=gray. The enum lives in `App\Enums\WalletTransactionType`; the contracts add `getLabel()` + `getColor()` methods without changing serialized values.
- **BCMath-aware money formatter on the model.** `WalletTransaction::formatAmount(string $amount): string` is used by both the table column and the infolist amount entries. Truncates to 2 decimals at scale, prepends `$`, applies thousand separators, preserves sign — without round-tripping through float. The decimal(18,6) string from the cast stays as a string the whole way through.
- **Sum summarizer on the amount column.** Footer total of currently-visible rows. Filtering by `type = Fee` + this month gives the platform's monthly revenue in one click — the drill-down companion to `OpsOverview`'s top-line stat.
- **"Sibling transactions" share an entity, NOT a reference_id.** The original M31 spec said siblings share the same `reference_id`, but the column has a UNIQUE constraint (it's the idempotency key for Wallet service writes — `findByReference` returns existing rows on repeat). The actual pattern is "two rows referencing the same match" via different prefixes — e.g. `match-payout:7` + `match-fee:7`. Implemented via `WalletReferenceParser::parseEntity()` returning `[kind, id]` and `allReferencesFor($kind, $id)` returning every known prefix combo, then a `whereIn` against the unique index. Fast lookup, no LIKE.
- **Reference parser maps to the resources that exist today.** Listing-bound prefixes (`listing-create:` / `listing-cancel:` / `listing-expire:` / `match-take:`) link to the public `listings.show` page; match-bound prefixes (`match-payout:` / `match-fee:` / `match-draw-*:` / `cancel-refund-*:`) link to the admin Disputes resource (M12's `GameMatchResource`). When M32 lands a proper `ListingResource`, only the parser needs updating — every consumer reads through it.
- **No CSV export, no charts, no per-currency filtering, no edit/adjust mutations.** Spec carve-outs hold — adjacent surfaces (`OpsOverview` widget for charts, `ListingResource` for listings) own those concerns.

### Phases

**Phase 1 — Resource scaffold + index page + filters + sum summarizer** ✅ shipped 2026-06-04

- `WalletTransactionType` enum implements `HasColor` + `HasLabel`.
- `app/Filament/Resources/WalletTransactions/` mirroring M30's structure: `WalletTransactionResource` (read-only gates, banknotes icon, Operations group, slug `wallet-transactions`), `Pages/ListWalletTransactions`, `Tables/WalletTransactionsTable`.
- Index columns: Tx# / User (link to `UserResource` view) / Type (auto-colored badge) / Amount (right-aligned, BCMath-formatted, color-coded by sign) / Reference (truncated + copyable) / When (relative + tooltip with absolute datetime).
- Filters: type multi-select (`->options(WalletTransactionType::class)` — Filament auto-detects the enum), user typeahead via relationship (no `preload()` — caused rendering issues in tests), date range, amount range, reference-contains.
- `Sum` summarizer on the amount column with the same BCMath formatter for the footer total.
- Default sort: `created_at` DESC.
- 14 Pest tests in `tests/Feature/Admin/WalletTransactionResourceTest.php` (48 assertions).

**Phase 2 — View page + Infolist + reference parser + sibling-entity lookup** ✅ shipped 2026-06-04

- `ViewWalletTransaction` page registered in `getPages()` + table-level `ViewAction` row action.
- `WalletTransactionInfolist` with three sections: Transaction (id, type badge, when, user link, amount + balance_after BCMath-formatted), Reference (raw id + parsed contextual link + description + related listing FK), Sibling transactions (other rows referencing the same listing/match).
- `App\Support\WalletReferenceParser` — `parse()` returns `[label, url]`; `parseEntity()` returns `[kind, id]`; `allReferencesFor()` returns every known prefix combo for an entity. Single source of truth for prefix→entity mapping.
- Sibling section uses `parseEntity` + `allReferencesFor` + `whereIn` so it hits the unique index instead of LIKE. Hidden when reference is unparseable / null.
- 11 additional feature tests (parser + view page + siblings + formatter) — total M31 suite is 25 tests, 122 assertions. 151/151 admin suite + 101/101 wallet suite passing.

### Not in M31

- CSV export. Add when there's a concrete external workflow (tax filing, accounting integration, auditor request) — column / format decisions follow the destination.
- Charts / time-series. `OpsOverview` widget already exposes monthly platform earnings; this resource is the drill-down.
- Per-currency filtering. USDT-only today; extends when M15-era multi-currency happens.
- Refund / adjust / cross-user-transfer actions. No domain trigger today; refunds happen via `Wallet::release` triggered by match-state events. Manual adjustments would require a future `Wallet::adjust(...)` method that doesn't exist.

---

## M32 — Admin listing management ✅ shipped 2026-06-04

Operational visibility + force-cancel for the marketplace. The lowest-urgency of the three admin gaps, but enables takedown of abusive listings (sub-penny stakes, off-platform deal solicitation in the title, harassment-style descriptions) without dropping to Tinker. Admin views every listing the same way users see them, plus a single moderation action.

### Design decisions taken into this milestone

- **Index columns** — id, creator (link to UserResource view), state badge (Open / Taken / Cancelled / Expired), platform (chess.com / Lichess), stake_amount (right-aligned), skill range, time controls, region, languages, created_at, expires_at.
- **Filters** — state multi-select, platform, stake range, creator typeahead, region, has-language.
- **One action: Force cancel.** Routes through the existing `CancelListingAction` so escrow releases via `Wallet::release` and the ledger stays clean — the admin never writes to `usdt_balance` directly. Confirm dialog names the listing id + stake + creator so a wrong click is hard. Listing must be in `Open` state; Taken / Cancelled / Expired states have no force-cancel action (the corresponding match flow handles those cases through `GameMatchResource`).
- **View page.** Full listing data, related match (if Taken — link to `GameMatchResource`), related wallet transactions (escrow hold + any release on cancel).
- **No edit action.** Stake / skill range / platform are immutable on a real listing — changing them mid-flight invalidates expectations for any taker. If a listing needs changes, the right path is force-cancel + the creator re-creates.
- **No bulk cancel.** One listing at a time; bulk-cancel is a footgun and there's no operational scenario that needs it.
- **`ListingStatus` gets `HasColor` + `HasLabel`.** Same pattern M31 used for `WalletTransactionType` — auto-colored badges across every Filament surface that reads this enum (admin index, view page, dashboard widgets, M32 + future).
- **The M31 wallet-reference parser already knows about listing prefixes.** `listing-create:` / `listing-cancel:` / `listing-expire:` / `match-take:` are all entity-mapped to "listing" via `WalletReferenceParser::parseEntity()`. The View page's wallet-transactions section calls `WalletReferenceParser::allReferencesFor('listing', $id)` + `whereIn('reference_id', $candidates)` (plus FK match on `related_listing_id`) — fast indexed lookup, no LIKE, no parser logic re-implementation.

### Phases

**Phase 1 — Resource scaffold + index page + filters** ✅ shipped 2026-06-04

- [x] `App\Enums\ListingStatus` implements `HasColor` + `HasLabel`. Open=success, Taken=warning, Expired=gray, Cancelled=danger.
- [x] `app/Filament/Resources/Listings/` folder: `ListingResource` (read-only — `canCreate / canEdit / canDelete = false` on the resource, force-cancel surfaces only as a header action on the View page in P2), `Pages/ListListings`, `Tables/ListingsTable`.
- [x] Index columns — id, Creator (link to `filament.admin.resources.users.view`), Game, Platform, Stake (right-aligned, `$X.XX USDT` via `number_format((float) $state, 2)` — `decimal(12,2)` doesn't need BCMath display precision the way the ledger does), Skill range (formatted "1200–1600"), Time controls (joined from the `time_control` jsonb column), Region, Languages (joined from the `language` jsonb column), Status (auto-colored badge), Created at, Expires at.
- [x] Filters — status multi-select via `->options(ListingStatus::class)`, platform select, stake range (custom Filter with min/max TextInputs), creator typeahead via `relationship('user', 'username')->searchable()`, region select, has-language text/contains filter against the jsonb column.
- [x] Default sort: `created_at` DESC.
- [x] Pest tests in `tests/Feature/Admin/ListingResourceTest.php` — admin-only access, non-admin 403, guest redirect, list renders, read-only posture (canCreate/canEdit/canDelete all false), each filter narrows correctly, default sort newest-first.

**Phase 2 — View page + force-cancel action + related entities** ✅ shipped 2026-06-04

- [x] `Pages/ViewListing` registered in `getPages()` + table-level `ViewAction` row action.
- [x] `Schemas/ListingInfolist` with three sections:
  - **Listing details** — every column rendered, creator link to `UserResource` view, status / platform / game badges.
  - **Related match** — visible only when `status === Taken`; link to `route('filament.admin.resources.disputes.view', $match->id)` (the M12 `GameMatchResource`). Hidden otherwise.
  - **Wallet transactions** — every `wallet_transactions` row tied to this listing. Lookup combines `whereIn('reference_id', WalletReferenceParser::allReferencesFor('listing', $id))` with `orWhere('related_listing_id', $id)` so the FK-bound rows (escrow holds via `match-take:{listingId}`, escrow holds on listing-create, refunds on cancel) all appear. Reuses M31's HTML-summary pattern.
- [x] Force-cancel header action — `danger` color, confirm modal showing `Listing #X · $Y stake · @creator` so misclick risk is low. `visible(fn $r => $r->status === ListingStatus::Open)`. Callback: `app(CancelListingAction::class)->handle($record)`. Defense-in-depth re-check `status === Open` inside the callback (stale-cache safety). Filament notification on success.
- [x] Pest tests — view page renders, force-cancel action hidden on Taken/Expired/Cancelled, action visible+working on Open, force-cancel flips status to Cancelled AND emits an `escrow_release` ledger row with `listing-cancel:{id}` reference (via `Wallet::release`), creator's balance returns to pre-listing state, action stays hidden after cancel (idempotent at the visibility layer).

### Not in M32

- Manual "create listing on behalf of a user" action. No legitimate support reason; a vector for admin abuse if it existed.
- Force-expire (separate from force-cancel). The expiry clock is automatic; manual expiry without refund is a money operation that should go through the existing cancellation path. If we ever need "skip the timer," it's the cancellation action with the same refund behavior.
- Listing dispute moderation (separate from match dispute moderation). Match disputes are covered by `GameMatchResource` (M12). Pre-match listing disputes don't exist as a concept.
- Editing listing description / title (no fields exist today on listings — listing is just stake + skill + time control + region + languages). If a future listing schema adds free-text fields, moderation routes through M13 chat-anti-abuse patterns, not via direct admin edits.

---

## M34 — Team play + lobbies ✅ shipped 2026-06-12 → 2026-06-14

Production-launch dependency for CS2 (FACEIT competitive is 5v5 with no native ranked 1v1) and every future 5v5 game. Adds a **soft-join lobby** between "listing posted" and "match Pending" for `team_size > 1` listings; chess (1v1) keeps its existing `TakeListingAction` flow unchanged. CS2 = 5v5 + 2v2 Wingman. Shipped P0–P9 (+ P3.1, P3.2), suite 1330 → ~1533. Two P3.1 follow-ups remain deferred and live in `milestones.md` (country flags + per-player W/L form).

### Core flow — hybrid stake-at-Ready

Listing creation escrows nothing; the creator is auto-soft-joined to slot 0. Soft-join is free + reversible (no money moves). Clicking **Ready** escrows *that player's* stake in the moment — per-player atomic, so there's no all-N balance race at match start; un-Ready / leave refunds until lock. All `2 × team_size` Ready → match flips `Pending`, lobby `locked`, leaving is now a forfeit. **One active lobby per user globally.** 5-min ready-check timeout (non-Ready vacated, already-Ready keep escrow + stay; if the creator is vacated the whole lobby cancels + refunds). Owner kick with a 5-min same-listing rejoin cooldown (kicked row preserved as audit + cooldown anchor, refunded if Ready'd). 24h fill timeout cancels + refunds. Insufficient balance at Ready is a silent, penalty-free retry.

### Decisions (load-bearing)

- **`listings.team_size`** (int, default 1) drives lobby size (`2 × team_size`). `creator_side` ('a'|'b'), `lobby_state` (`recruiting → ready_checking → locked → cancelled/expired`), `is_public`, `invite_token` (32-char, unique) — all nullable/defaulted so legacy chess payloads stay valid.
- **`lobby_participants` table** — `is_ready` + `stake_held_at` (null = soft-joined, set = escrowed; **the two always flip together** — no Ready-without-escrow, no orphaned hold) + `kicked_at`. Two **Postgres partial unique indexes** (`WHERE kicked_at IS NULL`) on `(listing_id, side, slot_index)` and `(listing_id, user_id)` so kicked rows don't poison live constraints (slot reclaimable, user rejoins post-cooldown). Built via `DB::statement` (Laravel has no first-class partial-index API).
- **`MatchStatus::LobbyFilling`** — the match row exists from listing creation so chat works day 1; flips to `Pending` at lock. Every `status === Pending`-assuming consumer got an explicit carve-out (auto-fetch skip, policy view/dispute/cancel gates, `/matches/{id}` redirect, username-change in-flight check, Filament exhaustive expressions, TS unions).
- **Per-player skill range** — each joiner's snapshotted rating must fall in `[skill_min, skill_max]`; per-team averaging explicitly rejected (one whale can't carry low-skill teammates and break the trust pitch).
- **`match_provider_snapshots.slot_index`** populated 0..team_size-1 at lock so settlement maps snapshot → user with team identity preserved; unique constraint extended to `(match_id, side, slot_index, provider)`.
- **Settlement (`SettleTeamMatchAction`)** — `pot = stake × team_size × 2`, `fee = pot × fee_rate`, `perPlayer = bcdiv(winnings, team_size, 6)`; the slot-0 winner absorbs the truncation remainder so the **conservation invariant `sum(payouts) + fee == pot` holds exactly** (asserted in-action). Per-player payout ref `match-payout:{match}:player-{user}` (idempotent).
- **Real-time** — `private-lobby.{id}` channel (`LobbyChannel`, auth mirrors `ListingPolicy::viewLobby`), `LobbyUpdated` event (`ShouldBroadcast` + `ShouldDispatchAfterCommit`, minimal id-only payload — a trigger, not a transport; server `LobbyResource` stays the source of derived truth). Dispatched from every roster/state-mutating action on success only. Frontend `<LobbyRealtimeSync>` → `router.reload({ only: ['lobby'] })`, mounted only while auth + live so terminal states tear down the socket cleanly.

### Phases

- **P0 — Schema + lobby model.** Migrations (listings team-play columns; `lobby_participants`; `slot_index` on snapshots), `MatchStatus::LobbyFilling`, models + factories + seeder. +18 tests.
- **P1 — Backend lobby flow.** All actions (`CreateTeamPlayListing`, `Join`, `Leave`, `ToggleReady`, `Kick`, `LobbyReadyCheck`, `LobbyReadyCheckTimeout`, `LobbyLock`, `LobbyFillTimeout`) + two cron sweeps (`lobbies:sweep-ready-check-timeouts` per-minute, `lobbies:sweep-fill-timeouts` hourly, `withoutOverlapping`) + a dedicated `scheduler` container in `compose.yaml`. Full `LobbyFilling`-consumer audit. +48 tests.
- **P2 — Private invite links.** `Game::allowedTeamSizes()`, `StoreListingRequest` team-play fields, `store` branching, `scopeOnPublicMarketplace` hides private listings, `/lobbies/{token}` resolver. +16 tests.
- **P3 — Frontend lobby UI.** `ListingPolicy` lobby gates, `LobbyController` endpoints, `LobbyResource`, the lobby page (5s polling at this stage — replaced in P3.2), chat reuse via an optional `participants` prop. +16 tests.
- **P3.1 — Unify lobby into the listing page.** Collapsed `/lobbies/{id}` → canonical `/listings/{id}` (301 redirect for legacy URLs, `viewLobby` relaxed). Marketplace listing-card team-play adapter (`TeamSizeBadge` / `LobbyStateBadge` / `LobbyFillCounter`), rows↔grid view toggle (cookie-persisted, roster preview in grid), FACEIT-grade 3-col center column (Money HERO / Skill matchup / Trust / state-dependent Coordination) via `LobbyResource.aggregates`, new `ParticipantStats` service. Three dogfooding polish rounds (CS2 locked to 5v5-only; Ready/Leave folded into the Money block as traffic-light buttons; chat → participant-gated floating FAB; slot stats → Matches / Win-rate / Completion-30d; homepage "Ending soon" unified to `ListingGridCard`). **Two follow-ups deferred — now tracked in `milestones.md`.**
- **P3.2 — Real-time lobby via Reverb.** Replaced 5s polling with `private-lobby.{id}` + `LobbyUpdated` (channel/event above). Also fixed a match-chat 403/leak (non-participants subscribing to `private-match.{id}`) by extracting `<LobbyChatPanel>`, mounted only for live participants. +18 tests.
- **P4 — FACEIT 5v5 verification (the gating work).** Generalized the 1v1 pipeline to N-vs-N: `snapshotProviderUserIds`, strict `isOpposingTeamRosters` (all 10 Stakly GUIDs must be on the FACEIT match — strict-no-partial; a missing player → no candidate → 4h timeout → ManualReview, safer than a wrong settle), `SettleTeamMatchAction` fan-out, additive card payload (`winning_team` + `winner_user_ids` alongside legacy 1v1 fields), defensive winner-roster check in `SettleFromCardAction` (strict containment). +9 5v5 tests. **CS2 production launch unblocked.**
- **P5 — CS2 create-form + 2v2 Wingman + private post-create UX + chat-leak fix.** Create form ships `team_size`/`creator_side`/`is_public` (segmented Format/Side/Visibility controls); `Cs2->allowedTeamSizes()` → `[2, 5]`; team-play creators redirect to the lobby with an owner-only `LobbyInviteBanner`. Closed a chat-content leak — `showTeamPlay` now gates `messages.data` on live participation (strangers/guests/kicked get `collect()`). +9 tests.
- **P6 — Team-aware dispute + cancellation.** Team-aware `GameMatchPolicy::isParticipant` (reads the live `LobbyParticipant` roster) + same-team-accept block; `AcceptCancellationAction` refund fan-out (ref `cancel-refund:{match}:{user}`); new `MatchParticipants` service + notification fan-out across the dispute/cancellation actions; `GameMatchResource` team rosters + `winning_team` (1v1 shape preserved verbatim via `mergeWhen`); team-aware `match/show.tsx` (`TeamMatchView` / `TeamRosters` / `TeamSettlementSummary`); `SettleDrawMatchAction` + `AdminSettleToWinnerAction` + `AdminSettleDrawAction` made team-aware (Filament "Settle to Team A/B / Refund all"). +42 tests. Full lifecycle (lock → Pending → cancel/dispute → admin settle → fan-out payouts) works for 5v5 + 2v2.
- **P7 — FACEIT-style lobby header bar.** Replaced the plain title block with a unified header (team-leader avatars + "Team {leader}" + mode chip + a state-aware countdown that morphs by `lobby_state` + Share via `navigator.share`/copy); folded the ready-check countdown out of `CoordinationPanel` into the header. `LobbyResource.match_deadline_at` added (Pending-gated). +2 tests.
- **P8 — Team match-page polish + lobby-lock notification.** Match-page roster card brought to lobby `slot-card` parity (avatars, crowns, rating chips, stats); "Team {leader}" labels via shape-agnostic `lib/team-leader.ts`; new `TeamMatchStartedNotification` (sound-default ON) dispatched from `LobbyLockAction` to every locked-in participant.
- **P9 — Match-details strip inside the Rosters card.** Compact horizontal strip (Pot · Stake · +win · −lose + "Verified via FACEIT" chip) at the top of the team match page's Rosters card, closing the gap where the lobby's Money block disappears at lock; `potentialWinnerPayout` computed so draws don't zero the headline.

### Not in M34

- Unifying chess (1v1) to the lobby model — `TakeListingAction` keeps handling `team_size = 1`; revisit only on a UX need.
- Captain mode / explicit leader role beyond "owner can kick"; spectator slots; mid-match player replacement (FACEIT doesn't support it for our verification model); cross-server roster verification (we rely on the verified-match-record — all 10 in the same FACEIT match with correct factions is the proof); anti-collusion beyond the skill-range gate.

---

## M35 — Outbound third-party API rate-limit audit ✅ shipped 2026-06-11

Swept every outbound HTTP integration to confirm each has: client-side self-throttle (`RateLimiter::for(...)` + `RateLimited` job middleware, default 30 req/min when the provider limit is undocumented), 429 header handling (`RateLimitHeaderParser` — `Retry-After` / `X-RateLimit-Reset`), a per-provider `ProviderCircuitBreaker`, and explicit `->timeout()` / `->connectTimeout()`. Goal: zero production 429-driven settlement freezes. All caps env-tunable via `{PROVIDER}_REQUESTS_PER_MINUTE`.

### Phases

- **P0 — Inventory + audit (read-only).** Per-provider gap table across every `Http::` callsite + Provider client + the link-preview job. Found: all three **game** clients had breaker + 429 + retry but **no self-throttle**; all three **profile** clients (sync, controller-driven) had timeouts only (no throttle/breaker/429-classification); `FetchLinkMetadataJob` is arbitrary-URL (global throttle only, intentional `$tries=1`).
- **P1 — chess.com.** `RateLimiter::for('chess-com-api')` (cap from `services.chess_com.requests_per_minute`, default 30) + `RateLimited` middleware on `AutoFetchChessComGameJob` (`$tries` 7→15 to absorb throttle releases; `retryUntil()` stays the real safety net). `ChessComProfileClient` brought to game-client parity (429 → `RateLimitedError` + retry-at; 5xx → transient; 4xx → permanent; 404 → `ProfileNotFoundException`, counts as breaker success; constructor takes the breaker). +12 tests.
- **P2 — Lichess.** Same shape; `lichess-api` cap default **60** (Lichess documents 1200/min — 20× margin). `$tries` 4→12. `LichessProfileClient` upgraded. +12 tests.
- **P3 — FACEIT.** Same shape; `faceit-api` cap default 30 (undocumented quota; conservative-cap rule). `$tries` 7→15. `FaceitProfileClient` upgraded — graceful-null path on a missing API key preserved (no request, no breaker signal). +14 tests.
- **P4 — Telemetry pass — skipped.** Existing `PipelineHealth` coverage is sufficient; throttle-wait visibility can land later as a small follow-up if real production caps get hit.

### Decisions

- **Profile-client self-throttling intentionally omitted** — called synchronously from controllers, not queued jobs, so `RateLimited` middleware doesn't fit; revisit with controller-level `throttle:` if a real abuse vector surfaces.

### Not in M35

- Throttling internal Stakly→Stakly APIs (covered by `throttle:` on auth routes); inbound webhook rate-limiting (per-receiver `throttle:60,1`); token-bucket vs leaky-bucket tuning (Laravel fixed-window is fine for our load).

---

## M36 — Active-matches quick access ✅ shipped 2026-06-23

Surfaced "what am I doing right now" Bybit-style: a **live count badge** on the player-hub sidebar's **Matches** item + an **"In Progress / All" toggle** on `/matches` that defaults to In Progress. "In progress" = `Pending + Disputed + ManualReview` (started, not finished, money may be escrowed); excludes terminals + unlocked team lobbies (`LobbyFilling`). Reused the existing sidebar, `/matches` page, the shared-props count pattern (mirrors `unread_notifications_count`), and Reverb — no new route or section.

### Phases

- **P1 — Backend.** `MatchStatus::inProgress()` / `inProgressValues()` as the single source of "active". New `GameMatch::scopeForRosterParticipant` (creator OR taker OR live `LobbyParticipant`) — a sibling to `scopeForParticipant`, used only by the matches index + shared count so public-profile / username-blocker / admin numbers stay on the narrower creator-or-taker scope. `active_matches_count` on `auth.user` shared props. `IndexMatchesRequest` + `GameMatchController::index` gained the In Progress (default) vs All (`?view=all` → status chips) view contract.
- **P2 — Frontend.** Sidebar count badge (expanded pill / collapsed-rail dot; aria-label carries the count). `match/index.tsx` underline-tab "In Progress / All" toggle matching `MineTabs`. "Nothing live right now" empty state.
- **P3 — Real-time.** `NotificationProvider` fires `router.reload({ only: ['auth'] })` (scroll/state preserved) on the match notifications that cross the in-progress boundary — `listing_taken`, `team_match_started`, `match_settled`, `dispute_resolved`, `cancellation_accepted` — reusing the existing moderation-reload path (no new channel). Same-set transitions excluded (count unchanged). Actor refreshes via their own Inertia response; the broadcast covers the counterparty.

### Decisions

- **Sibling scope, not a widened one** — `scopeForRosterParticipant` exists precisely so team-awareness doesn't leak into public-facing profile / username / admin counts.
- **Count rides global shared `auth`** so the live badge reload works from any page; recomputed per request (not cached), pinned by Pest guards.

### Not in M36

- Counting unlocked team lobbies in the badge; making profile / username-blocker / admin queries team-aware; a dedicated route; desktop push; live-refreshing the `/matches` list *rows* (badge refreshes via shared `auth`; the list is a per-page prop — separate concern, cheap follow-up noted).

---

## M37 — One active match per game ✅ shipped 2026-06-23

Closed a concurrency hole: chess `TakeListingAction` had no "already in a match" guard, so a player could take unlimited simultaneous chess matches (CS2 already blocked this via the lobby). **Policy — one active match _per game_:** a chess match + a CS2 match at once is fine, two of the same game is not. Two concurrent *same-game* matches are where API settlement can mis-attribute a result (auto-fetch picks the game closest to each match's start time — two Alice-vs-Bob games in overlapping windows can settle the wrong match); different games never overlap. Full suite 1554 green (+10).

### Phases

- **P1 — The lock.** `User::hasInFlightMatchForGame(Game)` (team-aware, in-progress set, per game). `TakeListingAction` guards the taker (`already_in_match`) + listing owner (`owner_busy`) inside the locked tx, with stable-order (ascending-id) participant locks to prevent deadlock; folded the existing `ownerIsActive` check onto the same locked row. New sentinels → toasts in `GameMatchController::take`. CS2 unchanged (`activeLobbyParticipation` already enforced one-CS2-at-a-time + allowed a chess match alongside). +5 Pest.
- **P2 — The busy sign.** `Listing::scopeWhereCreatorNotBusyForGame` (correlated `whereNotExists` anti-join) composed into `scopeOnPublicMarketplace` — the board, homepage, and visitor profile drop a creator's same-game listings while they're mid-match, reappearing when it resolves; CS2 offers stay up during a chess match; the owner still sees their own listings on their own profile (that path uses `scopeOpen`). 1v1-only correlation — team listings can't dangle. +3 Pest.
- **P3 — Frontend gating.** Shared `auth.user.in_flight_games` (distinct games the viewer is mid-match in), derived from a single in-flight-matches fetch in `HandleInertiaRequests` that now also feeds `active_matches_count` (one query, not two). Detail-page Take CTA (`show.tsx`) → disabled "Already in a match" + "View your matches →"; marketplace card (`take-button.tsx`) → disabled "In a match". Server stays authoritative. +2 Pest.

### Decisions

- **The guard is the fix; hiding is polish.** The authoritative, race-safe guard lives in the locked take transaction; board-hiding never replaces it (direct URLs, stale tabs, double-take races still hit the guard).
- **Per-game, not global** — `hasInFlightMatchForGame` is scoped per game; the username-rename blocker stays global (any match blocks a rename).

### Not in M37

- Raising the per-game cap above 1 (would first need settlement attribution hardened — store + validate opponent identity per game — then a config cap). Pausing a hidden listing's expiry timer while its owner is busy (a listing can still expire + refund mid-match; acceptable for now).

---
---

## M15 — Multi-game expansion (CS2 via FACEIT) ✅ phases 0–5 shipped 2026-06-07 → 2026-06-11

The multi-game realization of M8's per-provider adapter pattern: chess (M8) joined by CS2 via FACEIT, with the same shape reused for every future game. Phase 0 FACEIT API research → Phase 1 schema extension (`linked_accounts.provider_user_id` + `skill_rating`; `match_provider_snapshots.provider_user_id` + `skill_rating_snapshot`) → Phase 2 FACEIT OAuth link flow (`socialiteproviders/faceit` + local PKCE `FaceitProvider`) → Phase 3 per-game create form + listing-creation gating → Phase 4 FACEIT outcome pipeline (`FaceitGameClient`, `AutoFetchFaceitGameJob`, `FaceitGameApi` adapter, `/webhooks/faceit` receiver scaffold) → Phase 5 dispute fast-path + telemetry (`Game::hasArbitrationDriver()` gate, `FaceitGameApi → ChessGameApi → MockGameApi` composition chain, per-provider circuit-breaker config, `PipelineHealth` per-provider breakdown). CS2 5v5 settles end-to-end through the polling pipeline; **CS2 production launch was unblocked by M34 P4** (lobby + multi-player stake collection). Two non-blocking follow-ups remain (tracked in `milestones.md` Active): lock the webhook `event_id` field name + idempotency decision, and the webhook egress IP-allowlist.

### Decisions / reference (load-bearing for future game adapters)

- **Trust pitch — strongest anti-cheat per game.** Stakly only stakes matches on the strongest available anti-cheat platform: Chess → chess.com/Lichess; CS2 → FACEIT (FACEIT AC); Dota 2 → FACEIT Hub or Steam-ranked (OpenDota verify); Valorant/LoL → Vanguard + Riot API. Games without both a usable anti-cheat AND a verification API stay out of scope.
- **Per-player AC gate, not per-queue.** Phase 4's filter rejects matches where any roster entry has `anticheat_required === false` (FACEIT AC is mandatory on matchmaking but opt-in on Hubs) — the per-player roster boolean is the only reliable signal.
- **FACEIT API specifics** (Phase 0/2): OAuth is PKCE-mandatory (confidential client; `code_verifier` in body AND Basic auth header) — Stakly's `App\Services\Provider\FaceitProvider` re-adds the `code_verifier` the upstream package drops. Data API needs a server-side API key (OAuth user tokens 403 against it); host `open.faceit.com`. Winner in one call: `results.winner ∈ {faction1,faction2}` + `teams.*.roster[]`. Webhook (`match_status_finished`) has NO HMAC — static shared secret only, so every settle re-fetches `GET /matches/{id}` before releasing escrow. One redirect URI per OAuth app → separate dev + prod FACEIT apps; HTTPS-only redirect URIs.
- **`match_provider_snapshots` extension** — for non-chess identifiers, add nullable per-identifier columns (`steam_id`, `faceit_id`, `riot_region`, `mmr_at_snapshot`) OR a `provider_data` JSONB column; decide per-adapter. Current `username` sized 64 covers Riot IDs + Steam vanity URLs.
- **Per-game filter UI** — extract chess-specific widgets into siblings (`ChessFormatFilter` etc.) and branch per game; no premature generic-filter abstraction (the variable shape emerges from the second game). Narrow `ChessProvider` vs wide `ListingPlatform` type discipline already in place.
- **Aggregator-as-a-service (PandaScore/Bayes/Abios) — parked.** Reconsider only if per-game maintenance gets painful and revenue absorbs the cost.

---

## M38 — Redis for queue, cache & sessions (launch-readiness) ✅ P1–P3 shipped 2026-06-23 · paused at P4 (2026-06-24)

Moved the hot, latency- and correctness-sensitive infra (queue, cache, sessions) off the Postgres `database` driver onto Redis, on distinct logical DBs (0 default/locks/Horizon · 1 cache · 2 queue · 3 session) so a `cache:clear` can't evict queued settlement jobs or log everyone out. **P1** driver flip + connection hygiene (new `queue`/`session` Redis connections; CI stays array/sync/array via `phpunit.xml` so the `.env` flip never leaks into tests). **P2** settlement-path resilience via Laravel 13's native `failover` cache store (`redis → array`) — the infallible in-memory tail means a Redis blip degrades gracefully (breaker reads fail-open, catalog/CMS caches recompute from Postgres, limiter stops blocking) with zero app-code changes; `CacheFailedOver` → `LogCacheFailover` is the alert hook. **P3** Horizon (admin-gated `/horizon` reusing `User::isAdmin()`, `horizon:snapshot` every 5 min). **P4 (production wiring) is deferred to deploy day** — host configuration that can only happen on the live DigitalOcean target. The one repo-side P4 prerequisite shipped early: TLS-capable Redis config (`'scheme' => env('REDIS_SCHEME', 'tcp')` on all four connections; `tls` in prod for DO Managed Redis), since DO requires TLS and Laravel never enables it automatically.

### Decisions

- **Failover store, not hand-rolled try/catch** — the `array` tail is infallible so no cache call can throw; fail-open + recompute + limiter-allow fall out for free. **Caveat:** sessions + queue are NOT under the failover net (it covers cache only) — a Redis outage logs everyone out for its duration (ephemeral, no money-loss) and pauses the queue (workers retry). Queue-failover was rejected (splits jobs across redis+postgres).
- **Target = DigitalOcean** (chosen 2026-06-24); recommend Droplet + Laravel Forge (one-click Horizon + Reverb daemons fit the app's three long-lived processes — Horizon worker · Reverb · scheduler).
- **Eviction policy (deploy-day):** Redis `maxmemory-policy` is per-instance, not per-logical-DB, so the cache-`allkeys-lru` / queue+session-`noeviction` split needs **two Redis instances**. Evicting a queued settlement job = lost money work; evicting sessions = mass logout.
- **P4 deploy-day checklist** (if M38 is resumed): provision Redis (two instances), set `REDIS_SCHEME=tls` + auth, configure eviction per-instance, run `php artisan horizon` under Forge's daemon + `horizon:terminate` on deploy, smoke-test a real settlement + a Redis-blip degrade. **Not in M38:** Reverb horizontal scaling (single-node today).

---

## Parked milestones

Work that has a clear shape but isn't being picked up right now. Lives in the archive so the active milestones list stays focused on what we can act on; revisit if priorities shift.

### M13 — Chat anti-abuse + moderation [parked]

**Why parked:** the original framing assumed every flagged-keyword case was a clear off-platform-deal attempt, but the actual designs (regex flags + flagged-messages dashboard + per-day caps + blocked-words list) need a tighter problem definition before they're worth building. Pre-launch with no real chat volume, building a moderation pipeline against hypothetical patterns risks false positives across legitimate match-coordination chat. Revisit once there's real chat traffic to study OR a concrete abuse incident to design against.

**Sketch that was drafted (kept for future reference):**

- Phase 1 — Off-platform deal detection: regex flags in `SendMessageAction` for TRC20 / ERC20 / BTC addresses + payment-method names + messenger handles + trade-coordination phrases. Flagged messages still post (don't tip the abuser) but write to a `flagged_messages` table with the trigger pattern. Filament dashboard widget for recent flags.
- Phase 2 — Rate limits + report-user button: per-user chat soft-warn UI on top of the M8 Phase 2 10-msg/10s limit, per-match-day cap (200 messages), per-message report-user button writing a Filament-routed report.
- Phase 3 — Blocked words + admin moderation tools: configurable blocked-words list (slurs / harassment) filtered server-side, admin moderation panel for flagged + reported users, mute / ban tools with history.

**Adjacent dependencies (when picked up):**

- M20 — could add `MessageFlaggedForReviewNotification` (admin bell) using M27's dispatch pattern.
- M21 — blacklist enforcement overlaps with mute / ban; align the two before scoping M13's admin tools to avoid duplicate ban paths.
- M30 — admin user ban / moderation actions land in M30; M13's mute is a chat-specific subset rather than an account-wide ban.



