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
