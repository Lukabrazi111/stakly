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
- **M8** — Match Chat + Linked Accounts **← in progress** (Phases 1–2 ✅; Phase 3 Slice 1 ✅; Phase 3 Slice 2 ✅; Phase 4, 4b, 5 next)
- **M10** — Mutual Match Cancellation
- **M12** — Filament admin panel + chat-driven dispute resolution
- **M13** — Chat anti-abuse + moderation
- **M14** — Automated outcome adapters (volume-triggered optimization)
- **M15** — Multi-game expansion (FACEIT, OpenDota, Riot adapters)
- **M9** — Chain Integration [paused — pending crypto-payment-gateway specialist]

> Only the active milestone keeps a detailed task list. Shipped milestones are one-paragraph summaries — the code is the source of truth for "how it works." Future milestones expand when started. Any of this can shift — flag the change, update the doc.

---

## Architectural decisions

Decisions made earlier that have shaped a lot of code downstream. Not locked — revisit if the situation changes, just expect a ripple of refactor when you do.

- **Money writes only through `App\Services\Wallet`** (M3.5). `users.usdt_balance` and `wallet_transactions` are written ONLY by Wallet service methods. The invariant `users.usdt_balance == SUM(wallet_transactions.amount)` is asserted in `WalletTest.php`. Direct writes from controllers / seeders / migrations / factories / tinker break this.
- **Money math is BCMath strings, never floats** (M3.5). Internal arithmetic at scale 6 via `bcadd` / `bcsub` / `bccomp`. Floats only appear at the API resource boundary.
- **Append-only ledger** (M3.5). No `updated_at` on `wallet_transactions`. Idempotency via optional `reference_id` — repeat calls return the existing row silently.
- **Actions pattern** (M11). Business logic lives in `app/Actions/<Domain>/<Verb><Noun>Action.php` with a `handle()` method, container-injected. Controllers and commands are thin adapters. `App\Services\Wallet` and `App\Services\GameApi\*` stay as primitives, not Actions. Decompose long `handle()` bodies into private helpers so `handle()` reads like a recipe of high-level steps.
- **`MatchStatus` state machine guard** (M6 Phase 7). `SettleMatchAction` / `SettleDrawMatchAction` no-op on `Settled` (idempotency), proceed on `Pending` / `Disputed`, throw on `ManualReview` or unknown. Future admin tools resolving `ManualReview` must use a different path.
- **`ManualReview` is admin-resolved out-of-band**, not in player chat (M6 Phase 7). Admin reads via Filament dashboard (M12); no admin-in-chat.
- **Snapshot, don't link**, when a relationship needs to survive identity changes. Linked-account usernames are denormalized onto `game_matches` at match creation so a mid-match unlink doesn't break dispute resolution (M8).
- **`is_platform = true` users are never user-facing** (M3.5 + M5). Filtered from profile show, wallet UI, listing pages.
- **No chain code or smart contracts right now** (project-wide). Custodial via internal Postgres ledger; chain integration is M9, paused for a specialist.
- **Strongest anti-cheat per game** (M8 + M15). Stakly only takes stakes on matches played on the strongest available anti-cheat platform for the relevant game. The verification provider (who tells us the result) and the anti-cheat platform (where the match must be played) are conceptually separate — sometimes the same vendor (FACEIT for CS2, Riot for Valorant), sometimes different (Steam-ranked Dota 2 verified via OpenDota). Per-game adapter pattern via `LinkedAccountProvider` enum + `ProfileClient` interface + `listings.platform` column. Adding a new game = new enum cases + new clients + new platform value; no new architectural shape.

---

## Shipped milestones

### M1 — Design Foundation + Homepage ✅

