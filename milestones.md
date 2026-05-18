# Stakly Milestones

Frontend-first MVP. Build UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated.

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
- **M8** — Match Chat + Linked Accounts **← in progress** (Phases 1–2 ✅; Phases 3, 4, 4b, 5 next)
- **M10** — Mutual Match Cancellation
- **M12** — Filament admin panel + chat-driven dispute resolution
- **M13** — Chat anti-abuse + moderation
- **M14** — Automated outcome adapters (post-launch optimization)
- **M9** — Chain Integration [deferred — pending crypto-payment-gateway specialist]

> Only the active milestone keeps a detailed task list. Shipped milestones are one-paragraph summaries — the code is the source of truth for "how it works." Future milestones expand when started.

---

## Locked architectural decisions

These rules survive past their originating milestone and apply to all future work.

- **Money writes only through `App\Services\Wallet`** (M3.5). `users.usdt_balance` and `wallet_transactions` are written ONLY by Wallet service methods. The invariant `users.usdt_balance == SUM(wallet_transactions.amount)` is asserted in `WalletTest.php`. Direct writes from controllers / seeders / migrations / factories / tinker break this.
- **Money math is BCMath strings, never floats** (M3.5). Internal arithmetic at scale 6 via `bcadd` / `bcsub` / `bccomp`. Floats only appear at the API resource boundary.
- **Append-only ledger** (M3.5). No `updated_at` on `wallet_transactions`. Idempotency via optional `reference_id` — repeat calls return the existing row silently.
- **Actions pattern** (M11). Business logic lives in `app/Actions/<Domain>/<Verb><Noun>Action.php` with a `handle()` method, container-injected. Controllers and commands are thin adapters. `App\Services\Wallet` and `App\Services\GameApi\*` stay as primitives, not Actions. Decompose long `handle()` bodies into private helpers so `handle()` reads like a recipe of high-level steps.
- **`MatchStatus` state machine guard** (M6 Phase 7). `SettleMatchAction` / `SettleDrawMatchAction` no-op on `Settled` (idempotency), proceed on `Pending` / `Disputed`, throw on `ManualReview` or unknown. Future admin tools resolving `ManualReview` must use a different path.
- **`ManualReview` is admin-resolved out-of-band**, not in player chat (M6 Phase 7). Admin reads via Filament dashboard (M12); no admin-in-chat.
- **Snapshot, don't link**, when a relationship needs to survive identity changes. Linked-account usernames are denormalized onto `game_matches` at match creation so a mid-match unlink doesn't break dispute resolution (M8).
- **`is_platform = true` users are never user-facing** (M3.5 + M5). Filtered from profile show, wallet UI, listing pages.
- **No chain code, no smart contracts in v1** (project-wide). Custodial via internal Postgres ledger; chain integration is M9, deferred to a specialist.

---

## Shipped milestones

### M1 — Design Foundation + Homepage ✅

Stakly's dark + pink/purple gradient visual system shipped: design tokens, Bricolage + Inter fonts, gradient `Button` variant + `pill` size, `MarqueeStrip`, `SiteHeader`, `SiteFooter`, `SiteLayout`, `MobileMenu`. Homepage renders Hero (typography-only, no character art), `GameSelector` (chess + 8 "Soon" tiles), `HowItWorks`. **Locked**: dark-only design; Stakly-skin shadcn primitives at the source (`components/ui/<name>.tsx`), never per-usage; pink hover at `bg-primary/10`, focus ring at `ring-2 ring-primary/25`.

### M2 — Auth Flow ✅

Modal-only auth (`?auth=*` URL-driven), page-only destinations for reset/2FA/confirm, email verification via `MustVerifyEmail`, `ProfileMenu` + mobile account card, Inertia v3 flash + Stakly-styled Sonner toasts, custom Fortify response bindings (register / verify / resend / forgot-password / password-reset → `/?auth=login` with toast), reset-token guard, `ThrottleVerificationSend` (1/min). **42 tests / 166 assertions.**

### M2.5 — Pre-M3 polish ✅

Settings rendered inside `SiteLayout` with inline pill-tabs sub-nav (Profile / Security / Appearance). Starter-kit shell (`AppLayout` / `AppShell` / `AppSidebar` / `Breadcrumbs` etc.) deleted. `AuthModalProvider` no longer flashes the modal at logged-in users + strips stale `?auth=*` query.

