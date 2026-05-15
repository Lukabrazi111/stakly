# Stakly Milestones

Frontend-first MVP. Build UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands later per page once the UI is validated.

## Phases (map)

- **M1** — Design Foundation + Homepage ✅
- **M2** — Auth Flow ✅
- **M2.5** — Pre-M3 polish ✅
- **M3** — Listings Index ✅
- **M3.5** — Wallet / Ledger Foundation ✅
- **M4** — Listing Detail + Create Flow ✅
- **M5** — User Profile ✅
- **M7** — Wallet UI ✅
- **M6** — Match Flow (mock) **(next)**
- **M8** — Settings / Linked Accounts (chess.com / Lichess) [deferred]
- **M9** — Chain Integration [deferred — pending crypto-payment-gateway specialist]
- **M10** — Match Chat [deferred — post-launch v1.1]

> Only the current milestone keeps a detailed task list. Future milestones expand when started. Completed milestones live at the top as short summaries.

---

## M1 — Design Foundation + Homepage ✅

Stakly's dark + pink/purple gradient visual system shipped: design tokens, Bricolage + Inter fonts, gradient `Button` variant + `pill` size, `MarqueeStrip`, `SiteHeader`, `SiteFooter`, `SiteLayout`, `MobileMenu`. Homepage renders Hero (typography-only, no character art), `GameSelector` (chess + 8 "Soon" tiles), `HowItWorks`.

**Locked decisions:**
- `/` is hybrid: marketing sections + featured-listings strip (built in M3 — never ship fake data on `/`).
- Visual system rules live in CLAUDE.md "Visual System" + memory.

---

## M2 — Auth Flow ✅

Modal-only auth (`?auth=*` URL-driven), page-only destinations for reset/2FA/confirm, email verification via `MustVerifyEmail`, `ProfileMenu` + mobile account card, Inertia v3 flash + Stakly-styled Sonner toasts, custom Fortify response bindings (register / verify / resend / forgot-password / password-reset → `/?auth=login` with toast), reset-token guard, `ThrottleVerificationSend` (1/min). **42 tests / 166 assertions.**

Locked architecture in `memory/project_milestones_state.md`.

---

## M2.5 — Pre-M3 polish ✅

Settings rendered inside `SiteLayout` with inline pill-tabs sub-nav (Profile / Security / Appearance). Starter-kit shell (`AppLayout` / `AppShell` / `AppSidebar` / `Breadcrumbs` etc.) deleted. `AuthModalProvider` no longer flashes the modal at logged-in users + strips stale `?auth=*` query.

---

## M3 — Listings Index ✅

Public marketplace `/listings` is live: filterable / sortable / paginated grid of open listings (12/page, server-controlled). Featured strip on `/` shows top 4 ending-soon. PII-safe `ListingResource`. Bybit-inspired filter bar (`ListingFiltersBar`, `ListingFilters` popover/sheet via `useIsMobile()`), two card variants (`ListingCard` marketing / `ListingRow` index). Smart-ellipsis pagination with `Skeleton` loading rows wired to `router.on('start'/'finish')`. Game + currency registries (`config/games.ts`, `config/currencies.ts`) — adding a future game/currency is a one-line change. Stakly-skinned shadcn primitives at the source (select, input, toggle, sheet, dialog, popover). **69 tests / 434 assertions.**

**Locked decisions:**
- **URL contract** project-wide (via Spatie query-builder): `?filter[stake_max]=100&filter[time_control]=blitz,rapid&sort=ending_soon&page=2`. Future filtered endpoints use the same shape — no per-controller adapters.
- **Take CTA is UI-only** in M3 (wired to nothing). Real take lands in M4 (UI) + M6 (match flow).
- **Create listing form doesn't exist** — lands in M4 *after* M3.5 ledger is in place.
- `IndexListingsRequest` keeps `$redirect = '/listings'` for graceful share-link UX; `?filter[admin]=1` rejected via `array:keys` whitelist.
- `lib/listings-query.ts` `buildListingsQuery(filters, { page? })` centralizes URL building across filter bar / popover / pagination.

---

## M3.5 — Wallet / Ledger Foundation ✅

