# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

## Phases (map)

- **M1** — Design Foundation + Homepage ✅
- **M2** — Auth Flow ✅
- **M2.5** — Pre-M3 polish ✅
- **M3** — Listings Index ✅
- **M3.5** — Wallet / Ledger Foundation ✅
- **M4** — Listing Detail + Create Flow ✅
- **M5** — User Profile ✅
- **M6** — Match Flow (mock) ✅
- **M7** — Wallet UI ✅
- **M11** — Controller Refactor to Actions Pattern ✅
- **M8** — Match Chat + Linked Accounts ✅ (all phases shipped — Phase 5 Slice C closed it out 2026-05-22)
- **M14 Slice A** — `ChessGameApi` card arbitration ✅ (pulled forward from full M14)
- **M10** — Mutual Match Cancellation **← next**
- **M12** — Filament admin panel + chat-driven dispute resolution
- **M13** — Chat anti-abuse + moderation
- **M14** — Automated outcome adapters (volume-triggered optimization; Slice A shipped)
- **M15** — Multi-game expansion (FACEIT, OpenDota, Riot adapters)
- **M9** — Chain Integration [paused — pending crypto-payment-gateway specialist]

> Only the active milestone (M8) keeps a detailed task list. Shipped phases are one-paragraph summaries — the code is the source of truth for "how it works." Decisions worth surviving in the doc go in the per-phase `Decisions` blocks. Future milestones expand when started. Any of this can shift — flag the change, update the doc.

---

## Architectural decisions

Decisions made earlier that have shaped a lot of code downstream. Not locked — revisit if the situation changes, just expect a ripple of refactor when you do.

- **Money writes only through `App\Services\Wallet`** (M3.5). `users.usdt_balance` and `wallet_transactions` are written ONLY by Wallet service methods. The invariant `users.usdt_balance == SUM(wallet_transactions.amount)` is asserted in `WalletTest.php`. Direct writes from controllers / seeders / migrations / factories / tinker break this.
- **Money math is BCMath strings, never floats** (M3.5). Internal arithmetic at scale 6 via `bcadd` / `bcsub` / `bccomp`. Floats only appear at the API resource boundary.
- **Append-only ledger** (M3.5). No `updated_at` on `wallet_transactions`. Idempotency via optional `reference_id` — repeat calls return the existing row silently.
- **Actions pattern** (M11). Business logic lives in `app/Actions/<Domain>/<Verb><Noun>Action.php` with a `handle()` method, container-injected. Controllers and commands are thin adapters. `App\Services\Wallet` and `App\Services\GameApi\*` stay as primitives, not Actions. Decompose long `handle()` bodies into private helpers so `handle()` reads like a recipe of high-level steps.
- **`MatchStatus` state machine guard** (M6 Phase 7). `SettleMatchAction` / `SettleDrawMatchAction` no-op on `Settled` (idempotency), proceed on `Pending` / `Disputed`, throw on `ManualReview` or unknown. Future admin tools resolving `ManualReview` must use a different path.
- **`ManualReview` is admin-resolved out-of-band**, not in player chat (M6 Phase 7). Admin reads via Filament dashboard (M12); no admin-in-chat.
- **Snapshot, don't link** when a relationship needs to survive identity changes (M8 Phase 4). Linked-account usernames are denormalized onto the sibling `match_provider_snapshots` table at match creation so a mid-match unlink doesn't break dispute resolution. Sibling table (not flat columns on `game_matches`) so per-provider identifier shape can grow with multi-identifier games (M15) without re-migrating the wide table.
- **One arbitration driver per game family** (M14 Slice A). `ChessGameApi` handles both Lichess and chess.com cards via the card's `provider` discriminator. M15 game adapters (FACEIT, Riot, etc.) each get their own sibling driver. No multi-provider chain wrappers — the card carries its own provider field.
- **`is_platform = true` users are never user-facing** (M3.5 + M5). Filtered from profile show, wallet UI, listing pages.
- **No chain code or smart contracts right now** (project-wide). Custodial via internal Postgres ledger; chain integration is M9, paused for a specialist.
- **Strongest anti-cheat per game** (M8 + M15). Stakly only takes stakes on matches played on the strongest available anti-cheat platform for the relevant game. The verification provider (who tells us the result) and the anti-cheat platform (where the match must be played) are conceptually separate — sometimes the same vendor (FACEIT for CS2, Riot for Valorant), sometimes different (Steam-ranked Dota 2 verified via OpenDota). Per-game adapter pattern via `LinkedAccountProvider` enum + `ProfileClient` interface + `listings.platform` column.