### M3 — Listings Index ✅

Public marketplace `/listings`: filterable / sortable / paginated grid (12/page, server-controlled). Featured strip on `/` shows top 4 ending-soon. PII-safe `ListingResource`. Bybit-inspired filter bar + popover/sheet via `useIsMobile()`. Smart-ellipsis pagination with `Skeleton` loading rows. Game + currency registries in `config/`. **69 tests / 434 assertions.** **Locked**: project-wide URL contract via Spatie query-builder (`?filter[stake_max]=100&sort=ending_soon&page=2`); `$redirect = '/listings'` for graceful share-link UX; `lib/listings-query.ts` centralizes URL building.

### M3.5 — Wallet / Ledger Foundation ✅

Append-only Postgres ledger (`wallet_transactions`) is the source of truth for every USDT balance change. `App\Services\Wallet` exposes 6 methods (`deposit`, `withdraw`, `hold`, `release`, `payout`, `fee`) plus `balanceFor`, all funnelling through a private `record()` that wraps `DB::transaction(...)` + `lockForUpdate()` on the user row. Idempotency via optional `reference_id`. BCMath strings throughout (scale 6, matching Tron USDT precision). Platform rake credits the seeded `is_platform = true` user. **86 tests / 493 assertions (17 wallet-specific).**

### M4 — Listing Detail + Create Flow ✅

First end-to-end money flow. Public listing detail (`/listings/{id}`), auth-gated create form, owner-only cancel — wired to real `Wallet::hold` on create, `Wallet::release` on cancel, both transactional + idempotent. Multi-select `time_control` and `language` (jsonb + `AsEnumCollection` + `whereJsonContains`). Owner-only `App\Policies\ListingPolicy::cancel`. **110 tests / 655 assertions.** **Locked**: duration dropdown (not datetime picker); insufficient-balance validated twice (request + `Wallet::hold` exception); detail page renders for any status (no 404 on stale share links); stake precision pinned at `decimal:0,2`; hybrid build order (backend skeleton → frontend → backend hardening → tests).

### M5 — User Profile ✅