Stakly's dark + pink/purple gradient visual system shipped: design tokens, Bricolage + Inter fonts, gradient `Button` variant + `pill` size, `MarqueeStrip`, `SiteHeader`, `SiteFooter`, `SiteLayout`, `MobileMenu`. Homepage renders Hero (typography-only, no character art), `GameSelector` (chess + 8 "Soon" tiles), `HowItWorks`. **Decisions**: dark-only design; Stakly-skin shadcn primitives at the source (`components/ui/<name>.tsx`), never per-usage; pink hover at `bg-primary/10`, focus ring at `ring-2 ring-primary/25`.

### M2 — Auth Flow ✅

Modal-only auth (`?auth=*` URL-driven), page-only destinations for reset/2FA/confirm, email verification via `MustVerifyEmail`, `ProfileMenu` + mobile account card, Inertia v3 flash + Stakly-styled Sonner toasts, custom Fortify response bindings (register / verify / resend / forgot-password / password-reset → `/?auth=login` with toast), reset-token guard, `ThrottleVerificationSend` (1/min). **42 tests / 166 assertions.**

### M2.5 — Pre-M3 polish ✅

Settings rendered inside `SiteLayout` with inline pill-tabs sub-nav (Profile / Security / Appearance). Starter-kit shell (`AppLayout` / `AppShell` / `AppSidebar` / `Breadcrumbs` etc.) deleted. `AuthModalProvider` no longer flashes the modal at logged-in users + strips stale `?auth=*` query.

### M3 — Listings Index ✅

Public marketplace `/listings`: filterable / sortable / paginated grid (12/page, server-controlled). Featured strip on `/` shows top 4 ending-soon. PII-safe `ListingResource`. Bybit-inspired filter bar + popover/sheet via `useIsMobile()`. Smart-ellipsis pagination with `Skeleton` loading rows. Game + currency registries in `config/`. **69 tests / 434 assertions.** **Decisions**: project-wide URL contract via Spatie query-builder (`?filter[stake_max]=100&sort=ending_soon&page=2`); `$redirect = '/listings'` for graceful share-link UX; `lib/listings-query.ts` centralizes URL building.

### M3.5 — Wallet / Ledger Foundation ✅

Append-only Postgres ledger (`wallet_transactions`) is the source of truth for every USDT balance change. `App\Services\Wallet` exposes 6 methods (`deposit`, `withdraw`, `hold`, `release`, `payout`, `fee`) plus `balanceFor`, all funnelling through a private `record()` that wraps `DB::transaction(...)` + `lockForUpdate()` on the user row. Idempotency via optional `reference_id`. BCMath strings throughout (scale 6, matching Tron USDT precision). Platform rake credits the seeded `is_platform = true` user. **86 tests / 493 assertions (17 wallet-specific).**

### M4 — Listing Detail + Create Flow ✅

First end-to-end money flow. Public listing detail (`/listings/{id}`), auth-gated create form, owner-only cancel — wired to real `Wallet::hold` on create, `Wallet::release` on cancel, both transactional + idempotent. Multi-select `time_control` and `language` (jsonb + `AsEnumCollection` + `whereJsonContains`). Owner-only `App\Policies\ListingPolicy::cancel`. **110 tests / 655 assertions.** **Decisions**: duration dropdown (not datetime picker); insufficient-balance validated twice (request + `Wallet::hold` exception); detail page renders for any status (no 404 on stale share links); stake precision pinned at `decimal:0,2`; hybrid build order (backend skeleton → frontend → backend hardening → tests).

### M5 — User Profile ✅