Append-only Postgres ledger (`wallet_transactions`) is now the source of truth for every USDT balance change. `App\Services\Wallet` exposes 6 static methods (`deposit`, `withdraw`, `hold`, `release`, `payout`, `fee`) plus a `balanceFor` helper, all funnelling through a single private `record()` that wraps `DB::transaction(...)` + `lockForUpdate()` on the user row, enforces idempotency via optional `reference_id`, applies the signed-amount convention per `WalletTransactionType`, throws `InsufficientBalanceException` on overdraft, and writes the immutable ledger row + balance update atomically. BCMath strings throughout (scale 6, matching Tron USDT precision and the `decimal(18, 6)` columns). Platform rake credits the seeded `is_platform = true` user via `Wallet::fee(...)`. Seeders give every dev user $1000 through `Wallet::deposit` (idempotent via `seed:dev-deposit:{id}` references — re-running `migrate:fresh --seed` doesn't double-credit). No UI, no chain code — pure financial infrastructure behind a future `ChainGateway` adapter contract that lets the chain layer plug in at pre-launch. **86 tests / 493 assertions (17 wallet-specific).**

**Locked decisions:**
- **Service-only-write rule**: `users.usdt_balance` and `wallet_transactions` are written ONLY by `App\Services\Wallet`. Direct writes from controllers, seeders, migrations, factories, or tinker break the invariant `users.usdt_balance == SUM(wallet_transactions.amount)` (asserted in `WalletTest.php`).
- **Money math is BCMath strings**, never floats. Internal arithmetic at scale 6 via `bcadd` / `bcsub` / `bccomp`. Floats appear only at the API resource boundary (`(float) $this->stake_amount` in `ListingResource`).
- **Signed-amount convention**: credits (`Deposit`, `EscrowRelease`, `Payout`, `Fee`) write positive amounts; debits (`Withdrawal`, `EscrowHold`) write negative amounts. Balance = `SUM(amount)` for that user.
- **Platform-as-User pattern**: platform rake credits the seeded `is_platform = true` user — no nullable `user_id` on `wallet_transactions`, no special-casing in the service.
- **Idempotency contract**: every Wallet call accepts an optional `reference_id`; repeat calls return the existing row silently (no-op, no double-debit). Verified inside the user-row lock so concurrent same-reference calls are serialized.
- **Append-only enforcement at both layers**: no `updated_at` column, `UPDATED_AT = null` on the `WalletTransaction` model — query log assertions (test 3.6) prove `UPDATE` never hits the ledger.
- **Conservation of money**: holds + releases + payouts + fees in a complete match flow sum to 0 (test 3.7). Money is redistributed, never created or destroyed inside a match.
- **Nested-transaction rule**: `Wallet::hold` etc. open their own `DB::transaction` internally, but callers can wrap a larger transaction around them (e.g., M4 "create listing + `Wallet::hold`" must commit or roll back as one unit). Laravel nests via savepoints — safe in either direction.
- **Chain integration deferred to M9; provider TBD with a specialist developer.** M3.5 contains zero chain code regardless of which provider lands.

---

## M4 — Listing Detail + Create Flow ✅

First end-to-end money flow on the platform. Public listing detail (`/listings/{id}`), auth-gated create form (`/listings/create`), owner-only cancel — wired to real `Wallet::hold` on create and `Wallet::release` on cancel, both transactional with the listing row, idempotent via `listing-create:{id}` / `listing-cancel:{id}` references. Multi-select `time_control` and `language` (jsonb columns + `AsEnumCollection` + `whereJsonContains` overlap filter). Owner-only `App\Policies\ListingPolicy::cancel`. **110 tests / 655 assertions (was 88/533).**

**Locked decisions:**
- **Duration dropdown** (`1h..72h`), not datetime picker — kills timezone confusion.
- **Insufficient balance handled twice**: `StoreListingRequest` validates `stake_amount ≤ balance` upfront (`Wallet::balanceFor($user)` for fresh value) + `Wallet::hold` still throws `InsufficientBalanceException` for concurrent-tab races, caught in the controller as a `stake_amount` `ValidationException`.
- **Detail page renders for any status** — taken/expired/cancelled show status badge, no 404 (shared links shouldn't break).
- **Stake precision pinned** at `decimal:0,2` to match the `decimal(12, 2)` column — otherwise `100.456` holds at scale 6 but stores at 2 decimals, drifting on refund.
- **Cancel via `Dialog`**, not `AlertDialog` (installing a new Radix package would have conflicted with our Stakly-skinned `button.tsx`).
- **Hybrid build order**: backend skeleton → frontend iteration → backend hardening → tests. Avoids both pure-frontend throwaway code and backend-first delayed visual feedback.
- **Out of scope**: listing edit (cancel + re-create is the v1 mental model); image uploads; take CTA behavior (M6); pause/resume + max-listings cap + step-up auth (see Post-MVP section).

**Bugs caught by Phase 8 tests:**
- **Precision mismatch** — `decimal(12, 2)` listings column vs scale-6 wallet ledger. Stake of `100.456` would hold `-100.456000` in the ledger but the listing stored `100.46`, drifting 0.004 USDT on cancel. Fixed: `decimal:0,2` rule on `stake_amount`.
- **Stale `$user` instance** — `stakeWithinBalance` read `$user->usdt_balance` directly, returning the pre-deposit cached value when the auth instance was stale. Fixed: route through `Wallet::balanceFor($user)` which always does `$user->fresh()`.

**Seeder note:** `ListingSeeder` calls `Wallet::hold` for every open/taken/ending-soon seeded listing so seeded data satisfies the balance ↔ ledger invariant. Test User + seeded users seeded with $10k each (was $1k — some users own multiple listings, holds overflowed). Verified post-seed: 0 users with broken invariant.

---

## Post-MVP — Listings polish (deferred, not in M4)

Captured so the intent isn't lost. **Don't pull these into M4.** Each is a real user-facing improvement but adds scope (state machine, UX flow, or step-up auth) that doesn't earn its complexity until we see real usage.

- **Step-up auth at listing creation.** Email-verified is already enforced via middleware. *All-listings* 2FA = friction that trains users to dismiss prompts. Better: step-up only for **high-stake** listings (e.g., `stake_amount > $500`) via Fortify's `confirm-password` (already plumbed for settings). Optionally also step-up on suspicious signals (new device fingerprint, rapid-fire creates). Decision deferred until post-launch when actual abuse patterns are visible.
- **Max active listings cap.** Wallet already caps total *capital exposure* naturally (can't escrow > balance). Explicit count cap is anti-marketplace-spam only. Suggested cap: **5** (not 2 — a player wanting one Blitz + one Rapid + one Classical listing hits 2 immediately). Consider tiered caps later (KYC'd users get higher cap).
- **Pause / resume listing.** New `paused` status on `ListingStatus` enum; `scopeOpen` excludes it. **Soft pause** (hide from board, keep escrow held) is the chosen flavor (locked 2026-05-15) — atomic, no extra wallet ops, no new dispute surface. Hard pause (release escrow, re-hold on resume) was considered and rejected (wallet churn for marginal UX benefit). Owner-only via `ListingPolicy::pause`.

---

## M5 — User Profile ✅

Public read-only player profiles at `/users/{username}`. Schema (`username` + `bio` columns on users), `UserController` + `UserProfileResource` (whitelist-only, no PII), TS types, page with 5 components (header + stats grid + active listings + match history empty state + linked accounts empty state). Auto-generated usernames at registration via `Str::slug($name)` + collision-safe suffix loop in `CreateNewUser`. Reserved-username list. Strict ASCII Latin name validation (Bybit-style) enforced in `ProfileValidationRules`. Profile entry points wired through `ListingRow` / `ListingCard` / listing-detail header — each via two interior `<Link>`s (creator zone → profile, body → listing). **147 tests / 822 assertions (was 110/655).**

**Locked decisions:**

- **Public URL** `/users/{username}` via `User::getRouteKeyName()` override. Username derived at registration, immutable in v1.
- **`'user'` is in `RESERVED_USERNAMES`.** Empty-slug fallback names (emojis, pure punctuation) start at `user-1`, never bare `user` — avoids placeholder-looking handles.
- **`CreateNewUser` wraps each INSERT in `DB::transaction(...)`** so unique-violation retries use a Postgres savepoint, not a full txn abort. Critical inside `RefreshDatabase` outer wrapper in tests; also defensive for any future caller that wraps registration in a transaction.
- **Platform user hidden via inline `abort_if($user->is_platform, 404)`** in `UserController::show`. Explicit, no User-model global scope; `Wallet::fee()` still finds the platform user normally.
- **`openListings` on profile uses `setRelation('user', $user)`** to skip a redundant SQL query — the user we just loaded is the same one each listing belongs to.
- **Strict ASCII Latin names** — `regex:/^(?=.*[a-zA-Z])[a-zA-Z '\-\.]+$/` enforced via `ProfileValidationRules` trait shared by `CreateNewUser` and `ProfileUpdateRequest`. Custom error message in `profileMessages()`. Catches numbers, emojis, accents, non-Latin scripts. (Won't catch keyboard-mash like `dsakl djsa` — content moderation is a separate problem, deferred to KYC at pre-launch.)
- **Entry points** use two interior `<Link>`s in row/card (creator zone + listing body). Outer `<article>` carries the unified hover glow. Take button moved outside both Links (it's an action, not nav — relevant for M6).
- **`creator.username` added to `ListingResource`** + all controller eager-loads bumped from `user:id,name` to `user:id,name,username`. Required by the profile-link entry points.

---

## M9 — Chain Integration

**Deferred — pending crypto-payment-gateway specialist.**

Real on-chain TRC20 USDT deposits and withdrawals. Provider, custody model, key management, gas strategy, and architecture all TBD — to be designed with a specialist developer joining the project later.

The platform layers below are deliberately provider-agnostic and won't change when chain integration lands:

- **Internal ledger** (`wallet_transactions`, M3.5) — append-only, idempotent via `reference_id`, source of truth for `users.usdt_balance`. Whatever provider is picked, it will call `Wallet::deposit` on confirmed deposits and `Wallet::withdraw` from a queued withdrawal worker.
- **Wallet UI** (M7) — overview, deposit page (currently shows a `MockTronAddress`), withdraw form (validates fully, short-circuits on submit). Real per-user addresses replace the mocks; the withdraw POST handler swaps the short-circuit for a real worker dispatch.
- **`App\Support\MockTronAddress`** — continues to generate placeholder addresses for `users.tron_address` until the integration lands.

When the specialist joins, the live questions to resolve are: **provider** (Tatum / Fireblocks / BitGo / Coinbase Developer Platform / DIY) → **custody model** (BYO-key vs vendor-managed) → **key storage** (env / AWS Secrets Manager / KMS / vendor-held) → **TRC20 gas strategy** (sweep-on-deposit + staked TRX vs alternatives) → **testnet shakedown plan** → **mainnet flip checklist**. Background research and prior planning iterations live in git history if useful as a starting point.

---

## M10 — Match Chat (post-launch v1.1)

**Deferred — post-launch v1.1.** Chat between matched players so they can coordinate (start time, time control, rematch suggestions, dispute discussion).

### Why deferred (not built in M6)

- Real scope: real-time messaging needs WebSockets, persistence, moderation tooling — easily a 1–2 week milestone on its own.
- Abuse surface: free-form chat enables off-platform deal-making (evades the 10% rake), harassment, easier sandbagging coordination, and money-laundering negotiation. Stakly needs real abuse data before designing the right anti-abuse mechanisms.
- Support burden: every dispute then requires reading chat logs.

### Locked design intent (when M10 lands)

- **Structured messages first, free-form text later.** Quick-action buttons like *"Suggest start time"* / *"Request rematch"* / *"Disconnected — restart?"* before letting users type freely. Massively reduces abuse surface vs. open chat.
- **Anti-abuse instrumentation from day one:**
  - Off-platform deal-detection (regex for crypto wallet addresses, payment-method names, Telegram handles in messages → flag for review).
  - Per-user rate limits.
  - Report-user button → dispute pipeline.
  - All chat logs auditable by support.
- **Cancel fee discussion** — the user raised this during M6 planning. Current architecture already prevents bait-listings (cancel releases escrow with no game played, no harm). A cancel fee would discourage *frequent* cancellation but isn't anti-collusion. Worth revisiting if abuse data shows churning patterns.

### Deferred infrastructure (related)

- **Real-time push layer** (Laravel Reverb or Pusher) — decided 2026-05-15 to defer until 2+ live features need it. M6 Phase 3 uses Inertia v3 polling for live match-page updates, which is sufficient for one-page-at-a-time use cases. M10 would be the natural trigger for introducing Reverb.

---

## M6 — Match Flow (mock) **(next)**

The missing core loop: take listing → match created → both players play off-platform → return to confirm outcome → money settles. Real chess.com / Lichess outcome verification is M8; M6 uses a mocked game-API for the dispute tiebreaker so the milestone is self-contained.

### Key parameters (defaults — confirm or override before Phase 1)

- **Platform fee**: 10% of pot, configurable via `config/stakly.php`. Stored as a string (`'0.10'`) for BCMath.
- **Confirmation timeout**: 4h after match creation (clock starts the moment Take is confirmed and both stakes are escrowed — NOT at listing creation, which has its own `expires_at`). If only one player confirms by then, that player wins by default. If neither confirms, auto-dispute → game-API.
- **Listing → match relationship**: 1:1. A taken listing creates exactly one match; once settled, the listing stays `Taken` forever (no re-listing).
- **Match visibility**: only the two players (creator + taker) can view a match page. Non-participants get 404.
- **Confirmation options**: per-player "I won" / "I lost" buttons. Both saying the same player won = settle. Both saying the same player lost (impossible in good faith but a real edge) = dispute.

### Money flow at settlement

Each player has a `Wallet::hold` of $stake from listing-create / take. At settlement (`pot = creator_stake + taker_stake`, `fee = pot * fee_rate`):
- **Winner**: `Wallet::payout(pot - fee, listing, ref: "match-payout:{$match->id}")`.
- **Platform**: `Wallet::fee(fee, listing, ref: "match-fee:{$match->id}")`.
- **Loser**: no further wallet op — their original `EscrowHold` is the loss (permanent debit).

Conservation check across all parties: `-creator_stake + -taker_stake + (pot - fee) + fee = 0` ✓

### State machine

```
Listing.Open --[take]--> Match.Pending, Listing.Taken (taker's Wallet::hold)
Match.Pending --[both confirm same winner]--> Match.Settled (settlement)
Match.Pending --[both confirm different]--> Match.Disputed (game-API)
Match.Pending --[one confirms, 4h passes]--> Match.Settled (default-win for confirmer)
Match.Pending --[neither confirms, 4h passes]--> Match.Disputed (game-API)
Match.Pending --[either opens dispute]--> Match.Disputed (game-API)
Match.Disputed --[game-API returns winner]--> Match.Settled
Match.Disputed --[game-API can't determine]--> Match.ManualReview (shell only)
```

**`ManualReview` is a terminal state for v1.** Money stays locked in escrow; an admin resolves the case manually post-launch (admin tools deferred to a later milestone). Auto-refund-on-API-failure was rejected: a losing player could trigger `ManualReview` to recover their stake (e.g. deliberately not playing the game when their chess.com account is linked).

### Phases

> Estimates are **focused solo dev time**, not calendar time. Each phase ships something usable; commit per phase.

**Phase 1 — Schema + state machine + policies** ✅ shipped

- [x] **1.1** Migration: `game_matches` table — `id, listing_id (UNIQUE FK), taker_user_id (FK), status (string), creator_confirmed_outcome (string nullable), taker_confirmed_outcome (string nullable), winner_user_id (nullable FK), dispute_opened_at (nullable), dispute_opened_by (nullable FK), settled_at (nullable), timestamps`.
- [x] **1.2** `App\Enums\MatchStatus` — `Pending`, `Disputed`, `Settled`, `ManualReview` (Cancelled dropped per YAGNI; add only if a both-agree-to-cancel feature ships later).
- [x] **1.3** `App\Enums\MatchOutcome` — `Won`, `Lost`.
- [x] **1.4** `App\Models\GameMatch` model + factory — relations: `listing`, `taker`, `winner`, `disputeOpener`. Creator reached via `$match->listing->user`. Avoided the name `Match` (PHP reserved keyword post-8.0).
- [x] **1.5** `Listing` model: added `gameMatch()` HasOne; `User` model: added `gameMatchesAsTaker()` HasMany. Combined "all my matches" query (creator + taker union) deferred to Phase 6 when `/matches` list page needs it.
- [x] **1.6** `App\Policies\GameMatchPolicy`: `view` + `confirm` + `openDispute` — only creator or taker pass. Auto-discovered by Laravel 11+ (no manual `Gate::policy(...)` registration needed).
- [x] **1.7** Tests: 24 tests / 38 assertions covering casts, relations, schema invariants (1:1 enforced at DB), all 3 policy methods including non-Pending blocks.

**Phase 2 — Take listing → create match** ✅ shipped

- [x] **2.1** Wired the existing "Take" CTA on `/listings/{id}` with a Dialog confirmation. Branches: authed-with-balance (Take + Dialog), authed-no-balance (disabled + "Deposit USDT to take this match" link), guest (Log in to take), non-Open status (disabled status label), owner (hidden — cancel area handles their case).
- [x] **2.2** `GameMatchController::take`: row-locked transaction with three distinct failure modes documented inline — self-take 403, race-lost (state changed between page load and submit) 302 + info toast, insufficient-balance race 422 keyed on `amount`.
- [x] **2.3** `App\Http\Requests\GameMatch\TakeRequest` — empty body rules + balance pre-check via `withValidator` (sourced from route-bound listing). Same shape as `StoreListingRequest::stakeWithinBalance`.
- [x] **2.4** Route: `POST /listings/{listing}/take` (auth + verified middleware).
- [x] **2.5** Tests: 10 tests / ~70 assertions — happy path, self-take 403, guest/unverified redirect, race-lost branch (Taken/Expired/Cancelled/past-expiry → 302 + info toast — chose redirect over `ValidationException` so user gets friendlier UX when listing disappears), insufficient balance 422, double-submit safety, BCMath round-trip.

**Phase 2 also lifted Phase 3.1, 3.2, and basic 3.3 from the original plan** (the route `GET /matches/{match}`, `GameMatchController::show` with 404-not-403 for non-participants, `GameMatchResource` PII whitelist, `resources/js/pages/match/show.tsx` with opponent card + stake/pot/time-control stats + status pill). Phase 3 now builds the interactive confirm + polling layer on top — those route/controller/page foundations are reused, not rebuilt. **9 additional tests / ~60 assertions for the show flow + PII-safety assertions.**

**Phase 3 — Confirm UI + settlement + polling** ✅ shipped

Phase 5 (settlement service) folded in here so the agree-path moves real money end-to-end in one commit. Phase 4 (dispute resolution + game-API) stays separate — disputed matches sit in `Disputed` status with "Under review" UI until Phase 4 lands.

**"Change freely until opponent confirms" rule** (locked 2026-05-15, replaces an earlier "one allowed change" design): a player can change their confirmation any number of times while the match is still `Pending`. Once both players have confirmed, the match resolves (Settled or Disputed), status flips to non-Pending, and `GameMatchPolicy::confirm` blocks any further changes. **The lock is implicit via match status — no `confirmation_locked` columns needed.** Misclicks are recoverable until your opponent commits.

**Data + services:**

- [x] **3.1** `config/stakly.php` — `platform_fee_rate` (`'0.10'` default, BCMath string).
- [x] **3.2** `App\Services\MatchSettlement::settle($match, $winner)` — atomic settlement under a row lock; calls `Wallet::payout(winner, pot - fee)` + `Wallet::fee(fee)`; idempotent on `match-payout:{id}` / `match-fee:{id}` references; flips status → `Settled` + sets `winner_user_id` + `settled_at`. Throws `InvalidArgumentException` on non-participant winner (defense in depth).

**Controller + request:**

- [x] **3.3** `App\Http\Requests\GameMatch\ConfirmRequest` — outcome enum validation.
- [x] **3.4** `GameMatchController::confirm` with private `resolveBothConfirmed` + `confirmRedirect` helpers. Race-safe via `lockForUpdate`. Distinguishes 4 resolution sentinels: `recorded` / `no-change` / `settled` / `disputed` / `too-late` — each maps to its own flash toast.

**Frontend:**

- [x] **3.5** Confirm Dialog (`components/match/confirm-buttons.tsx`) with first-confirmation vs change-confirmation copy. Selected outcome's button is gradient + disabled; the other stays clickable.
- [x] **3.6** Disabled state once status != Pending — confirm UI hidden entirely, settlement summary or dispute banner takes the slot.
- [x] **3.7** `SettlementSummary` (`components/match/settlement-summary.tsx`) — winner badge + pot/fee/payout breakdown, success accent for the winner's view, muted for the loser's view. Disputed/ManualReview "under review" banner inline in `match/show.tsx`.
- [x] **3.8** Inertia v3 polling every 8s while Pending — `useEffect` + `setInterval` calling `router.reload({ only: ['match'] })`. Stops automatically on terminal status (interval cleared in cleanup when status changes).
- [x] **3.9** `components/match/` folder with `confirm-buttons.tsx`, `settlement-summary.tsx`, and `match-timer.tsx`.

**Tests:**

- [x] **3.10** `GameMatchConfirmTest` — 16 tests covering auth (non-participant 403, guest redirect, Settled/Disputed 403), body validation, first confirmation by creator + taker, change-confirmation-multiple-times-while-Pending, both-agree → settle (creator-wins + taker-wins paths), both-disagree (Won/Won + Lost/Lost) → dispute, mid-match change flips final winner, toast-content assertions.
- [x] **3.11** `MatchSettlementTest` — 7 tests covering conservation of money (sum of all listing-related ledger entries = 0), idempotency on repeat `settle()` calls, non-participant winner rejection, BCMath precision on awkward stakes (`123.45` round-trips exactly), fee-rate config plumbing (changing `stakly.platform_fee_rate` changes the fee), status transition to `Settled` + `winner_user_id` + `settled_at`.

**Bonus additions (not in the original plan):**

- [x] **`MatchTimer` component** (`resources/js/components/match/match-timer.tsx`) — 4-hour countdown visible only while Pending. Tone shifts: neutral > 1h, warning 30m–1h, destructive < 30m (Clock icon pulses via `motion-safe:animate-pulse`), muted on expiry. Tabular-nums for steady width; `role="timer"` + dynamic `aria-label`. Frontend display only — backend Phase 7 enforces the same `match.created_at + 4h` deadline.
- [x] **`platformUser()` global helper in `tests/Pest.php`** — idempotently creates the `is_platform = true` user. Any test that exercises `Wallet::fee` (settlement here, dispute resolution in Phase 4, timeout in Phase 7) can call it without duplicating seed code. Required because `RefreshDatabase` doesn't run the production seeders.

**Test count: 259 / 1273 (was 236 / 1209 end of Phase 2 — +23 tests / +64 assertions in Phase 3).**

**Phase 4 — Dispute path + mock game-API** **(next)** (~2–3 days, no new deps)

- [ ] **4.1** `App\Services\GameApi\GameApi` interface — `getMatchResult(GameMatch $match): GameApiResult`.
- [ ] **4.2** `App\Services\GameApi\GameApiResult` value object — `winner_user_id`, `confidence` (`'confirmed'` | `'unknown'`), `raw_response` (array, for audit).
- [ ] **4.3** `App\Services\GameApi\MockGameApi` — returns a winner deterministically based on `Match::id` (so tests are reproducible). Configurable via test helpers to force "unknown" for the ManualReview branch.
- [ ] **4.4** `config/match.php` — `game_api_driver` (`'mock'` for v1). DI binding in `AppServiceProvider`.
- [ ] **4.5** `App\Services\MatchSettlement::resolveDispute(GameMatch $match)` — calls `GameApi`, settles if `confirmed`, else moves to `ManualReview`.
- [ ] **4.6** "Open dispute" button on match page (visible during `Pending` status).
- [ ] **4.7** `GameMatchController::openDispute` (POST `/matches/{match}/dispute`) — transitions match to `Disputed`, dispatches resolution synchronously (queue job in M8 when real APIs land).
- [ ] **4.8** Tests: dispute opens, mock API queried, settlement happens with API winner, ManualReview branch (UI placeholder, money stays locked, deferred resolution flow).

**Phase 5 — Settlement service** ✅ folded into Phase 3 (above) so the agree-path is end-to-end testable in one commit.

**Phase 6 — Match list page + profile + listing integration** (~1–2 days, no new deps)

- [ ] **6.1** `/matches` page — your active + past matches, status filter chips, pagination 12/page (Spatie query-builder pattern from `/listings`).
- [ ] **6.2** Profile page (`/users/{username}`): "Match history" section — replace empty state with paginated last-N matches (winner, opponent, stake, date). Public, no PII beyond what's already exposed.
- [ ] **6.3** Listing detail: when status `Taken`, show a "View match →" link visible only to participants.
- [ ] **6.4** Wallet history: payout / fee transactions render with match context (clickable listing → match navigation).
- [ ] **6.5** `BalanceChip` continues to refresh on navigation post-settlement (already works via Inertia share).
- [ ] **6.6** Tests: page renders, only your matches visible (not others'), filters work, profile match history loads.

**Phase 7 — Timeouts + edge cases + polish** (~1–2 days, no new deps)

- [ ] **7.1** `App\Console\Commands\MatchesResolveTimeouts` Artisan command + scheduled task (`->everyTenMinutes()->withoutOverlapping()` in `routes/console.php`):
  - For each `Pending` match older than 4h: if exactly one player confirmed → settle in their favor; if neither → trigger dispute (game-API).
  - Idempotent via `match-timeout:{$match->id}` reference.
  - **Deadline contract shared with frontend.** `MatchTimer` (shipped in Phase 3) already shows the 4h countdown using `match.created_at + 4h` and flips to "Expired" state when the deadline passes. Phase 7's job uses the SAME deadline calculation — its role is to actually flip the match status server-side. Until Phase 7 lands, the timer hits "Expired" but the match stays `Pending` indefinitely (no auto-resolution).
- [ ] **7.2** Inertia flash toasts: "Listing taken — match started", "Match settled — you won/lost $X", "Dispute opened, awaiting resolution".
- [ ] **7.3** Final test sweep + manual end-to-end run: create listing, take it from another account, confirm both ways (agree, disagree, timeout, dispute).
- [ ] **7.4** Suggested commit: `feat: match flow with mock game-API (M6)`.

### Out of scope for M6 (deferred)

- **Real chess.com / Lichess outcome verification** — that's M8. `MockGameApi` is the v1 implementation.
- **Manual review of disputes** — the `ManualReview` status exists but the resolution flow is shell only (admin tools deferred).
- **In-app notifications** (bell icon, inbox) — M6 uses Inertia flash toasts only.
- **Email notifications** beyond the auth flow.
- **Re-matching after a settled listing** — listings stay `Taken` forever; players can create new listings.
- **Three-way / team matches** — v2.
- **Mid-match cancellation** — once taken, the only out is settlement, dispute, or timeout.
- **Live status push (WebSocket / SSE)** — manual refresh + Inertia partial reload is fine for v1.
- **Anti-collusion / anti-cheat measures** — sandbagging (strong player on a low-rated alt account farming weaker opponents), multi-accounting, money laundering via stake rotation. Commission rake disincentivizes pure 1v1 friend collusion but doesn't cover these. Designed in a separate post-launch milestone.
- **Step-up auth on Take** (2FA / email code / Fortify `confirm-password`) for high-stake takes. Same shape as the deferred listing-creation step-up auth (M4 Post-MVP). Decision deferred — design when post-launch abuse patterns are visible.

---

## M7 — Wallet UI ✅

User-facing wallet pages on top of the M3.5 ledger. v1 mocks the chain layer — real Tron integration lands pre-launch. Four pages: `/wallet` overview (hero balance + 3 action cards + recent activity), `/wallet/deposit` (TRC20 mock address + QR + bold network warning + copy button), `/wallet/withdraw` (validating form, short-circuited POST with launch-gated info toast), `/wallet/history` (filter chips + paginated rows + smart-ellipsis pagination + empty states). `BalanceChip` in `SiteHeader` (desktop) + inline balance in `MobileMenu` close the listing-create → balance-changed feedback loop. `WalletController` + `WalletTransactionResource` (whitelist — no `reference_id`/`user_id` leak) + `WithdrawRequest` (TRC20 regex `^T[1-9A-HJ-NP-Za-km-z]{33}$`, min $10, ≤ balance, `decimal:0,2`) + `IndexHistoryRequest` (Spatie pattern). New `users.tron_address` column (varchar 34 unique) generated at registration via `App\Support\MockTronAddress`. `auth.user.usdt_balance` shared via `HandleInertiaRequests` as float. Semantic transaction colors (Deposit/Payout/Refund = success, Hold = warning, Withdrawal = destructive, Fee = muted). **193 tests / 1039 assertions (was 147/822).**

**Locked decisions:**

- **Multi-page, not tabbed** — `/wallet`, `/wallet/deposit`, `/wallet/withdraw`, `/wallet/history`. Each sub-page carries a "← Back to wallet" link; no tab strip, no sidebar.
- **Spendable balance only** in the UI — `usdt_balance` already nets out escrow holds. Held balance is derivable from the ledger if users ask.
- **Mock TRC20 addresses** (v1) via `App\Support\MockTronAddress` (`T` + 33 base58 chars, no `0`/`O`/`I`/`l`). Real Tatum-generated managed-custody addresses replace this in M9. UNIQUE constraint at DB level + collision retry in `CreateNewUser` (same `DB::transaction` savepoint pattern as username).
- **Withdrawal = Option B** — form fully validates today (so all 422 paths are exercisable), but submit short-circuits with a Sonner info toast (`"Withdrawals will be enabled at launch — your balance is safe."`) + `back()`. No ledger write. At launch the notice is removed and the worker wires up. UX testable today, zero risk of real-money desync.
- **`WalletTransactionResource` deliberate omissions** — `reference_id` (idempotency keys are internal plumbing — leaking exposes our naming convention) and `user_id` (implied by auth context for every endpoint). Tested at resource + HTTP boundary.
- **`auth.user.usdt_balance` shared via Inertia middleware** as float (same `(float) $value` boundary convention as `ListingResource`). Refreshes every navigation since auth.user is re-shared on each request — `BalanceChip` always reflects current state without polling.
- **Semantic transaction colors** in `TransactionTypeChip` (not binary credit/debit). Amber for Escrow Hold specifically communicates "paused, not gone" — important for at-a-glance reads.
- **Pagination 20/page** for history (vs 12 for listings) — transaction rows are denser.
- **`abort_if($user->is_platform, 403)`** on every wallet controller method — defense in depth on top of `auth + verified` middleware. Platform user holds the rake but should never see a wallet UI.
- **QR code via `qrcode.react`** (~16kb, lazy-loaded by Inertia code splitting to the deposit page only). Rendered on a forced-white card so phone cameras can read the dark squares against Stakly's dark theme.

**Bugs caught:**

- **`Spatie\QueryBuilder::allowedFilters()` array-vs-variadic** — installed version only accepts variadic args / single value, not arrays. Surfaced manually during Phase 6 walkthrough on `/wallet/history`. Fixed by removing the `[...]` wrap (now consistent with `ListingController`).
- **JSON int/float drift in Inertia assertions** — `(float) 750` serializes to JSON `750` and decodes back as PHP int. Strict `->where('balance', 750.0)` mismatches. Fixed by asserting the integer literal; documented inline so future tests don't repeat.
- **Invalid `VALID_TRC20` test constant masked real coverage** — hand-crafted string contained `0` (not in base58 alphabet). FormRequest validation redirected back with errors instead of the controller running. The "valid submission redirects back" test was passing **by accident** (its assertions held true on validation failure too). Fixed constant to a real-shape address; tests now actually exercise the short-circuit path.

---

## M8 — Settings / Linked Accounts

**Deferred — after M9.**

Profile settings, chess.com / Lichess account linking flow with ownership verification (UI only).

---

## Pre-launch gate — Custody + Jurisdiction (BLOCKER)

Real on-chain integration is gated by these blockers. **Do not proceed without explicit go-ahead.** Once Stakly accepts a single real deposit, it's operating a regulated money-handling business and the engineering becomes hard to unwind.

Required answers before mainnet wiring:
1. **Custody model committed**: TBD — to be decided with the crypto-payment-gateway specialist who will own M9. Internal ledger architecture (M3.5) is provider-agnostic and supports any custody model.
2. **Jurisdiction committed**: where Stakly is registered + license path (e.g., Curaçao sublicense, Malta MGA, US state-by-state map, or testnet-only / fake-money for the foreseeable future).
3. **Chain + provider committed**: TRC20 (Tron USDT) chain remains the v1 commitment. Provider (Tatum / Fireblocks / BitGo / Coinbase Developer Platform / DIY) and node strategy TBD with the specialist.
4. **Key storage in production**: TBD — depends on the custody model decision (#1). Likely candidates: AWS Secrets Manager, AWS KMS, HashiCorp Vault, vendor-held, or a hardware device (Ledger / Trezor) for any cold-wallet portion.
5. **Incident response plan**: hot-wallet compromise procedure, user notification template, insurance (if any).
6. **Terms of Service + dispute resolution** policy drafted.
7. **KYC/AML** required? If yes, integration with which provider, threshold that triggers it.

> No traditional banking / payment processor in scope — Stakly is **crypto-end-to-end** (USDT deposits, USDT withdrawals, USDT-denominated platform revenue). The only fiat touchpoint is the operating company's own expenses (taxes, legal), which is part of the jurisdiction decision (#2), not a user-facing gate.

### Admin tooling (planned for pre-launch)

- **Filament for the admin panel.** Install when admin needs become real (first ManualReview match requiring human resolution, first dispute needing a manual refund, first moderation case) — likely one milestone before launch. First-class Spatie roles + permissions integration; Spatie is already installed. Trade-off accepted: admin lives at `/admin/*` on Livewire + Alpine + Filament's Tailwind config, separate from the Inertia + React user app. Admin uses Filament's defaults — does not share Stakly's pink/purple design system.

### App-level hardening (deferred from dev)

Small code-level cleanups noticed during M3–M4 development. Not blocking until we're approaching a real deployment, but they must land before the first non-developer touches the platform.

- **Platform user credentials.** Seeder currently creates `platform@stakly.internal` via the default `UserFactory`, which means `Hash::make('password')` + `email_verified_at = now()`. Pre-launch: override the seeder to use an unguessable random password (e.g. `Hash::make(bin2hex(random_bytes(32)))`) and set `email_verified_at = null`. Add a `Fortify::authenticateUsing(...)` hook in `FortifyServiceProvider` that explicitly rejects any user where `is_platform = true` — defense in depth against future code paths that might re-grant the password. When M5 ships, `UserController::show` also needs to 404 on `is_platform = true` users so the platform account isn't enumerated alongside real players.
- **Marquee copy.** `resources/js/layouts/site-layout.tsx` `defaultMarqueeItems` currently advertises a `STAKLY30 30% off` promo and other aspirational claims that don't reflect reality. Replace with honest copy (or move to per-page overrides) before any user-facing surface.