---

## Shipped milestones

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

## M10 — Mutual Match Cancellation

The fourth resolution path for a Pending match. Today a match has three exits: both players confirm an outcome → `Settled`; either opens a dispute → `Disputed`; the 4-hour timer expires → resolution per Phase 7 rules. Real players will occasionally want a fourth — cancel by mutual agreement. Common scenarios: opponent goes AFK before play, both realise the match was a misclick or miscommunication, one player has an emergency and the other is willing to bail.

The UX models Bybit's order-cancellation pattern: when one player requests cancellation, the other sees an inline accept/reject banner at the top of the match page. On accept the match transitions to `Cancelled`, both stakes are refunded via `Wallet::release`, and a system message in chat narrates the resolution.

### Decisions (pre-design)

- **Pending only.** Once a match flips to `Disputed` (game-API has been invoked) or `Settled` (money's moved), cancellation is off the table. From those states the dispute / settlement path is the only exit.
- **One open request at a time per match.** A second request before the first resolves is rejected at the controller with a toast: "There's already an open cancellation request."
- **30-minute cooldown after rejection.** If Bob rejects Alice's request, Alice can't re-request for 30 min. Prevents spam-cancel as a coercion tactic ("cancel or I'll keep asking until you give in").
- **Request expires with the match timeout.** If neither player responds within the existing 4h confirmation window, the regular timeout resolver runs (honored claim or game-API arbitration). The cancel request is a polite offer — it doesn't extend or interrupt the match's primary lifecycle.
- **Reason is optional, capped at 200 chars.** Short free-text so the other player understands the why.
- **System messages narrate the flow.** "Alice requested to cancel the match. [reason]" → "Bob accepted. Match cancelled, stakes refunded." or "Bob declined. Match continues."
- **No fee on cancellation.** Mirrors the draw outcome — both stakes released, no platform rake. Cancellation is a no-result, not a no-winner game.
- **Cancellation doesn't count toward player record.** Like a draw with no fee, but explicitly logged as "Cancelled" not "Drawn" — preserves the distinction for future statistics / reputation surfaces.

### Phases

**Phase 1 — Schema + state machine (~1-2 days)**

- [ ] Migration: add `cancelled_at`, `cancellation_requested_by` (FK to users), `cancellation_requested_at`, `cancellation_rejected_at`, `cancellation_reason` columns to `game_matches`. `cancellation_rejected_at` tracks the cooldown window for the requester.
- [ ] Enum: extend `MatchStatus` with `Cancelled`. Update the state-machine doc in M6 to reflect the new path.
- [ ] `GameMatchPolicy::requestCancellation` — participant-only, Pending only, no open request from same user, past cooldown if previously rejected.

**Phase 2 — Backend Actions (~1-2 days)**

- [ ] `RequestCancellationAction`: row-locked transaction, validates state + cooldown, writes pending columns, posts system message via `PostSystemMessageAction`. Returns a sentinel for the controller.
- [ ] `AcceptCancellationAction`: row-locked transaction, transitions status to `Cancelled`, calls `Wallet::release` for both players (idempotent via `cancel-refund-creator:{match_id}` / `cancel-refund-taker:{match_id}` references), posts system message. Same conservation invariant as `SettleDrawMatchAction`.
- [ ] `RejectCancellationAction`: clears pending columns, records `cancellation_rejected_at` for cooldown tracking, posts system message.
- [ ] Route: `POST /matches/{match}/cancellation` (request), `POST /matches/{match}/cancellation/accept`, `POST /matches/{match}/cancellation/reject`.

**Phase 3 — Frontend (~1-2 days)**

- [ ] "Request cancellation" button on the Pending action card (alongside Confirm and Open dispute).
- [ ] Modal asking for optional reason (textarea, 200-char cap mirroring backend).
- [ ] Inline banner at the top of `match/show.tsx` when there's an open request — shown to the OTHER player with Accept / Reject buttons. Requester sees a "Cancellation pending — waiting for {opponent}" version with no actions.
- [ ] Cancelled-status banner replaces the active-pending banner once the match transitions: "Match cancelled by mutual agreement. Both stakes refunded."
- [ ] Disable the "Request cancellation" button when in cooldown; show a tooltip with the cooldown expiry.

**Phase 4 — Tests + polish (~1 day)**

- [ ] Pest coverage: happy path (request → accept), reject path, cooldown enforcement, double-request rejection, status guards (no-cancel from Settled / Disputed / ManualReview), wallet refunds + ledger conservation, broadcast events fired.
- [ ] Wallet ledger conservation per cancelled match: `-A_stake + -B_stake + +A_release + +B_release = 0` (same as draw settlement).

### Not in M10

- **Unilateral cancellation** (one player cancels without consent). The only one-sided exit during Pending remains "open dispute" — the API decides.
- **Partial refund / negotiated split.** Cancellation refunds both stakes equally; players who want to split unequally should play it out or dispute.
- **Cancellation during Disputed or ManualReview.** Once the game API has been invoked, only the API or admin can resolve.
- **Cancellation by listing creator before take.** Already exists via `ListingController::cancel` — that's a separate path that operates on `Listing` (not `GameMatch`) and doesn't need a peer's agreement.

---

## M12 — Filament admin panel + chat-driven dispute resolution

Pulled forward from "pre-launch gate" because chat-first dispute resolution requires admin tooling. Without M12, M8's chat sits alongside the existing `MockGameApi` dispute path — useful but not the primary mechanism. M12 makes chat the source of truth for disputes.

### Phases

**Phase 1 — Install Filament + admin auth** (~2 days)

- [ ] `composer require filament/filament`. Filament admin lives at `/admin/*` (Livewire + Alpine + Filament's Tailwind config, separate from the Inertia + React user app — doesn't share Stakly's pink/purple design).
- [ ] Admin user role via Spatie permissions (Spatie already installed).
- [ ] First admin user seeded via dedicated seeder.

**Phase 2 — Match resolution panel** (~2-3 days)

- [ ] Filament resource for `GameMatch` with filters by status (Disputed / ManualReview).
- [ ] Resolution view: shows full chat history inline (text + screenshots + link cards including any API-verified evidence cards from M8 Phase 4), match metadata, both players' linked-account info.
- [ ] Three action buttons: "Settle to {creator}", "Settle to {taker}", "Draw — refund both." Each calls the existing `SettleMatchAction` / `SettleDrawMatchAction` (idempotent, status-guarded — Phase 7 of M6 made this safe).
- [ ] Audit log: every admin resolution writes a row to a new `match_admin_resolutions` table (admin user + action + reason text + timestamp).

**Phase 3 — Switch dispute resolver** (~1-2 days)

- [ ] `OpenDisputeAction` no longer dispatches `MockGameApi` resolution. Sets match to `Disputed` and waits for admin.
- [ ] `ResolveMatchTimeoutAction`: cases that would have gone to `MockGameApi` now go to `Disputed` and surface in admin queue.
- [ ] `MockGameApi` retained for the existing test suite (tests still call it via service binding); production binding switches to a null-driver that no-ops or to the real Lichess adapter once M14 lands.
- [ ] Migration of the conceptual model: `Disputed` becomes "waiting for admin or API," `ManualReview` becomes the truly-irrecoverable terminal state (locked, money frozen pending refund-or-payout decision).

### Not in M12

- Real-time admin notifications (email / push when new dispute opens) — Filament's default polling is fine to start.
- Bulk resolution actions — one match at a time.
- Auto-resolution from M8 Phase 4 verified cards (admin still clicks to confirm). M14 adds the auto-path.

---

## M13 — Chat anti-abuse + moderation

Chat is the highest-abuse-surface feature on the platform. M13 builds the policing layer. Lands after M12 so admin tools exist to review flags + bans.

### Phases

**Phase 1 — Off-platform deal detection** (~2-3 days)

- [ ] Regex flags in `SendMessageAction`: TRC20 wallet addresses (`T[1-9A-HJ-NP-Za-km-z]{33}`), ERC20 addresses (`0x[a-fA-F0-9]{40}`), BTC addresses, common payment-method names ("revolut", "paypal", "venmo", "cashapp"), messenger handles ("telegram @", "discord:", "wickr"), trade-coordination keywords ("send me", "outside stakly", "off platform").
- [ ] Flagged messages still post (we don't want to tip the abuser), but write to a `flagged_messages` table with the trigger pattern.
- [ ] Filament dashboard widget: recent flags, click-through to chat context.

**Phase 2 — Rate limits + report-user button** (~1-2 days)

- [ ] Per-user chat rate limit (10 messages / 10s, already in M8 Phase 2 — Phase 2 here adds the soft-warn UI: "You're sending messages quickly — pause a moment").
- [ ] Per-match-day cap (200 messages/day/user/match) — prevents flooding.
- [ ] Report-user button on each message: opens a Filament-routed report record with the message ID, reporter, reason.

**Phase 3 — Blocked words + admin moderation tools** (~2 days)

- [ ] Configurable blocked words list (slurs, harassment terms). Filtered server-side in `SendMessageAction` — message is replaced with a placeholder + flagged for admin.
- [ ] Admin moderation panel: list flagged + reported users, ban/mute tools, history of actions per user.
- [ ] Mute = can't send messages for N hours (configurable). Ban = account suspended (manual unban only).

---

## M14 — Automated outcome adapters

When dispute volume justifies automation, swap from "every dispute → admin reviews" to "supported-game disputes → API auto-resolves, falls through to admin only on Unknown."

Builds on the per-game verification clients from M8 (chess.com / Lichess) and M15 (FACEIT / OpenDota / Riot): each adapter is the same HTTP client the chat link-card enrichment uses, just invoked from `ResolveDisputeAction` instead of only from `SendMessageAction` link-paste detection. Result confidence maps to `MatchOutcome` (Won/Lost/Drawn) and `GameApiConfidence` (Confirmed → auto-settle, Drawn → auto-refund, Unknown → fall to admin).

Trigger for full M14: M12 admin path is in use and dispute volume justifies the engineering. Pull forward sooner if a class of disputes shows it'd be obviously easier to auto-resolve.

**Slice A — Chess card arbitration** ✅ shipped 2026-05-22 (pulled forward)

`App\Services\GameApi\ChessGameApi` implements `GameApi`. Reads the most-recent auto-fetched card (`source: 'auto_fetch'`) off the match's chat — provider-agnostic (handles both Lichess and chess.com via the card's `provider` field) — and returns the named winner with `Confirmed` confidence. Maps `winner_username` to a Stakly `user_id` via snapshotted handles (case-insensitive). Falls through to `MockGameApi` for no card, race window (auto-fetch hasn't completed), paste-only cards, or unmappable winners (defensive).

`AppServiceProvider::bindGameApi` registers `MockGameApi` as its own concrete + binds `GameApi::class` to `ChessGameApi` (wrapping `MockGameApi` as fallback). `config/stakly.php` default driver: `'chess'`. `tests/Pest.php` `mockGameApi()` helper resolves `MockGameApi::class` directly so existing tests using `forceWinner()` keep working through the wrapper's fallback path.

**Pulled forward from M14 proper because** the mock arbitration was paying the wrong player when a Lichess card showed a different winner — a visible "the system is broken" symptom every time a dispute hit during dev. Originally landed Lichess-only as `LichessGameApi`; Phase 4b extended to chess.com cards and renamed to `ChessGameApi`. Full M14 (FACEIT, OpenDota, Riot, plus no-admin-fallback policy) still lives behind the original trigger: M12 admin path in use + dispute volume signal.

---

## M15 — Multi-game expansion

The multi-game realization of M8's per-provider adapter pattern. Where M8 builds chess (chess.com + Lichess), M15 brings every other game Stakly eventually supports — each game arriving as its own adapter following the same shape. Phases intentionally left open; scope and ordering to be decided with the lead dev before any work starts.

The trust pitch this milestone earns: **Stakly only takes stakes on matches played on the strongest available anti-cheat platform for that game.** Per-game catalog:

| Game     | Played on (anti-cheat)                            | Verification API                                      |
| -------- | ------------------------------------------------- | ----------------------------------------------------- |
| Chess    | chess.com or Lichess (native fair-play detection) | chess.com / Lichess (shipped in M8)                   |
| CS2      | FACEIT (FACEIT AC required)                       | FACEIT Data API                                       |
| Dota 2   | FACEIT Hub or Steam ranked                        | FACEIT (if played there) or OpenDota (Steam matches)  |
| Valorant | Ranked Valorant (Vanguard required)               | Riot Games API                                        |
| LoL      | Ranked LoL (Vanguard rolling out)                 | Riot Games API                                        |

Games without a usable anti-cheat platform AND a verification API (Fortnite, Apex, COD, FIFA, fighting games, mobile games) are out of scope until either changes — not because they're impossible, but because the trust pitch doesn't hold for them.

**Architectural composition** — mostly the existing shapes, with one extension called out below:

- `LinkedAccountProvider` enum gains `Faceit`, `Riot`, possibly `Steam` (for the OpenDota / Steam-ranked Dota 2 path).
- New `ProfileClient` implementations: `FaceitProfileClient`, `RiotProfileClient` (likely split per region), `SteamProfileClient`. Bio-code paste flow per provider where the platform exposes an editable profile field; OAuth where available (FACEIT and Riot both expose it — cleaner UX, requires app approval).
- `Game` enum gains `Cs2`, `Dota2`, `Valorant`, `Lol`.
- `listings.platform` (M8 Phase 5) expands its allowed values to include the new platforms.
- New game-result clients (`FaceitGameClient`, `OpenDotaGameClient`, `RiotGameClient`) mirror M8's `LichessGameClient` / `ChessComGameClient` — first wired into chat link-card enrichment, later into the auto-resolver via M14.
- New arbitration drivers per game family (sibling to `ChessGameApi`): `FaceitGameApi`, `RiotGameApi`, etc. Each reads its own provider's cards from chat.
- Webhooks where the provider supports them (FACEIT match-completed, Riot match-end) reduce polling cost when M14 lands.

**`match_provider_snapshots` table — extending to non-chess identifiers**:

The sibling snapshot table that landed in M8 Phase 4 is already in place. Today each row is `(match_id, side, provider, username)` — sufficient for chess.com + Lichess. When CS2 / Dota 2 / Valorant land, each will need additional identifier columns: Steam ID (uint64 — likely `string(20)`), Faceit player ID (uuid), Riot ID region (varchar 4), maybe MMR at snapshot for sandbag-detection surfaces.

Two ways to extend:

1. **Add nullable columns per identifier** as each game adapter ships (`steam_id`, `faceit_id`, `riot_region`, `mmr_at_snapshot`, etc.). Type-safe, query-friendly, but the table accumulates per-game-specific columns. Probably fine — Stakly's planned game catalog tops out at ~6-7 providers, so the column count stays manageable.
2. **Add a `provider_data` JSONB column** alongside `username` to carry the variable-shape per-provider extras. Schema stays narrow; per-provider parsing happens in the adapter. Cost is no DB-level uniqueness on those extras.

Decide per-adapter when the first non-chess one ships. The current `username` column is sized 64 to cover Riot IDs (`name#tag`, 16+5 chars) and Steam vanity URLs (up to 32) without a length migration.

**Cross-cutting smurf / sandbag defense** — FACEIT-class anti-cheat solves "no aimbots in CS2" but does not solve "experienced player hides behind a fresh account." That second threat is mitigated by Stakly's verified-rating system: bio-code linked accounts carry rating history; listings can require a minimum rating (`listings.skill_min`, already in the schema). Both layers need to be in place for the trust pitch to actually hold.

### Not in M15

- Auto-resolution from those adapters — that's M14, gated on volume.
- Filament admin moderation surfaces for the new game types — covered by M12 once it lands.
- Marketing / homepage copy for the anti-cheat trust pitch — separate from engineering scope; revisit alongside the existing marquee-copy cleanup.
- Aggregator-as-a-service (PandaScore / Bayes / Abios) — considered and parked. Reconsider only if the per-game maintenance burden gets painful and revenue can absorb the monthly cost.

---

## M9 — Chain Integration

**Paused — pending crypto-payment-gateway specialist.**

Real on-chain TRC20 USDT deposits and withdrawals. Provider, custody model, key management, gas strategy, and architecture all TBD — to be designed with a specialist developer joining the project later. Resume when that person is on board, or sooner if the user decides to own this themselves.

The platform layers below are deliberately provider-agnostic and won't change when chain integration lands:

- **Internal ledger** (`wallet_transactions`, M3.5) — append-only, idempotent via `reference_id`, source of truth for `users.usdt_balance`. Whatever provider is picked, it will call `Wallet::deposit` on confirmed deposits and `Wallet::withdraw` from a queued withdrawal worker.
- **Wallet UI** (M7) — overview, deposit page (shows `MockTronAddress`), withdraw form (validates fully, short-circuits on submit). Real per-user addresses replace the mocks; the withdraw POST handler swaps the short-circuit for a real worker dispatch.
- **`App\Support\MockTronAddress`** — continues to generate placeholder addresses until integration lands.

### Why per-user deposit addresses, not a shared hot wallet

The first attribution question — "if every user deposits to one shared address, how do we tell who deposited?" — only has good answers on chains with a native memo / destination-tag field (XRP, XLM, BNB Beacon Chain, Cosmos). **TRC20 USDT has no memo field**: the smart contract's `transfer(to, amount)` carries no user metadata, and most wallets don't surface a memo input for TRC20 anyway. Attempting to attribute deposits via amount-and-timing heuristics breaks on the edge cases that matter most (two simultaneous deposits, identical amounts, retries) — not safe for a money platform.

The standard answer is **per-user deposit addresses via an HD (Hierarchical Deterministic) wallet** (BIP32 / BIP44). One master seed, derive a child address per user at path `m/44'/195'/0'/0/<user_index>` (195 = Tron's BIP44 coin type), publish that derived address as the user's deposit address, watch the chain for incoming USDT to it, credit on confirmation via `Wallet::deposit($user, $amount, reference: $tx_hash)`. Address = identity by construction, no memo needed. The master seed is the protected secret; every address recovers deterministically from it.

This drops cleanly into the existing schema. `users.tron_address` is already the per-user column; `App\Support\MockTronAddress` is the placeholder address generator that gets replaced with real HD derivation; `Wallet::deposit(..., reference: $tx_hash)` is already idempotent on the tx hash. Nothing in the ledger or wallet UI has to change — only the address-generation source and the watcher process.

The TRC20-specific catch is that sweeping USDT off a per-user address later requires TRX on that address to pay for the transaction. Standard fixes: pre-fund each address with a small TRX amount on creation, or stake TRX from a master account and delegate "Energy" to deposit addresses via Tron's resource-rental mechanism. This is the gas strategy the specialist owns.

### Provider spectrum (buy vs build)

Three real architectural choices for the chain-facing layer, ordered from most control to least:

- **DIY HD wallet + TronGrid for chain reads.** Stakly owns the master seed, derives addresses in its own code, uses TronGrid (Tron's official node API, free at moderate volume) to watch addresses + fetch transactions, sweeps with its own worker. Maximum control, maximum security responsibility. What centralized exchanges build internally. Moralis fills the same chain-data role as TronGrid if multi-chain ever becomes relevant later.
- **Managed wallet primitives (Tatum, BitGo, Fireblocks, Coinbase Developer Platform).** They expose HD-wallet primitives via API; Stakly still controls the master through them. Tatum is approachable for a smaller platform; Fireblocks is enterprise pricing. Middle ground on control vs operational load.
- **Payment gateway (CryptoCloud, NOWPayments, CoinPayments).** They handle wallets, addresses, sweeps, and gas; Stakly calls their API to "create a deposit address for user X" and they notify on incoming funds. Fastest to launch. Trade: they hold real money briefly during the deposit-to-settlement window, per-transaction fees recur, and vendor trust becomes part of the security model.

The choice interacts directly with the key-storage question: DIY means *Stakly* answers "where does the master seed live" (KMS / HSM / vault / encrypted env). Managed providers either hold the seed (vendor-custody) or use key-share schemes (MPC). Payment gateways take that question off the table entirely.

Note: TronGrid, Moralis, and CryptoCloud are not the same kind of thing despite sometimes appearing in the same sentence. TronGrid + Moralis are **chain-data readers** (you'd combine them with your own HD-wallet code). CryptoCloud is a **payment gateway** (it replaces the HD-wallet code with their API). Picking one doesn't preclude the other — they sit at different layers.

### Live questions for the specialist

**Provider tier** (DIY / managed primitives / payment gateway) → **custody model** (BYO-key vs vendor-MPC vs full vendor custody) → **key storage** (KMS / HSM / vault / vendor-held) → **TRC20 gas strategy** (pre-funded TRX per address vs delegated Energy via TRX-staked master vs vendor-handled) → **testnet shakedown plan** → **mainnet flip checklist**.

---

## Open questions before real money flows

Engineering can keep moving on everything else; these are the topics worth surfacing before Stakly accepts a real deposit. Legal/jurisdiction is user-owned and out of engineering scope, but flagged here for completeness.

1. **Custody model** — TBD with the chain specialist (M9). Internal ledger is provider-agnostic so this decision doesn't gate ledger work.
2. **Jurisdiction** — where Stakly is registered + license path. User-owned.
3. **Chain + provider** — TRC20 (Tron USDT) is the planned starting chain; provider TBD with specialist.
4. **Key storage** — depends on custody model.
5. **Incident response plan** — hot-wallet compromise procedure, user notification template, insurance.
6. **Terms of Service + dispute resolution policy.** User-owned.
7. **KYC/AML** — required? threshold? provider? User-owned.

> Stakly is **crypto-end-to-end** (USDT in, USDT out, USDT-denominated platform revenue). No fiat-banking touchpoint for users.

### Small follow-ups noticed in passing

Tiny cleanups noticed during earlier work. Worth doing before non-developers touch the platform — not blocking any current slice.

- **Platform user credentials.** Seeder currently creates `platform@stakly.internal` via the default `UserFactory` (`Hash::make('password')` + `email_verified_at = now()`). Tighten: override seeder to use `Hash::make(bin2hex(random_bytes(32)))` and set `email_verified_at = null`. Add `Fortify::authenticateUsing(...)` hook in `FortifyServiceProvider` that rejects `is_platform = true` users — defense in depth.
- **Marquee copy.** `resources/js/layouts/site-layout.tsx` `defaultMarqueeItems` currently has `STAKLY30 30% off` promo and other aspirational claims. Replace with honest copy before any user-facing surface.