Public read-only profiles at `/users/{username}`. `username` + `bio` columns, `UserController` + `UserProfileResource` (whitelist, no PII), 5 profile components. Auto-generated usernames via `Str::slug($name)` + collision-safe suffix loop. Reserved-username list (`'user'` reserved). Strict ASCII Latin name validation. Profile entry points via two interior `<Link>`s on each listing row/card. **147 tests / 822 assertions.** **Locked**: `username` derived at registration, immutable in v1; `is_platform = true` users 404 on profile show.

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
Match.Disputed --[game-API returns winner]--> Match.Settled
Match.Disputed --[game-API can't determine]--> Match.ManualReview (terminal — admin resolves out-of-band)
```

Phases 1–7 shipped: schema + policies, take + match creation, confirm UI + settlement + Inertia polling, mock game-API dispute path, listings/profile/wallet integration, listings management + Active Mode + scoped Player Hub sidebar, `Drawn` outcome, timeout resolver. **366 tests / 1969 assertions.**

**Locked**: 10% platform fee (`config/stakly.php` `platform_fee_rate`); 4h confirmation timeout; 1:1 listing→match; participant-only match visibility; single-confirmer rule honors the claim (Won → confirmer wins, Lost → opponent wins, Drawn → game-API arbitrates); platform does NOT pocket stakes on no-show; `MatchSettlement` service deleted in M11 — settlement lives in `app/Actions/GameMatch/`.

### M7 — Wallet UI ✅

Four pages: `/wallet` (hero balance + 3 action cards + recent activity), `/wallet/deposit` (TRC20 mock address + QR + network warning), `/wallet/withdraw` (validating form, short-circuited POST), `/wallet/history` (filter chips + paginated rows). `BalanceChip` in `SiteHeader` desktop + inline balance in `MobileMenu`. `users.tron_address` (varchar 34 unique) generated at registration via `App\Support\MockTronAddress`. Semantic transaction colors. **193 tests / 1039 assertions.** **Locked**: multi-page (not tabbed); spendable balance only in UI (held derivable from ledger); mock TRC20 addresses until M9; withdrawal short-circuits with launch-gated toast (no ledger write); `abort_if($user->is_platform, 403)` on every wallet method.

### M11 — Controller Refactor to Actions Pattern ✅ (shipped 2026-05-17)

Business logic moved from controllers + commands into `app/Actions/<Domain>/` classes. `app/Actions/Listing/`: `Create`, `Cancel`, `Expire`. `app/Actions/GameMatch/`: `TakeListing`, `ConfirmOutcome`, `OpenDispute`, `SettleMatch`, `SettleDrawMatch`, `ResolveDispute`, `ResolveMatchTimeout`. Controllers + artisan commands shrink to thin HTTP/CLI adapters with method-injection. Old `App\Services\MatchSettlement` deleted. CLAUDE.md gained an "Actions pattern" subsection. **All 366 / 1969 tests still pass.** **Locked**: plain PHP classes, no package, no Repositories; `handle()` method; method-injection; Wallet + GameApi stay as primitives; pure queries (read-only index/show) stay in controllers.

---

## M8 — Match Chat + Linked Accounts **← in progress**

The architectural keystone for multi-game support. Stakly is multi-game by vision (chess now, Dota 2 / CS2 / others later). API-only outcome verification locks us to games with good APIs. **Chat with structured dispute evidence works universally** — admin reads chat + uploaded evidence and decides, with API verification appearing as an *enriched evidence card* when a player pastes a supported game URL. Linked accounts power both the chat enrichment (M8 Phase 4) and the eventual full automated adapters (M14).

The API is no longer the primary truth source — it's a smart link-previewer inside chat. Players coordinate and resolve via chat; admin moderates; API verification is an *accelerator*, not a *requirement*.

### Key parameters (defaults — adjust before relevant phase)

- **Real-time**: Laravel Reverb (free, self-hosted, Redis-backed). Pusher swap is a `.env` change later if needed.
- **Bio-code TTL**: 15 min. **Verify rate limit**: 6/min per user.
- **Chat retention**: forever (auditable for disputes). Indexed by `match_id`.
- **File upload**: 5MB max, image-only (screenshots). Videos hosted externally + posted as links.
- **Chat message rate limit**: 10 messages / 10s per user.
- **Per-game API capability**: indicator on match page ("Outcome can be auto-verified via Lichess" vs "Manual review only").

### Phases

> Estimates are focused solo dev time, not calendar time. Each phase ships something usable; commit per phase.

**Phase 1 — Linked accounts foundation (chess.com + Lichess)** ✅ shipped 2026-05-18

- [x] Schema migration on `users`: `chess_com_username`, `chess_com_verified_at`, `lichess_username`, `lichess_verified_at`, `pending_verification_provider`, `pending_verification_username`, `pending_verification_code`, `pending_verification_expires_at`.
- [x] Action: `RequestLinkVerificationAction` — generates 16-char random code, stores pending columns, returns code for UI display.
- [x] Action: `VerifyLinkedAccountAction` — calls provider profile API, parses target field, matches code, marks `*_verified_at = now()`, clears pending columns.
- [x] Services: `App\Services\Provider\ChessComProfileClient` + `LichessProfileClient` (`Http::fake()`-able). chess.com requires User-Agent header with contact email.
- [x] New settings tab `/settings/linked-accounts` (alongside Profile / Security / Appearance).
- [x] Profile section `LinkedAccountsSection` binds real data (username + verified badge); existing "Not linked" placeholder is replaced.
- [x] Throttle middleware on verify endpoint (`throttle:6,1`).
- [x] Tests with `Http::fake()` for both providers (happy path, expired code, mismatched code, API 404, API 500, rate limit). 36 tests / 402 total / 2075 assertions.

**Locked decisions** (Phase 1):
- **Bio-code target field**: chess.com `location` (public, free-text, rarely set by default — chess.com's public JSON exposes it). Lichess `profile.bio` (proper 400-char bio field). Switched from the original `name` (display name) plan during implementation: `name` is the player's identity in lobbies and live games, so clobbering it for a 15-min verification window is needlessly disruptive. `location` is equally verifiable via the public API but invisible-by-default to anyone not viewing the profile page. Users see "paste this code in your Location on chess.com" / "paste this code in your bio on Lichess."
- **Both providers from Phase 1**. The shared flow + symmetric UI cost almost nothing to add the second provider. Players who play only on one platform aren't locked out.
- **Verification is immutable until unlinked**. Re-verifying isn't required unless the user unlinks and relinks.

**Phase 2 — Reverb infrastructure + chat schema + text chat** ✅ shipped 2026-05-18

- [x] Add `reverb` service to `compose.yaml` (port 8080). Plus a `queue` service running `php artisan queue:listen` — `ShouldBroadcast` events route through the queue, without a worker broadcasts stall in the `jobs` table.
- [x] Composer: `laravel/reverb`. NPM: `@laravel/echo-react` + `pusher-js` (React hooks layer rather than plain `laravel-echo` — `useEcho` handles cleanup on unmount).
- [x] Schema: `messages` table with composite `(match_id, id)` index, `attachments_json` jsonb column reserved for Phase 3/4, immutable (no `updated_at`).
- [x] Model: `App\Models\Message` + factory (`->system()` state) + `match` / `user` relations. `UPDATED_AT = null`.
- [x] Action: `App\Actions\Message\SendMessageAction` — rate limit (10/10s via `RateLimiter`), status gate (Settled/ManualReview reject, Disputed allows as evidence record), content trim + 2000-char cap, persist + dispatch.
- [x] Event: `App\Events\MessageSent` — `ShouldBroadcast` + `ShouldDispatchAfterCommit` (Laravel 13 split — no single `ShouldBroadcastAfterCommit` interface exists), `broadcastAs(): 'message.sent'` for a stable event name decoupled from PHP class path. Broadcasts to `match.{id}` private channel.
- [x] Channel auth: extracted to `App\Broadcasting\MatchChannel::join(User, int): bool` (named class rather than inline closure) so the auth callback is directly unit-testable without going through `/broadcasting/auth` HTTP (test broadcasting connection is `null` which doesn't run callbacks).
- [x] React: `MatchChatPanel` as a right-side panel on `match/show.tsx` — sticky at `top-28` (clears the marquee at `top-16` + ~46px), fixed `h-[600px]` compact height, internal message-list scroll. Mobile: bottom-sheet via `MobileChatTrigger` with floating "Chat" button + unread count. Echo subscription via `useMatchChat` hook called once in the page (single subscription serves both desktop + mobile renders).
- [x] Read-only state when Settled / ManualReview: chat input replaced by "This match is settled — chat is read-only." footer.
- [x] Tests: 22 in `SendMessageTest` (auth gates, validation, rate limit, status gate, broadcast assertion, `MatchChannel::join` direct callback tests) + 14 in `SystemMessageTest`.

**Lifecycle system messages** (shipped same slice — narrate state changes inline with chat):

- [x] `App\Actions\Message\PostSystemMessageAction` — `type = system`, `user_id = null`, hard-coded so no HTTP path can produce one (un-impersonatable). Broadcasts via the same `MessageSent` event.
- [x] Wired into 7 lifecycle Actions: `TakeListingAction` ("Match started"), `ConfirmOutcomeAction` ("Alice confirmed: Won"), `SettleMatchAction` ("Match settled. Alice wins $X"), `SettleDrawMatchAction` ("Match ended as a draw"), `OpenDisputeAction` ("Dispute opened by Alice"), `ResolveDisputeAction` Unknown branch ("Game API could not determine — admin review"), `ResolveMatchTimeoutAction` ("4-hour confirmation window expired").
- [x] `SystemBubble` component visual: megaphone icon + muted background + centered, distinct from player message bubbles.

**Match page redesign** (shipped same slice):

- [x] `MatchInfoCard` (Bybit-style key:value list) replaces the old separate "Your opponent" + "Match details" cards. Rows: Opponent (clickable to profile, small avatar) → Stake (each) → Pot → Winner payout (gradient-accent, gameplay only — hidden when Settled since SettlementSummary already breaks it down) → Time control. Dropped redundant Pot duplication between settlement and details cards.
- [x] `MatchTimestamps` — subtle metadata strip under the page subtitle showing absolute timestamps ("Started May 18, 2026, 09:12 PM · Finished May 18, 2026, 09:13 PM"). Locale-aware via `toLocaleString`.
- [x] `MatchFaq` — 6 Stakly-specific Q&As via shadcn `Accordion` (Stakly-skinned at the source: dropped upstream `hover:underline` + `ring-[3px]`, swapped to text-color hover + `ring-2 ring-primary/25`, added `cursor-pointer`).
- [x] `SettlementSummary` enriched: opponent's `@handle` shown inline next to name in loser-view subtitle.

**Locked decisions** (Phase 2):

- **Reverb over Pusher**: free, Laravel-team built, Redis-backed, `.env` swap to Pusher possible if we hit scale issues.
- **Private channel scope**: only the two match participants subscribe. Admin reads via Filament dashboard (M12), not via channel subscription.
- **Queue worker is infrastructure, not optional**. `ShouldBroadcast` events are queued by default. With `QUEUE_CONNECTION=database` (production posture) and no worker, broadcasts stall in the `jobs` table and never reach Reverb. Solution: a dedicated `queue` service in `compose.yaml` running `php artisan queue:listen --tries=1 --timeout=0`. Same pattern as the `reverb` service — always running, no manual `composer run dev` orchestration needed.
- **Chat locks after settlement** (decided 2026-05-18). Once status is `Settled` (winner OR draw refund) OR `ManualReview`, chat becomes read-only. Reasoning: post-resolution messages add abuse surface (evidence pollution by losers, harassment) without product value. Audit trail stays viewable. `Pending` / `Disputed` keep chat open. Frontend hides input; backend `SendMessageAction` rejects with 422.
- **Right-side panel layout** (decided 2026-05-18). Compact column (`h-[600px]`, sticky `top-28`) to the right of match details on desktop, bottom-sheet on mobile. Match info stays the primary surface; chat is co-visible but not dominant.
- **Messages are immutable**: no edit, no delete. Dispute review depends on truthful logs.
- **System message type**: produced via `PostSystemMessageAction`, never the HTTP path. `user_id = null`, `type = system`. Cannot be impersonated.
- **Content cap = 2000 chars**. Enforced in `SendMessageAction`. Covers regular chat + Phase 5 paste-PGN evidence (~1.5-1.8 KB for a 40-move Lichess export). DB column is `text` (no Postgres-level cap); the Action is the single enforcement point.
- **Send semantics**: Enter sends, Shift+Enter inserts newline.
- **Direct callback testing for channel auth**. Named class (`MatchChannel`) over inline closure so tests can invoke `->join()` directly. Avoids the test-env-only no-op of the `null` broadcaster.

**Test count after Phase 2**: 442 tests / 2186 assertions (up from 406 / 2090).

**Phase 3 — File uploads + plain link cards** (~2-3 days)

- [ ] Storage: local disk for dev (`storage/app/public/match-attachments`) with S3-ready abstraction via Laravel's filesystem driver.
- [ ] Upload route: `POST /matches/{match}/messages/attachment`, validates image-only (`mimetypes:image/jpeg,image/png,image/webp`), max 5MB.
- [ ] Link detection: regex in `SendMessageAction` finds URLs in message content, dispatches queued `FetchLinkMetadataJob`.
- [ ] `FetchLinkMetadataJob`: fetches Open Graph `<title>` + `<image>` + canonical URL, caches result (1h TTL), updates message's `attachments_json`.
- [ ] React: file picker in chat input; image render with lightbox in message list; link cards with OG preview.

**Locked decisions** (Phase 3):
- **Image-only uploads**: PDFs, videos, generic files are rejected. Screenshots are the only file type we want for v1. Videos hosted externally and posted as links.
- **5MB cap**: balances screenshot quality vs storage/bandwidth.
- **OG fetch is queued**: don't block chat send waiting for `<title>` of pasted URL; render plain link card immediately, swap to enriched card when fetch completes.

**Phase 4 — Smart link enrichment for Lichess** (~2-3 days)

The big payoff of having linked accounts: Lichess game URLs in chat become trusted evidence cards.

- [ ] Schema additions: `creator_provider_username` + `taker_provider_username` on `game_matches` (snapshot at match creation — see "snapshot don't link" locked decision).
- [ ] Update `TakeListingAction`: populate snapshot from `match.taker->lichess_username` + `match.listing.user->lichess_username` if linked (mirror for chess.com when Phase 4b lands).
- [ ] URL pattern detection in `SendMessageAction`: Lichess game URLs (`lichess.org/{8-char-id}` and longer-form export URLs).
- [ ] Service: `LichessGameClient` calls `GET /api/game/{gameId}` (returns JSON with player usernames + result).
- [ ] Cross-check: fetched game's player usernames must match the match's snapshot columns (case-insensitive). If they match → "verified" card. If they don't match → plain link card with subtle "could not verify" hint visible only to the pasting user.
- [ ] React: enriched card component shows winner + time control + game ID + "Verified via Lichess" green check.
- [ ] Tests: verified happy path, mismatched usernames, game not found, API error.

**Locked decisions** (Phase 4):
- **Lichess first**. Lichess has direct game-by-ID lookup (`GET /api/game/{id}`); chess.com requires archive paging + eventual-consistency retries. Building Lichess first shakes out the architecture on the easier API. chess.com enrichment follows in Phase 4b.
- **Cross-check usernames against snapshot, not live link**. Even if a player unlinks mid-match, snapshot survives. Prevents "unlink to escape match" abuse.
- **Verification is binary**: verified ✓ or not. We don't try to handle "verified but with caveat" in v1 — that's for chat-mediated discussion with admin.

**Phase 4b — Smart link enrichment for chess.com** (~3-4 days, follow-up to Phase 4)

- [ ] Service: `ChessComGameClient`. Strategy: parse chess.com URL for game ID, fetch the player's monthly archive (`GET /pub/player/{username}/games/{YYYY}/{MM}`), filter for matching game ID. Query current month + previous month to handle midnight UTC boundary.
- [ ] Eventual consistency: 3-retry queued job with backoff (5s / 15s / 45s) for games not yet in archive.
- [ ] User-Agent header per chess.com guidelines (contact email).
- [ ] Archive response is cached aggressively to avoid double-fetching during retries.
- [ ] Cross-check logic mirrors Phase 4 (snapshot column comparison).

**Phase 5 — Listing platform binding + capability badge + dispute evidence prompt** (~2-3 days)

- [ ] Schema: `platform` column on `listings` (enum: `chess_com` / `lichess`, default `chess_com` for existing rows). Create form picker (visible only if user has linked accounts on multiple platforms).
- [ ] Take-gate: `TakeListingAction` validates `$taker->{platform}_verified_at !== null` (must have linked + verified the relevant platform to take). `StoreListingRequest` validates creator likewise.
- [ ] Match page indicator: "Outcome can be auto-verified via Lichess" or "Auto-verification via chess.com coming soon" or "Manual review only" depending on game + listing platform.
- [ ] On dispute open, `OpenDisputeAction` posts a system message in chat: "Dispute opened by {user}. Submit evidence — screenshot, game URL, or PGN. An admin will review."
- [ ] React: system message variant (visually distinct, no user attribution).
- [ ] `/listings` filter chip: filter by platform (chess.com / Lichess).

**Locked decisions** (Phase 5):
- **Linking is required to create or take listings.** Players without a verified account can't participate. This is a real UX gate — but without it, dispute resolution is impossible (admin has nothing to cross-check).
- **Dispute evidence prompt is non-blocking**: players can dispute without submitting evidence — chat itself is the evidence record. The prompt nudges, doesn't gate.
- **Platform column default `chess_com`**: existing seeded listings stay valid. New listings pick at creation.

### Out of scope for M8

- **Filament admin panel** — M12. Until M12 ships, disputes still resolve via the existing `MockGameApi` path. Chat is *additive* in M8, not replacing dispute resolution yet.
- **chess.com smart link enrichment** — Phase 4b (post-Phase 4 follow-up; lands inside M8 or rolls to M12 depending on Lichess velocity).
- **Auto-resolution without admin**: even with a verified evidence card, M12 admin clicks to confirm. M14 adds the auto-path when adapters are mature at scale.
- **Chat anti-abuse** (off-platform deal detection, rate limits beyond basic, report-user, blocked words) — M13.
- **Voice / video chat** — v2 if ever.
- **Read receipts, typing indicators, message reactions, edit/delete, mentions, DMs** — v2.

---

## M10 — Mutual Match Cancellation

The fourth resolution path for a Pending match. Today a match has three exits: both players confirm an outcome → `Settled`; either opens a dispute → `Disputed`; the 4-hour timer expires → resolution per Phase 7 rules. Real players will occasionally want a fourth — cancel by mutual agreement. Common scenarios: opponent goes AFK before play, both realise the match was a misclick or miscommunication, one player has an emergency and the other is willing to bail.

The UX models Bybit's order-cancellation pattern: when one player requests cancellation, the other sees an inline accept/reject banner at the top of the match page. On accept the match transitions to `Cancelled`, both stakes are refunded via `Wallet::release`, and a system message in chat narrates the resolution.

### Locked decisions (pre-design)

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

### Out of scope

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

### Out of scope for M12

- Real-time admin notifications (email / push when new dispute opens) — Filament's default polling is fine for launch.
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

## M14 — Automated outcome adapters (post-launch optimization)

When dispute volume justifies automation, swap from "every dispute → admin reviews" to "supported-game disputes → API auto-resolves, falls through to admin only on Unknown."

Builds on M8 Phase 4 / 4b (Lichess + chess.com link clients): the adapter is the same HTTP client, just invoked from `ResolveDisputeAction` instead of only from `SendMessageAction` link-paste detection. Result confidence maps to `MatchOutcome` (Won/Lost/Drawn) and `GameApiConfidence` (Confirmed → auto-settle, Drawn → auto-refund, Unknown → fall to admin).

Order: Lichess adapter (extends `LichessGameClient` from M8 Phase 4), then chess.com (extends `ChessComGameClient` from M8 Phase 4b), then Dota 2 OpenDota (when Dota 2 listings are real).

Trigger: deferred until M12 admin path is at scale and the volume justifies automation. Likely post-launch.

---

## M9 — Chain Integration

**Deferred — pending crypto-payment-gateway specialist.**

Real on-chain TRC20 USDT deposits and withdrawals. Provider, custody model, key management, gas strategy, and architecture all TBD — to be designed with a specialist developer joining the project later.

The platform layers below are deliberately provider-agnostic and won't change when chain integration lands:

- **Internal ledger** (`wallet_transactions`, M3.5) — append-only, idempotent via `reference_id`, source of truth for `users.usdt_balance`. Whatever provider is picked, it will call `Wallet::deposit` on confirmed deposits and `Wallet::withdraw` from a queued withdrawal worker.
- **Wallet UI** (M7) — overview, deposit page (shows `MockTronAddress`), withdraw form (validates fully, short-circuits on submit). Real per-user addresses replace the mocks; the withdraw POST handler swaps the short-circuit for a real worker dispatch.
- **`App\Support\MockTronAddress`** — continues to generate placeholder addresses until integration lands.

Live questions for the specialist: **provider** (Tatum / Fireblocks / BitGo / Coinbase Developer Platform / DIY) → **custody model** (BYO-key vs vendor-managed) → **key storage** (env / AWS Secrets Manager / KMS / vendor-held) → **TRC20 gas strategy** (sweep-on-deposit + staked TRX vs alternatives) → **testnet shakedown plan** → **mainnet flip checklist**.

---

## Pre-launch gate — Custody + Jurisdiction (BLOCKER)

Real on-chain integration is gated by these blockers. **Do not proceed without explicit go-ahead.** Once Stakly accepts a single real deposit, it's operating a regulated money-handling business and the engineering becomes hard to unwind.

Required answers before mainnet wiring:
1. **Custody model committed** — TBD with M9 specialist. Internal ledger is provider-agnostic.
2. **Jurisdiction committed** — where Stakly is registered + license path (Curaçao / Malta / US state-by-state / testnet-only).
3. **Chain + provider committed** — TRC20 (Tron USDT) chain remains v1. Provider TBD with specialist.
4. **Key storage in production** — depends on custody model decision.
5. **Incident response plan** — hot-wallet compromise procedure, user notification template, insurance.
6. **Terms of Service + dispute resolution policy** drafted.
7. **KYC/AML** — required? threshold? provider?

> Stakly is **crypto-end-to-end** (USDT in, USDT out, USDT-denominated platform revenue). No fiat-banking touchpoint for users.

### App-level hardening (deferred from dev)

Small code-level cleanups noticed during M3–M4 development. Not blocking until first non-developer touches the platform.

- **Platform user credentials.** Seeder currently creates `platform@stakly.internal` via the default `UserFactory` (`Hash::make('password')` + `email_verified_at = now()`). Pre-launch: override seeder to use `Hash::make(bin2hex(random_bytes(32)))` and set `email_verified_at = null`. Add `Fortify::authenticateUsing(...)` hook in `FortifyServiceProvider` that rejects `is_platform = true` users — defense in depth.
- **Marquee copy.** `resources/js/layouts/site-layout.tsx` `defaultMarqueeItems` currently has `STAKLY30 30% off` promo and other aspirational claims. Replace with honest copy before any user-facing surface.
