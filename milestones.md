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
- **M8** — Match Chat + Linked Accounts **← in progress (started 2026-05-18)**
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

**Phase 1 — Linked accounts foundation (chess.com + Lichess)** (~3-4 days)

- Schema migration on `users`: `chess_com_username`, `chess_com_verified_at`, `lichess_username`, `lichess_verified_at`, `pending_verification_provider`, `pending_verification_username`, `pending_verification_code`, `pending_verification_expires_at`.
- Action: `RequestLinkVerificationAction` — generates 16-char random code, stores pending columns, returns code for UI display.
- Action: `VerifyLinkedAccountAction` — calls provider profile API, parses target field, matches code, marks `*_verified_at = now()`, clears pending columns.
- Services: `App\Services\Provider\ChessComProfileClient` + `LichessProfileClient` (`Http::fake()`-able). chess.com requires User-Agent header with contact email.
- New settings tab `/settings/linked-accounts` (alongside Profile / Security / Appearance).
- Profile section `LinkedAccountsSection` binds real data (username + verified badge); existing "Not linked" placeholder is replaced.
- Throttle middleware on verify endpoint (`throttle:6,1`).
- Tests with `Http::fake()` for both providers (happy path, expired code, mismatched code, API 404, API 500, rate limit).

**Locked decisions** (Phase 1):
- **Bio-code target field**: chess.com `name` (public display name — chess.com's public JSON exposes this; their "About" bio is NOT in the public API). Lichess `profile.bio` (proper 400-char bio field). Users see "paste this code in your display name on chess.com" / "paste this code in your bio on Lichess." The chess.com clobber UX is acceptable since it's a one-time-per-provider action.
- **Both providers from Phase 1**. The shared flow + symmetric UI cost almost nothing to add the second provider. Players who play only on one platform aren't locked out.
- **Verification is immutable until unlinked**. Re-verifying isn't required unless the user unlinks and relinks.

**Phase 2 — Reverb infrastructure + chat schema + text chat** (~3-4 days)

- Add `reverb` service to `compose.yaml` (port 8080).
- Composer: `laravel/reverb` + `@laravel/echo` + `pusher-js` client.
- Schema: `messages` table (id, match_id FK, user_id FK nullable, type [text/image/link/system], content text, attachments_json, created_at), indexed by `match_id`.
- Model: `App\Models\Message` + factory + `match` / `user` relations. `UPDATED_AT = null` (immutable — keep dispute logs honest).
- Action: `SendMessageAction` — validates participant (via existing `GameMatchPolicy`), validates rate limit, persists, broadcasts `MessageSent` event.
- Event: `MessageSent` broadcasts to `match.{id}` **private** channel.
- Channel auth in `routes/channels.php`: only the two match participants subscribe.
- React: `MatchChatPanel` component in match `show.tsx` (message list + input).
- Tests: rate limit hit, channel auth (non-participant rejected), persistence, broadcast event fired.

**Locked decisions** (Phase 2):
- **Reverb over Pusher**: free, Laravel-team built, Redis-backed (we have Redis), `.env` swap to Pusher possible if we hit scale issues.
- **Private channel scope**: only the two match participants subscribe. Admin reads via Filament dashboard (M12), not via channel subscription.
- **Chat stays open after settle**: GG wishes, rematch suggestions are common. Cheap to keep open. Stops only if match is in `ManualReview` (admin can lock if abuse).
- **Messages are immutable**: no edit, no delete. Dispute review depends on truthful logs.
- **System message type**: posted by `SendMessageAction` with `user_id = null` and `type = system`. Cannot be impersonated. Used in Phase 5 for dispute prompts.

**Phase 3 — File uploads + plain link cards** (~2-3 days)

- Storage: local disk for dev (`storage/app/public/match-attachments`) with S3-ready abstraction via Laravel's filesystem driver.
- Upload route: `POST /matches/{match}/messages/attachment`, validates image-only (`mimetypes:image/jpeg,image/png,image/webp`), max 5MB.
- Link detection: regex in `SendMessageAction` finds URLs in message content, dispatches queued `FetchLinkMetadataJob`.
- `FetchLinkMetadataJob`: fetches Open Graph `<title>` + `<image>` + canonical URL, caches result (1h TTL), updates message's `attachments_json`.
- React: file picker in chat input; image render with lightbox in message list; link cards with OG preview.

**Locked decisions** (Phase 3):
- **Image-only uploads**: PDFs, videos, generic files are rejected. Screenshots are the only file type we want for v1. Videos hosted externally and posted as links.
- **5MB cap**: balances screenshot quality vs storage/bandwidth.
- **OG fetch is queued**: don't block chat send waiting for `<title>` of pasted URL; render plain link card immediately, swap to enriched card when fetch completes.

**Phase 4 — Smart link enrichment for Lichess** (~2-3 days)

The big payoff of having linked accounts: Lichess game URLs in chat become trusted evidence cards.

- Schema additions: `creator_provider_username` + `taker_provider_username` on `game_matches` (snapshot at match creation — see "snapshot don't link" locked decision).
- Update `TakeListingAction`: populate snapshot from `match.taker->lichess_username` + `match.listing.user->lichess_username` if linked (mirror for chess.com when Phase 4b lands).
- URL pattern detection in `SendMessageAction`: Lichess game URLs (`lichess.org/{8-char-id}` and longer-form export URLs).
- Service: `LichessGameClient` calls `GET /api/game/{gameId}` (returns JSON with player usernames + result).
- Cross-check: fetched game's player usernames must match the match's snapshot columns (case-insensitive). If they match → "verified" card. If they don't match → plain link card with subtle "could not verify" hint visible only to the pasting user.
- React: enriched card component shows winner + time control + game ID + "Verified via Lichess" green check.
- Tests: verified happy path, mismatched usernames, game not found, API error.

**Locked decisions** (Phase 4):
- **Lichess first**. Lichess has direct game-by-ID lookup (`GET /api/game/{id}`); chess.com requires archive paging + eventual-consistency retries. Building Lichess first shakes out the architecture on the easier API. chess.com enrichment follows in Phase 4b.
- **Cross-check usernames against snapshot, not live link**. Even if a player unlinks mid-match, snapshot survives. Prevents "unlink to escape match" abuse.
- **Verification is binary**: verified ✓ or not. We don't try to handle "verified but with caveat" in v1 — that's for chat-mediated discussion with admin.

**Phase 4b — Smart link enrichment for chess.com** (~3-4 days, follow-up to Phase 4)

- Service: `ChessComGameClient`. Strategy: parse chess.com URL for game ID, fetch the player's monthly archive (`GET /pub/player/{username}/games/{YYYY}/{MM}`), filter for matching game ID. Query current month + previous month to handle midnight UTC boundary.
- Eventual consistency: 3-retry queued job with backoff (5s / 15s / 45s) for games not yet in archive.
- User-Agent header per chess.com guidelines (contact email).
- Archive response is cached aggressively to avoid double-fetching during retries.
- Cross-check logic mirrors Phase 4 (snapshot column comparison).

**Phase 5 — Listing platform binding + capability badge + dispute evidence prompt** (~2-3 days)

- Schema: `platform` column on `listings` (enum: `chess_com` / `lichess`, default `chess_com` for existing rows). Create form picker (visible only if user has linked accounts on multiple platforms).
- Take-gate: `TakeListingAction` validates `$taker->{platform}_verified_at !== null` (must have linked + verified the relevant platform to take). `StoreListingRequest` validates creator likewise.
- Match page indicator: "Outcome can be auto-verified via Lichess" or "Auto-verification via chess.com coming soon" or "Manual review only" depending on game + listing platform.
- On dispute open, `OpenDisputeAction` posts a system message in chat: "Dispute opened by {user}. Submit evidence — screenshot, game URL, or PGN. An admin will review."
- React: system message variant (visually distinct, no user attribution).
- `/listings` filter chip: filter by platform (chess.com / Lichess).

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

## M12 — Filament admin panel + chat-driven dispute resolution

Pulled forward from "pre-launch gate" because chat-first dispute resolution requires admin tooling. Without M12, M8's chat sits alongside the existing `MockGameApi` dispute path — useful but not the primary mechanism. M12 makes chat the source of truth for disputes.

### Phases

**Phase 1 — Install Filament + admin auth** (~2 days)

- `composer require filament/filament`. Filament admin lives at `/admin/*` (Livewire + Alpine + Filament's Tailwind config, separate from the Inertia + React user app — doesn't share Stakly's pink/purple design).
- Admin user role via Spatie permissions (Spatie already installed).
- First admin user seeded via dedicated seeder.

**Phase 2 — Match resolution panel** (~2-3 days)

- Filament resource for `GameMatch` with filters by status (Disputed / ManualReview).
- Resolution view: shows full chat history inline (text + screenshots + link cards including any API-verified evidence cards from M8 Phase 4), match metadata, both players' linked-account info.
- Three action buttons: "Settle to {creator}", "Settle to {taker}", "Draw — refund both." Each calls the existing `SettleMatchAction` / `SettleDrawMatchAction` (idempotent, status-guarded — Phase 7 of M6 made this safe).
- Audit log: every admin resolution writes a row to a new `match_admin_resolutions` table (admin user + action + reason text + timestamp).

**Phase 3 — Switch dispute resolver** (~1-2 days)

- `OpenDisputeAction` no longer dispatches `MockGameApi` resolution. Sets match to `Disputed` and waits for admin.
- `ResolveMatchTimeoutAction`: cases that would have gone to `MockGameApi` now go to `Disputed` and surface in admin queue.
- `MockGameApi` retained for the existing test suite (tests still call it via service binding); production binding switches to a null-driver that no-ops or to the real Lichess adapter once M14 lands.
- Migration of the conceptual model: `Disputed` becomes "waiting for admin or API," `ManualReview` becomes the truly-irrecoverable terminal state (locked, money frozen pending refund-or-payout decision).

### Out of scope for M12

- Real-time admin notifications (email / push when new dispute opens) — Filament's default polling is fine for launch.
- Bulk resolution actions — one match at a time.
- Auto-resolution from M8 Phase 4 verified cards (admin still clicks to confirm). M14 adds the auto-path.

---

## M13 — Chat anti-abuse + moderation

Chat is the highest-abuse-surface feature on the platform. M13 builds the policing layer. Lands after M12 so admin tools exist to review flags + bans.

### Phases

**Phase 1 — Off-platform deal detection** (~2-3 days)

- Regex flags in `SendMessageAction`: TRC20 wallet addresses (`T[1-9A-HJ-NP-Za-km-z]{33}`), ERC20 addresses (`0x[a-fA-F0-9]{40}`), BTC addresses, common payment-method names ("revolut", "paypal", "venmo", "cashapp"), messenger handles ("telegram @", "discord:", "wickr"), trade-coordination keywords ("send me", "outside stakly", "off platform").
- Flagged messages still post (we don't want to tip the abuser), but write to a `flagged_messages` table with the trigger pattern.
- Filament dashboard widget: recent flags, click-through to chat context.

**Phase 2 — Rate limits + report-user button** (~1-2 days)

- Per-user chat rate limit (10 messages / 10s, already in M8 Phase 2 — Phase 2 here adds the soft-warn UI: "You're sending messages quickly — pause a moment").
- Per-match-day cap (200 messages/day/user/match) — prevents flooding.
- Report-user button on each message: opens a Filament-routed report record with the message ID, reporter, reason.

**Phase 3 — Blocked words + admin moderation tools** (~2 days)

- Configurable blocked words list (slurs, harassment terms). Filtered server-side in `SendMessageAction` — message is replaced with a placeholder + flagged for admin.
- Admin moderation panel: list flagged + reported users, ban/mute tools, history of actions per user.
- Mute = can't send messages for N hours (configurable). Ban = account suspended (manual unban only).

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