Public read-only profiles at `/users/{username}`. `username` + `bio` columns, `UserController` + `UserProfileResource` (whitelist, no PII), 5 profile components. Auto-generated usernames via `Str::slug($name)` + collision-safe suffix loop. Reserved-username list (`'user'` reserved). Strict ASCII Latin name validation. Profile entry points via two interior `<Link>`s on each listing row/card. **147 tests / 822 assertions.** **Decisions**: `username` derived at registration, immutable for now (revisit if there's a user need); `is_platform = true` users 404 on profile show.

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

**Decisions**: 10% platform fee (`config/stakly.php` `platform_fee_rate`); 4h confirmation timeout; 1:1 listing→match; participant-only match visibility; single-confirmer rule honors the claim (Won → confirmer wins, Lost → opponent wins, Drawn → game-API arbitrates); platform does NOT pocket stakes on no-show; `MatchSettlement` service deleted in M11 — settlement lives in `app/Actions/GameMatch/`.

### M7 — Wallet UI ✅

Four pages: `/wallet` (hero balance + 3 action cards + recent activity), `/wallet/deposit` (TRC20 mock address + QR + network warning), `/wallet/withdraw` (validating form, short-circuited POST), `/wallet/history` (filter chips + paginated rows). `BalanceChip` in `SiteHeader` desktop + inline balance in `MobileMenu`. `users.tron_address` (varchar 34 unique) generated at registration via `App\Support\MockTronAddress`. Semantic transaction colors. **193 tests / 1039 assertions.** **Decisions**: multi-page (not tabbed); spendable balance only in UI (held derivable from ledger); mock TRC20 addresses until M9; withdrawal short-circuits with launch-gated toast (no ledger write); `abort_if($user->is_platform, 403)` on every wallet method.

### M11 — Controller Refactor to Actions Pattern ✅ (shipped 2026-05-17)

Business logic moved from controllers + commands into `app/Actions/<Domain>/` classes. `app/Actions/Listing/`: `Create`, `Cancel`, `Expire`. `app/Actions/GameMatch/`: `TakeListing`, `ConfirmOutcome`, `OpenDispute`, `SettleMatch`, `SettleDrawMatch`, `ResolveDispute`, `ResolveMatchTimeout`. Controllers + artisan commands shrink to thin HTTP/CLI adapters with method-injection. Old `App\Services\MatchSettlement` deleted. CLAUDE.md gained an "Actions pattern" subsection. **All 366 / 1969 tests still pass.** **Decisions**: plain PHP classes, no package, no Repositories; `handle()` method; method-injection; Wallet + GameApi stay as primitives; pure queries (read-only index/show) stay in controllers.

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

**Decisions** (Phase 1):
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

**Decisions** (Phase 2):

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

**Phase 3 Slice 1 — Image attachments** ✅ shipped 2026-05-19 (+ polish pass 2026-05-19: optimistic UI, paste-to-upload, image dimensions)

- [x] Spatie Media Library on `App\Models\Message` — `match-attachments` collection on the private `local` disk, ~400px contain-fit `thumb` conversion (`nonQueued`, `keepOriginalImageFormat`).
- [x] `StoreMessageRequest` extended: `content` and `file` are mutually-optional (`required_without` pair) so image-only messages, caption-only messages, and image+caption all validate. File: `image`, `mimes:jpeg,jpg,png,webp`, `max:5120`.
- [x] `SendMessageAction` accepts `?UploadedFile`, pre-processes via `Spatie\Image\Image::load()->save()` to strip EXIF on the original, attaches via `addMedia(...)->toMediaCollection(...)` inside the message-creation `DB::transaction`.
- [x] Second sliding-window rate limit bucket — `chat-upload:{user_id}` at 5/30s, hit only when a file is present. Text bucket (`chat:{user_id}` at 10/10s) unchanged. Each attachment-bearing send hits both.
- [x] Authenticated streaming route — `GET /matches/{match}/messages/{message}/attachments/{media}`, `?conversion=thumb` returns the preview, anything else returns the original. Re-checks `view` policy + scope (message belongs to match, media belongs to message). 404 on every miss. `Cache-Control: private, max-age=31536000, immutable` overridden post-`prepare()` because Symfony's `BinaryFileResponse` stamps `public` otherwise.
- [x] `App\Support\MessageAttachmentsPayload::forMessage` — single source of truth for the `attachments` array shape consumed by `MessageResource` (initial load) and `MessageSent::broadcastWith` (live). Empty array when no attachments (never null) so the frontend type stays `ChatAttachment[]`.
- [x] `messages.content` migration nullable — image-only messages need a null caption.
- [x] React: paperclip file picker + drag-and-drop overlay on `ChatPanel`, preview strip with progress bar (Inertia `onProgress`), thumbnail in `ChatMessageBubble` opening shadcn `Dialog` lightbox on click.
- [x] Tests: 14 new in `AttachmentUploadTest` (happy path, image-only, caption-only with file, validation rejects, status gate, upload rate limit, broadcast payload) + 10 in `AttachmentStreamingTest` (participant + non-participant + guest + unverified, cross-match / cross-message / nonexistent media, thumb conversion). 466 / 2250 (up from 442 / 2186).

**Polish pass (same day):**

- [x] **Optimistic UI**: `useMatchChat.send` injects a `pending: true` bubble immediately with a client-generated `correlation_id` (UUID); broadcast echoes the id back; on arrival, the pending bubble is replaced in place (preserving order). On error, the bubble flips to `failed: true` and renders inline Retry + Dismiss controls. The original `File` is held in a ref so retry can re-POST without asking the user to re-pick.
- [x] **Paste-to-upload**: `onPaste` handler on the chat textarea — copying a screenshot and pressing Cmd/Ctrl+V queues the image straight into the file slot (no `<img>` data-URL fallback).
- [x] **Image dimensions in payload**: `SendMessageAction` captures `width` / `height` via `Spatie\Image\Image` during the EXIF strip pre-process, persists as Media custom properties, surfaces through `MessageAttachmentsPayload`. The bubble sets `width` / `height` attributes on the `<img>` so the browser reserves the right box before bytes arrive — no scroll-shift when chat history loads.
- [x] Tests: +4 (correlation echo round-trip, null when omitted, malformed rejected, dimensions in broadcast payload). 470 / 2258.

**Decisions** (Phase 3 Slice 1):
- **Single multipart endpoint, not two-endpoint.** Initial sketch called for `POST /messages/attachment` followed by `POST /messages` referencing the upload. Spatie's `addMedia` attaches to an existing model — a separate upload endpoint needs orphan-Media cleanup or temp-storage tokens, both extra moving parts. Browser-side `XMLHttpRequest.upload.onprogress` (which Inertia exposes via `onProgress`) gives the user upload-progress UI without needing the two-step flow.
- **Spatie Media Library, not raw Storage.** Already installed since M3 (the `media` table came in with the M3 migration). Polymorphic relation, cascade-cleanup on `Message` delete, automatic conversions, disk abstraction (S3-ready by `MEDIA_DISK` env swap).
- **Private `local` disk + authenticated streaming route, not public disk + UUID paths.** Files live at `storage/app/private/<model_id>/<uuid>.jpg` — not webserver-accessible. Every fetch passes the same `view` policy gate as the match page. The cost is one DB query per image view; the win is that a leaked URL out of the chat doesn't expose the image to outsiders. For a money platform whose chat is sometimes literal dispute evidence, the trade is right.
- **EXIF stripped on upload** via `Spatie\Image\Image::load()->save()` re-encode before `addMedia`. Phone screenshots carry GPS / device / capture-time metadata; not useful to the opponent, real privacy attack surface. The thumbnail conversion strips again on its own re-encode, so both the inline preview and the lightbox original land EXIF-free.
- **`attachments_json` reserved for non-binary metadata, Media for binaries.** Image entries come from Spatie Media; Slice 2 link cards (OG previews + Phase 4 verified-game cards) will populate `attachments_json`. `MessageAttachmentsPayload` composes both into a unified `attachments` array for the frontend.
- **Two rate-limit buckets**: text 10/10s, upload 5/30s. Attachment-bearing sends consume both. Different orders of magnitude of server cost deserve different protections; alternating text + uploads doesn't bypass either limit.
- **Empty content allowed when there's a file.** `messages.content` was made nullable; a screenshot-with-no-caption is a valid chat post.
- **One attachment per message right now.** Multi-file batching is an easy follow-up if real usage calls for it; for the screenshot-in-dispute case, a series of single-image messages reads fine.

**Phase 3 Slice 2 — Plain link cards** ✅ shipped 2026-05-19

- [x] Link detection: `SendMessageAction::extractLinkUrls` finds http(s) URLs in content (trims trailing punctuation, dedups, caps at 5), passes through `SsrfGuard::isPlausiblySafe` pre-flight (no DNS on the request path), dispatches one `FetchLinkMetadataJob` per message.
- [x] `App\Jobs\FetchLinkMetadataJob` (`ShouldQueueAfterCommit`, single try): per-URL `SsrfGuard::isUrlSafe` (DNS-resolved), oscarotero/embed extraction through `App\Support\SafeHttpClient` (PSR-18 wrapper around the library's CurlClient — disables curl-level redirects, walks the chain manually with per-hop SSRF check, strips Authorization/Cookie on cross-host hops, caps at 3 redirects), proxies OG images (separate manual-redirect Http fetch + size cap + MIME whitelist + Spatie\Image re-encode for EXIF strip) to `link-images/{sha256}.{ext}` on the private `local` disk, race-safe `lockForUpdate` append to `attachments_json`, re-broadcasts `MessageSent` so the bubble updates in place.
- [x] `App\Http\Controllers\LinkImageController` + auth-gated `/link-images/{filename}` route. Filename regex `[a-f0-9]{64}\.(jpg|png|webp|gif)` rejects path traversal; `Cache-Control: private, max-age=31536000, immutable`.
- [x] `MessageAttachmentsPayload::linkEntries` shapes the persisted `attachments_json` entries into the API contract (`type, url, canonical_url, title, description, site_name, image_url`); image URLs go through `route(..., absolute: false)` so worker-built broadcasts and request-built resource payloads stay in lockstep.
- [x] `useMatchChat` dedup-by-id → **replace-by-id** so the re-broadcast (with link cards populated) swaps the bubble in place — no scroll, no reorder.
- [x] React: `LinkCard` component in `ChatMessageBubble` — Slack/Discord-style unfurl, 64px square thumbnail left, title + description + site name right, whole-card anchor with `noopener noreferrer nofollow`, Stakly pink-glow hover.
- [x] Tests: +55 (`SsrfGuardTest`, `LinkExtractionTest`, `LinkPreviewDispatchTest`, `FetchLinkMetadataJobTest`, `LinkImageStreamingTest`). 525 / 2338.

**Decisions** (Phase 3 Slice 2):
- **Proxy images, don't embed direct.** Mirrors Slice 1's auth-streamed pattern and matches what Slack / Discord / WhatsApp do — third-party hosts never see participant IPs, EXIF is stripped on re-encode, MIME is validated, size is capped. The cost is one new auth route + ~200KB cached per unique image.
- **Any http(s) URL gets unfurled, not chess-domain allowlist.** SSRF is the real defense; an allowlist is belt-and-suspenders that limits the value of the feature (YouTube clip of a disputed game wouldn't get a card). General-purpose covers coordination + dispute-evidence both.
- **Per-hop SSRF, not just initial URL.** Library defaults follow 10 curl-level redirects with SSL verify OFF. A malicious `https://shortener.com/x` → `http://169.254.169.254/...` chain would bypass an initial-URL check; the PSR-18 wrapper SSRF-checks every Location.
- **Split `SsrfGuard::isPlausiblySafe` (no DNS, request-path) vs `isUrlSafe` (DNS-resolved, worker-path).** Doing DNS on the chat-send request would block message delivery on one resolver call per URL — moved to the queued worker, kept the cheap literal-IP-range check on the request path.
- **Cache results by URL hash for 1h, not persistent.** Link previews are nice-to-have; if Redis flushes, the next paste re-fetches. No DB table for cache.
- **Single try, fail silently.** Logged via `Log::info`; missing card is acceptable; throwing would put dead URLs at the head of the failed-jobs queue forever.

**Decisions** (Phase 3):
- **Image-only uploads today**: PDFs, videos, and other files are rejected at the validation layer. Screenshots are the primary use case; videos can come in via Slice 2 link cards (YouTube/Streamable/etc.). If real users push for inline short video later, the Media Library setup absorbs it cheaply — add `video/mp4` to the accepted MIME list, lift the size cap, add a `<video>` branch in the bubble.
- **5MB cap**: balances screenshot quality vs storage/bandwidth.
- **OG fetch is queued**: don't block chat send waiting for `<title>` of pasted URL; render plain link card immediately, swap to enriched card when fetch completes.

**Phase 4 — Smart link enrichment for Lichess** ✅ shipped 2026-05-22

The big payoff of having linked accounts: Lichess games surface as trusted evidence cards in chat — either auto-posted when players confirm, or pasted manually by a player anytime. Hybrid by design.

Two complementary paths, both producing the same `type: 'game_card'` attachment shape so the frontend has one renderer:

- **Manual paste**: a player pastes a Lichess game URL in chat. System fetches by game ID, cross-checks player usernames against the snapshot, renders a verified card. The escape hatch for "auto-fetch picked the wrong game," chess.com games (covered by 4b), or players without linked accounts.
- **Auto-fetch on first confirm**: when the first player hits Confirm, system queries Lichess for recent games between the two snapshotted usernames. If exactly one decisive game exists in the match's time window, post it as a verified card via a system message — visible to both players and admin. No yes/no vote.

Outcome resolution is unchanged. Players still Confirm Won/Lost/Drawn — that's the only binding signal. Cards are evidence the admin sees if a dispute lands. Auto-settle stays gated to M14.

### Tasks

- [x] **Schema**: `match_provider_snapshots` sibling table on `game_matches` (one row per (match, side, provider) — `id`, `match_id` FK cascade, `side` ('creator'|'taker'), `provider` (`LinkedAccountProvider` enum value), `username`, timestamps; UNIQUE `(match_id, side, provider)`, index `(provider, username)`). Sibling table rather than flat columns on `game_matches` so per-provider identifier shape can grow as M15 adds multi-identifier games (CS2 Steam+Faceit, Riot ID + region, etc.) without migrating the wide `game_matches` table. Initial design used 4 flat columns (`{side}_{provider}_username` × 4); refactored to the sibling table mid-Phase 4 once we agreed CS2 was the next adapter — the multi-identifier problem made the flat shape a near-term liability.
- [x] **`TakeListingAction`**: inserts one `match_provider_snapshots` row per (side, provider) where the player has a verified link, via a `snapshotProviderAccounts()` private helper that iterates `LinkedAccountProvider::cases()` and checks `{provider}_verified_at` non-null on each user. Batch insert (single SQL) inside the existing match-create transaction. Unverified-but-set columns are deliberately not snapshotted.
- [x] **`LichessGameClient` service**: `fetchGame(string $id)` for the paste path, `searchGamesBetween(string $userA, string $userB, CarbonInterface $since)` for auto-fetch. Both `Http::fake()`-able. Lichess ndjson parsed line-by-line; bot/AI opponents (`players.{color}.user` missing) surface as empty string so cross-check fails cleanly.
- [x] **URL detection in `SendMessageAction`**: `extractLichessGameId(url)` static helper recognises `lichess.org/{8-12 alphanumeric}` with optional `/embed/` wrapper, color (`/white` `/black`), and anchor (`#5`) suffixes. Explicit denylist for reserved Lichess paths in the same length window (`training`, `analysis`, `streamer`, `practice`, `tournament`). Lichess game URLs dispatch one `FetchLichessGameMetadataJob` per ID; non-Lichess URLs continue through the OG fetcher. Mixed messages dispatch both job types.
- [x] **`FetchLichessGameMetadataJob`**: fetches by ID, cross-checks both player usernames against the snapshot via `$match->snapshotUsername(side, provider)` (case-insensitive, order-independent), appends `type: 'game_card'` with `verified: true|false`, re-broadcasts `MessageSent`. Eager-loads `providerSnapshots` at job entry. Mirrors `FetchLinkMetadataJob`'s row-lock-append pattern. Unverified games still render a card (with an "Unverified" pill) so the admin sees that the URL resolved to a real game.
- [x] **`AutoFetchLichessGameJob` + `ConfirmOutcomeAction` wiring**: `ConfirmOutcomeAction` captures the zero→one confirm transition via a `&$wasFirstConfirm` reference inside the locked transaction; dispatches AFTER commit when both Lichess snapshot rows are present (`canAutoFetch()` checks via `snapshotUsername()`). Job runs `searchGamesBetween`, filters to decisive games (mate/resign/outoftime/timeout/cheat), posts a system-message card via `PostSystemMessageAction` only when EXACTLY one candidate exists. Idempotency via Postgres `whereJsonContains('attachments_json', [['source' => 'auto_fetch']])` scoped to system messages.
- [x] **`PostSystemMessageAction`**: extended to accept `?array $attachments = null` so auto-fetch can post a system-message-with-card in one call. Existing callers (text-only system messages) pass two args; the new path passes three.
- [x] **`MessageAttachmentsPayload::gameCardEntries`**: filters `type: 'game_card'` entries from `attachments_json` into the API shape. Near-passthrough — no URL rewriting needed since the entry was already API-shaped at write time.
- [x] **React**: `ChatGameCardAttachment` TS type extends the `ChatAttachment` union. `GameCardAttachment` component mirrors `LinkCard`'s visual family (Stakly-skinned pill border, hover glow) with a Crown chess tile, success-toned verified badge (color + `BadgeCheck` icon for a11y), per-player badges with winner highlighting, and a `lichess.org` link footer. `ChatMessageBubble` renders cards for both `text` (paste) and `system` (auto-fetch) messages; `SystemBubble` was extended to optionally render cards below the centered text pill.
- [x] **Tests**: +65 (snapshot population on TakeListing × 4; LichessGameClient `Http::fake` happy/404/5xx/429/malformed/draw/aborted/AI-opponent × 8 + ndjson × 6; URL detection variants × 19; dispatch routing × 4; paste-path job verified/swapped-colors/case-insensitive/snapshot-missing/no-match/one-side/404/5xx/concurrent × 9; auto-fetch job happy/zero/multiple/non-decisive/mixed/5xx/missing-snapshot/idempotent/paste-doesnt-block × 8; first-confirm dispatch fires/missing-snapshots/second-confirm/no-change/changed-outcome × 6). **525 / 2338 → 590 / 2495.**

### Decisions (Phase 4)

- **Lichess first**. Lichess has both direct game-by-ID lookup AND searchable games-between-users; chess.com requires archive paging + eventual-consistency retries. Lichess shakes out both architectures on the easier API. chess.com enrichment follows in Phase 4b.
- **Hybrid: auto-fetch + manual paste, not either/or**. Auto-fetch is convenience for the common case (linked players who played on Lichess). Paste is the escape hatch (auto-fetch picked wrong, no linked accounts, chess.com play). Both produce identical card shape — single renderer, single audit trail.
- **Auto-fetch is evidence, not a vote**. No "Is this your game?" yes/no buttons on the auto-card. Players continue to use the existing Confirm Won/Lost/Drawn — that remains the only binding signal. The "one says yes, one says no" disagreement axis is eliminated by not creating it.
- **Auto-fetch never auto-settles**. Even when the API-fetched game shows a clear winner that disagrees with players' confirms, the player consensus still wins (or dispute path arbitrates). API-only settlement is M14 territory, gated on real dispute volume.
- **Game-picking heuristic: single decisive game in window or skip**. Auto-fetch's window is `match.created_at → now`. If 0 candidates: skip. If multiple: skip and let a player paste the right URL. No "best guess" — wrong-game evidence is worse than no evidence.
- **Auto-fetch fires once per match**. Dispatched on the first confirm transition (zero confirms → one confirm) to avoid re-querying on confirm-changes. Job-level idempotency check via `attachments_json` membership.
- **Cross-check usernames against snapshot, not live link**. Even if a player unlinks mid-match, snapshot survives. Prevents "unlink to escape match" abuse.
- **Verification is binary**: verified ✓ or not. No "verified but with caveat" — caveats are for chat-mediated discussion with admin.

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

**Decisions** (Phase 5):
- **Linking is required to create or take listings.** Players without a verified account can't participate. This is a real UX gate — but without it, dispute resolution is impossible (admin has nothing to cross-check).
- **Dispute evidence prompt is non-blocking**: players can dispute without submitting evidence — chat itself is the evidence record. The prompt nudges, doesn't gate.
- **Platform column default `chess_com`**: existing seeded listings stay valid. New listings pick at creation.

### Not in M8

- **Filament admin panel** — M12. Until that lands, disputes still resolve via the existing `MockGameApi` path. Chat is *additive* in M8, not replacing dispute resolution yet.
- **chess.com smart link enrichment** — Phase 4b (follow-up to Phase 4).
- **Auto-resolution without admin** — M14, once adapters are battle-tested.
- **Chat anti-abuse** (off-platform deal detection, rate limits beyond basic, report-user, blocked words) — M13.
- **Voice / video chat in-app** — not on the table; links to externally-hosted clips cover the use case via Slice 2.
- **Read receipts, typing indicators, message reactions, edit/delete, mentions, DMs** — none of these today. Open if a real user pulls for one.

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

Trigger: M12 admin path is in use and dispute volume justifies the engineering. Pull forward sooner if a class of disputes shows it'd be obviously easier to auto-resolve.

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

**Architectural composition** — mostly the existing shapes, with one schema migration called out below:

- `LinkedAccountProvider` enum gains `Faceit`, `Riot`, possibly `Steam` (for the OpenDota / Steam-ranked Dota 2 path).
- New `ProfileClient` implementations: `FaceitProfileClient`, `RiotProfileClient` (likely split per region), `SteamProfileClient`. Bio-code paste flow per provider where the platform exposes an editable profile field; OAuth where available (FACEIT and Riot both expose it — cleaner UX, requires app approval).
- `Game` enum gains `Cs2`, `Dota2`, `Valorant`, `Lol`.
- `listings.platform` (M8 Phase 5) expands its allowed values to include the new platforms.
- New game-result clients (`FaceitGameClient`, `OpenDotaGameClient`, `RiotGameClient`) mirror M8's `LichessGameClient` / `ChessComGameClient` — first wired into chat link-card enrichment, later into the auto-resolver via M14.
- Webhooks where the provider supports them (FACEIT match-completed, Riot match-end) reduce polling cost when M14 lands.

**`match_provider_snapshots` table — extending to non-chess identifiers** (refactor landed in M8 Phase 4):

The sibling table that holds linked-account snapshots is already in place (`match_provider_snapshots`, see M8 Phase 4 task list). Today each row is `(match_id, side, provider, username)` — sufficient for chess.com + Lichess. When CS2 / Dota 2 / Valorant land, each will need additional identifier columns: Steam ID (uint64 — likely `string(20)`), Faceit player ID (uuid), Riot ID region (varchar 4), maybe MMR at snapshot for sandbag-detection surfaces.

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
