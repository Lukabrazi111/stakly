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

**State machine**:

```
Listing.Open --[take]--> Match.Pending, Listing.Taken (taker's Wallet::hold)
Match.Pending --[both confirm same winner]--> Match.Settled
Match.Pending --[both confirm Drawn]--> Match.Settled (refund both, no fee)
Match.Pending --[both confirm different]--> Match.Disputed
Match.Pending --[one confirms, 4h passes]--> Match.Settled (claim honored)
Match.Pending --[neither confirms, 4h passes]--> Match.Disputed
Match.Pending --[either opens dispute]--> Match.Disputed
Match.Pending --[one requests cancel, opponent accepts]--> Match.Cancelled (refund both, no fee — M10)
Match.Pending --[one requests cancel, opponent rejects]--> Match.Pending (30min cooldown on requester — M10)
Match.Disputed --[game-API returns winner]--> Match.Settled
Match.Disputed --[game-API can't determine]--> Match.ManualReview (terminal — admin resolves out-of-band)
```

> The player-confirm paths in this diagram are superseded by M16 (API-only outcome resolution). When M16 ships, the "both confirm" / "one confirms, 4h passes" / "both confirm different" branches disappear and the API becomes the only settlement signal during Pending.

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

## M14 Slice A — Chess card arbitration ✅ shipped 2026-05-22

Pulled forward from the broader M14 milestone (still active in `milestones.md`) because the mock arbitration was paying the wrong player when a Lichess card showed a different winner — a visible "the system is broken" symptom every time a dispute hit during dev.

`App\Services\GameApi\ChessGameApi` implements `GameApi`. Reads the most-recent auto-fetched card (`source: 'auto_fetch'`) off the match's chat — provider-agnostic (handles both Lichess and chess.com via the card's `provider` field) — and returns the named winner with `Confirmed` confidence. Maps `winner_username` to a Stakly `user_id` via snapshotted handles (case-insensitive). Falls through to `MockGameApi` for no card, race window (auto-fetch hasn't completed), paste-only cards, or unmappable winners (defensive).

`AppServiceProvider::bindGameApi` registers `MockGameApi` as its own concrete + binds `GameApi::class` to `ChessGameApi` (wrapping `MockGameApi` as fallback). `config/stakly.php` default driver: `'chess'`. `tests/Pest.php` `mockGameApi()` helper resolves `MockGameApi::class` directly so existing tests using `forceWinner()` keep working through the wrapper's fallback path.

Originally landed Lichess-only as `LichessGameApi`; Phase 4b extended to chess.com cards and renamed to `ChessGameApi`. Full M14 (FACEIT, OpenDota, Riot, plus no-admin-fallback policy) still lives behind the original trigger: M12 admin path in use + dispute volume signal.
