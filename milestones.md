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
- **M6** — Match Flow (mock) ✅
- **M11** — Controller Refactor to Actions Pattern ✅
- **M8** — Settings / Linked Accounts (chess.com / Lichess) [deferred — natural next]
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

---

## Post-MVP — Listings polish (deferred, not in M4)

Captured so the intent isn't lost. **Don't pull these into M4.** Each is a real user-facing improvement but adds scope (state machine, UX flow, or step-up auth) that doesn't earn its complexity until we see real usage.

- **Step-up auth at listing creation.** Email-verified is already enforced via middleware. *All-listings* 2FA = friction that trains users to dismiss prompts. Better: step-up only for **high-stake** listings (e.g., `stake_amount > $500`) via Fortify's `confirm-password` (already plumbed for settings). Optionally also step-up on suspicious signals (new device fingerprint, rapid-fire creates). Decision deferred until post-launch when actual abuse patterns are visible.

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

## M11 — Controller Refactor to Actions Pattern ✅ shipped 2026-05-17

Plain PHP Actions, no package, no Repositories. Business logic moved from controllers + artisan commands into `app/Actions/<Domain>/` classes with `handle()` methods; controllers + commands shrink to thin HTTP/CLI adapters that delegate via method-injection.

**Shipped:**

- `app/Actions/Listing/`: `CreateListingAction`, `CancelListingAction`, `ExpireListingAction`.
- `app/Actions/GameMatch/`: `TakeListingAction`, `ConfirmOutcomeAction`, `OpenDisputeAction`, `SettleMatchAction`, `SettleDrawMatchAction`, `ResolveDisputeAction`, `ResolveMatchTimeoutAction`.
- `ListingController::store` + `cancel` delegate to `CreateListingAction` / `CancelListingAction` (~10 lines each, was ~30).
- `GameMatchController::take` / `confirm` / `openDispute` delegate to their corresponding Actions; `resolveBothConfirmed` and `postDisputeResolutionSentinel` helpers moved into `ConfirmOutcomeAction` / `OpenDisputeAction`.
- `MatchesResolveTimeouts` + `ExpireListings` artisan commands now own only the iteration loop + per-item try/catch + summary print; the per-item resolution logic lives in the matching Actions.
- Old `App\Services\MatchSettlement` deleted.
- All 366 / 1969 tests still pass — existing controller-level tests didn't need changes (the routes still behave the same); `MatchSettlementTest` updated to call `app(SettleMatchAction::class)->handle(...)` etc.
- `CLAUDE.md` "Application Structure & Architecture" gained an "Actions pattern" subsection documenting the convention so future-me / future-AI doesn't re-introduce business logic in controllers.

### Locked design decisions (2026-05-16)

- **No package.** Plain PHP classes. `lorisleiva/laravel-actions`'s value-add (use-as-controller / use-as-job traits) isn't load-bearing until M8's queued jobs, and even then `app(SomeAction::class)->handle(...)` is one line either way.
- **No Repositories.** Eloquent IS the repository — queries live on models / scopes / `with(...)` calls. A Repository layer would be indirection without payoff.
- **Method name: `handle()`** — matches Laravel queue job convention.
- **Invocation: container-resolved via constructor injection.** Controllers / commands method-inject the action: `public function take(TakeRequest $request, Listing $listing, TakeListingAction $action) { return $action->handle($request->user(), $listing); }`. Makes deps explicit; tests can swap via `app()->bind(...)`.
- **Wallet + GameApi stay as primitives.** `App\Services\Wallet` is the single-source-of-truth ledger writer — splitting into N tiny `DepositAction` / `HoldAction` / `PayoutAction` classes would lose the invariant enforced by `WalletTest.php`. `App\Services\GameApi\*` are external-API adapters, not use-case actions.
- **Pure queries (read-only index / show controller methods) stay in controllers** — they don't earn the indirection. Configuration-heavy (Spatie QueryBuilder `allowedFilters` / `allowedSorts`) is HTTP-layer config, not business logic.
- **Decompose long `handle()` bodies into private helpers.** `handle()` should read like a recipe of high-level steps (`assertX`, `computeY`, `markZ`); the implementation details sit one level down. Applied across all the bigger Actions (SettleMatch, ResolveDispute, ConfirmOutcome, TakeListing, ResolveMatchTimeout).

### Out of scope for M11 (deferred)

- **11.6 — `WalletController` refactor.** Most methods are read-only or short. Only `withdrawStore` has logic worth extracting, and it's currently a no-op short-circuit. Defer until the withdraw flow becomes real (post-M9).
- DTOs / Value Objects per Action input. Current pattern (typed positional arguments) is fine.
- Adopting `lorisleiva/laravel-actions` package. If the manual pattern proves insufficient (e.g. same controller-to-action plumbing 10× over), revisit.

---

## M6 — Match Flow (mock) ✅

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

`game_matches` migration with 1:1 UNIQUE FK on `listing_id`. `MatchStatus` enum (Pending / Disputed / Settled / ManualReview). `MatchOutcome` enum (Won / Lost — `Drawn` added in Phase 6.7). `App\Models\GameMatch` (named to avoid PHP's reserved `Match`) + factory + relations (`listing`, `taker`, `winner`, `disputeOpener`). `GameMatchPolicy` auto-discovered with `view` / `confirm` / `openDispute` (participant-only + status-gated). 24 tests.

**Phase 2 — Take listing → create match** ✅ shipped

Take CTA wired on `/listings/{id}` with confirmation Dialog. `GameMatchController::take` is row-locked + handles three failure modes (self-take 403, race-lost → friendly redirect + info toast, insufficient-balance race → 422 keyed on `amount`). `TakeRequest` does balance pre-check. Route `POST /listings/{listing}/take` (auth + verified). Also lifted the match show page early (`GET /matches/{match}`, `GameMatchResource` PII whitelist, opponent card + stake / pot / time-control stats) so Phase 3 layered confirm + polling on top instead of rebuilding. 19 tests.

**Phase 3 — Confirm UI + settlement + polling** ✅ shipped (folds in Phase 5 settlement service)

`config/stakly.php` `platform_fee_rate` (`'0.10'` default, BCMath string). `App\Services\MatchSettlement::settle($match, $winner)` — row-locked, idempotent on `match-payout:{id}` / `match-fee:{id}` refs, flips status → Settled. `GameMatchController::confirm` is race-safe via `lockForUpdate` and distinguishes 5 resolution sentinels (`recorded` / `no-change` / `settled` / `disputed` / `too-late`) each mapped to its own flash toast. Frontend: `ConfirmButtons`, `SettlementSummary` (winner badge + pot/fee/payout breakdown), `MatchTimer` (4h countdown with tone shifts under 1h / 30m). Inertia v3 polling `router.reload({ only: ['match'] })` every 8s while Pending. `platformUser()` Pest helper. 23 new tests. Total 259 / 1273.

**"Change freely until opponent confirms" rule** (locked 2026-05-15): a player can change their confirmation any number of times while the match is still `Pending`. Once both confirm, status flips to non-Pending and `GameMatchPolicy::confirm` blocks further changes. **The lock is implicit via match status — no `confirmation_locked` columns needed.** Misclicks are recoverable until the opponent commits.

**Phase 4 — Dispute path + mock game-API** ✅ shipped 2026-05-16 (~half day, no new deps)

`App\Services\GameApi\GameApi` interface + `GameApiResult` value object + `MockGameApi` (deterministic winner by `match.id` parity; `forceWinner` / `forceUnknown` / `forceDraw` test helpers — `forceDraw` added in Phase 6.7). Singleton-bound so forced state persists across a request. Driver bound via `config('stakly.game_api_driver')` in `AppServiceProvider`. `MatchSettlement::resolveDispute(GameMatch $match)` re-fetches under lock, persists `api_response` + `api_resolved_at` for audit, branches on `confidence` (Confirmed → `settle`, Unknown → ManualReview, Drawn → `settleDraw` [added 6.7]). `GameMatchController::openDispute` route + `OpenDisputeButton` UI. Both auto-dispute (mid-confirm conflict) and openDispute paths call `resolveDispute` outside the transaction (flat structure, no nested savepoints). `mockGameApi()` Pest helper. Migration added `api_response` jsonb + `api_resolved_at` to `game_matches`. 18 new tests. Total 277 / 1345.

**ManualReview placeholder tracker.** With the mock driver, production matches will never land in ManualReview unless forced in tests. When M8 real adapters land and the first ManualReview match hits prod (game not found / ambiguous / abandoned), the next deliverable is **admin resolution UI**: list ManualReview matches, let admin settle to a winner or refund, write through `MatchSettlement::settle` or a new `refundDispute` method. Tracked here because it's tightly coupled to M8 going live.

**Phase 5 — Settlement service** — folded into Phase 3 (above) so the agree-path moves real money end-to-end in one commit.

**Phase 6 — Match list + profile + listing integration** ✅ shipped 2026-05-16

`/matches` page with status filter chips + pagination (Spatie pattern). Profile "Match history" section (paginated last-N settled matches; Pending/Disputed omitted as privacy/authoritative concerns). Listing detail shows "View match →" link to participants only when status `Taken`. Wallet history renders payout / fee transactions with match context. `BalanceChip` refreshes via Inertia share on every navigation.

Per-listing pause/resume shipped here as part of Phase 6 (`ListingStatus::Paused`, `ListingPolicy::pause` / `resume`, soft-pause via status flip with escrow held, UI + 22 tests) and **then removed wholesale in Phase 6.5** the next day after design review — global Active Mode does the same job at the user level for less surface area. Net result of Phase 6 + 6.5: 324 / 1648 tests. History of why we tried per-listing first lives in git.

---

## Phase 6.5 — Listings management UI + design polish **(shipped 2026-05-16)** (~2 days, no new deps)

Filed 2026-05-16 after design review against Bybit's P2P management UX. Filed separately from Phase 6 so it could be reverted independently if the design direction changed.

**Shipped:** `/matches` table-style redesign (single container card, column header, calm `bg-primary/5` row-tint hover). New `/listings/mine` management dashboard with Listed / All Ads tabs + Active Mode toggle in the page header + max-2-listings cap enforced on `StoreListingRequest`. "More" dropdown on `/listings` (Post listing / My listings / Match history). Global Active Mode replaces per-listing pause/resume wholesale (rationale in locked decisions). 27 new tests. Polish pass: `DropdownMenuItem` + `Button` focus-ring Stakly-skinned at source, ProfileMenu dropdown restructured to match auth-modal glow pattern, click-through fix on listing rows (pointer-events-none on content cells), new users default to Inactive Mode, listing-create + cancel redirect to `/listings/mine`, toast position moved to `bottom-right`.

### Locked decisions (2026-05-16)

- **Per-listing pause/resume removed wholesale in 6.5.** The Phase 6 model (Paused enum case + per-listing toggle + Resume dialog) was undone after design review: global Active Mode does the same job at the user level for less surface area. Files deleted: `ResumeListingDialog`, `ListingPauseResumeTest` (22 tests). Routes removed: `POST /listings/{listing}/pause` + `/resume`. `ListingStatus` enum trimmed back to 4 states (Open / Taken / Expired / Cancelled). The asymmetric-risk dialog that originally lived on Resume now lives on the Active Mode toggle.
- **Active Mode is the asymmetric-risk surface.** Inactive → Active opens a confirmation dialog ("Your listings will reappear on the marketplace immediately. An opponent could take one within seconds…"). Active → Inactive is a direct action with a success toast (safe direction — just hides).
- **Keep both surfaces for managing listings.** `/listings/mine` is the dedicated management dashboard (table view, Active/All tabs, all actions). The profile's "Active listings" section stays — it serves a different audience (opponents browsing your shopfront, plus you-when-on-your-own-profile). The inline pause/resume icon on profile cards also stays — it's a one-click convenience, routed through the same Resume dialog as `/listings/mine` for consistency.
- **`Create listing` button stays in the global `SiteHeader`.** It's the primary CTA for the entire app — especially the funnel for logged-out users who'll land on the homepage / listings index and need an obvious sign-up + post hook. The `Tools` dropdown on `/listings` contains a secondary "Post listing" entry for one-click access; the dropdown does not replace the global button.
- **Tools dropdown is page-local, not global.** Lives in the `/listings` page header only. Other pages (homepage, profile, wallet, etc.) don't get it — those audiences aren't the management audience. Adding it everywhere would clutter the nav.
- **Global Active Mode is the only visibility control (reversed twice on 2026-05-16).** Bybit-style toggle, single home on `/listings/mine` page header. Inactive hides listings from both the marketplace AND public profile (visitor sees nothing); reflects the "I'm stepping away" intent end-to-end. Per-listing pause/resume from Phase 6 was removed wholesale once we realized this toggle does the same job at the user level.
- **Max 2 active listings per user.** Enforced at create time on `StoreListingRequest`, counting listings in {Open, Paused} state. Taken / Expired / Cancelled don't count. Conservative cap for launch; revisit when usage data shows whether 3+ is needed for chess players who want one listing per format (Blitz / Rapid / Classical). The original M4 Post-MVP note suggesting cap = 5 is superseded by this 2 cap for v1 — bump later if friction shows up.

### Out of scope for 6.5 (deferred)

- Bulk actions (pause-all, resume-all, cancel-all). One-listing-at-a-time covers the v1 user with 1–5 listings.
- Edit a listing (change stake, change skill range, etc.). Cancel + recreate remains the v1 model.
- Search within `/listings/mine`. Pagination at 12/page is enough at this scale.
- Filter chips on `/listings/mine` beyond the Active/All tabs.
- A separate `/listings/mine` empty state hero. Standard empty state with a "Post listing" CTA is fine.

### Post-6.5 hotfix shipped same day (2026-05-16)

- [x] **Active Mode must gate match Take, not just visibility.** Bug found after 6.5 close-out: a taker who already had the listing detail page loaded (or knew the direct URL) could POST `/take` and start a match even after the owner went Inactive. The `scopeOnPublicMarketplace` filter only hid listings on browse surfaces — the mutation endpoint had no Active Mode check. Fix in `GameMatchController::take`: inside the existing locked transaction, `lockForUpdate` SELECT on `users.is_active_mode` (serializes against concurrent `ActiveModeController` toggles); if false, return a new `'owner_inactive'` sentinel that the controller translates to an info toast ("This player is currently inactive. Their listings are temporarily unavailable.") + redirect back to listing detail. Frontend defense-in-depth: `ListingResource.creator.is_active_mode` exposed (PII-safe — already inferrable from marketplace visibility); listing detail page disables Take button + shows "Player currently inactive" with a "Browse other listings →" link when owner is inactive. Two new tests in `GameMatchTakeTest`: inactive-owner blocks the take; reactivation between page-load and Take lets it succeed. **Three-layer enforcement now:** (1) `Listing::scopeOnPublicMarketplace` (visibility), (2) `GameMatchController::take` (mutation, authoritative), (3) listing detail frontend gate (UX). Future "consume listing" paths (e.g. private-challenge if ever added) must mirror this gate.

---

## Phase 6.6 — Player Hub Layout **(shipped 2026-05-16)** (~half a day, no new deps)

Filed and shipped same-day as a UX follow-on. Bybit-style scoped sidebar pattern — only on management pages, not site-wide.

**Shipped:** `PlayerHubLayout` wraps `SiteLayout` and adds a sticky left `PlayerSidebar` on `/listings/mine`, `/matches`, `/wallet` (+ wallet sub-pages). Public pages keep plain `SiteLayout`. Sidebar has 3 items (My listings / Matches / Wallet), pink left accent bar on active, collapsible to icon-rail mode with Radix Tooltip on hover. Preference persists in `localStorage` (`stakly:player-sidebar:collapsed`). Mobile: sidebar hidden (existing hamburger menu covers nav). Smart `BackLink` component replaces 5 hardcoded "Back to X" links — calls `window.history.back()` when there's referrer history, otherwise falls through to Inertia `<Link>` to a `fallback` URL. Modifier-clicks bypass for "Open in new tab" semantics.

### Locked decisions (2026-05-16)

- **Scoped sidebar, not site-wide.** Mirrors Bybit's pattern — their P2P section has the sidebar, the rest of the site doesn't. Public pages stay full-width: marketing audience there isn't a management audience.
- **"Matches" stays "Matches" (not "Orders").** Bybit-style "Orders" semantic was discussed and deferred. `Matches` page already has filter chips (All / Pending / Disputed / Settled) which covers the same need. Can split into "Orders" (Pending) + "Match history" (Settled) later if usage data shows the split is useful.
- **Sidebar items locked at 3 for v1.** My listings, Matches, Wallet. Settings would be the next natural addition (when a settings page is built). No KYC / Disputes / Orders items yet — they don't exist as routes.
- **Mobile = no sidebar.** Considered (a) icon rail at top of page, (b) sheet/drawer from hamburger, (c) hide entirely. Picked (c) because the hamburger menu already lists all three management surfaces; adding a second mobile nav pattern would duplicate without benefit. Revisit only if mobile management UX feels cramped after launch.

### Out of scope for 6.6 (deferred)

- "Orders" sub-section (active matches vs match history split).
- Settings, KYC, Disputes sidebar items (not enough surfaces to justify yet).
- Sidebar item badges (e.g. unread match count, pending dispute count) — nice-to-have, not v1.

---

## Phase 6.7 — Drawn outcome support **(shipped 2026-05-17)** (~half a day, no new deps)

Real chess games can end in draws (stalemate, threefold repetition, etc.). The original `MatchOutcome` enum was `Won | Lost` only — would have left M8's real adapters with no handler for draw API responses. Pulled forward from M8 prereq so the state machine is complete before Phase 7's timeout job builds on top of it.

**Shipped:** `MatchOutcome::Drawn` + `GameApiConfidence::Drawn` enum cases. New `MatchSettlement::settleDraw(GameMatch $match)` — row-locked, idempotent on `Settled`, refunds both stakes via `Wallet::release` (refs `match-draw-creator:{id}` / `match-draw-taker:{id}`), `winner_user_id` stays null. `GameMatchController::resolveBothConfirmed` rewritten with explicit branches (both-Drawn → settleDraw; mirror Won/Lost → settle; everything else → dispute). `resolveDispute` handles `confidence === Drawn`. `postDisputeResolutionSentinel` distinguishes `'settled-by-api'` (winner emerged) from `'settled-by-api-draw'` (refund-both) by checking `winner_user_id === null`. `MockGameApi::forceDraw()` helper. Frontend: third "Draw" button (Handshake icon) in `ConfirmButtons` (2-col → 3-col), draw branch in `SettlementSummary` (no fee row, "Match drawn" header), Draw chips in `MatchListRow` + `ProfileMatchRow`. 11 new tests.

### Locked decisions

- **Refund both, no platform fee.** Draws are refund-only — not revenue events. Conservation still holds across the four-party flow.
- **Separate `settleDraw()` method, not a `settle($winner = null)` overload.** Semantics differ enough that conflating muddies both — `settle` pays a winner + fees the platform; `settleDraw` refunds both, no fee, no winner. Same row-lock + idempotency pattern.
- **`GameApiConfidence` gains `Drawn` (option a), not `GameApiResult.is_draw` (option b).** Single source of truth; three result types in one enum. Enum semantically becomes "result type" rather than strict "confidence" but the rename isn't worth the churn.
- **Disagreement involving Drawn → dispute, not auto-settle.** If one player says `Won` and the other says `Drawn`, that's a real disagreement — the API arbitrates. We don't pick sides.
- **Sentinel split for API-resolved draws.** `'settled-by-api'` (winner emerged) and `'settled-by-api-draw'` (refund-both) carry different toast copy. Distinguished by `winner_user_id === null` after `resolveDispute`.

### Out of scope for 6.7 (deferred)

- Rake on draws (stays at zero — refund-only, no revenue event).
- Draw-by-agreement *before* the game is played (separate "mutual cancel" feature — not in M6).
- Per-game-type draw rules (Lichess's draw conditions differ slightly from chess.com's — handle when real adapters land in M8).

---

**Phase 7 — Timeouts + edge cases + polish** ✅ shipped 2026-05-17 (~1 day, no new deps)

**Status guard** (`MatchSettlement::settle` + `settleDraw`): no-op on `Settled` (idempotency), proceed on `Pending` / `Disputed`, throw on `ManualReview` or any unexpected status. Defense in depth so future admin tools resolving `ManualReview` can't silently piggyback on regular `settle`.

**Timeout resolver** (`App\Console\Commands\MatchesResolveTimeouts`) — scheduled `everyTenMinutes()->withoutOverlapping()`. For each Pending match older than `stakly.match_confirmation_timeout_hours` (default 4h): single `Won` → confirmer wins; single `Lost` → opponent wins (claim honored); single `Drawn` → game-API arbitrates (one-sided draw can't unilaterally declare); neither confirmed → game-API arbitrates; both confirmed but still `Pending` → defensive log + skip (anomaly the synchronous resolver should have caught). Iterates via `chunkById(100)`, per-match `try/catch` + `report()`, row-locked transaction with status re-check. `resolveDispute` runs outside the transaction (mirrors `GameMatchController::confirm` pattern). Idempotency at the match-status level + via existing wallet reference IDs (no new `match-timeout:{id}` key — would be ceremony, the status guard already covers replay safety). 15 new tests.

**Follow-up — dispute button visibility** (committed separately): frontend gate on `OpenDisputeButton` widened from "viewer has claimed" to "either player has claimed." Closes a UX gap where the viewer couldn't push back on a bad-faith claim from the opponent without first committing to one of their own. Backend was already permissive; this is a one-line conditional change in `match/show.tsx`.

**Dev caveat:** Laravel's scheduler does NOT auto-run in dev. Fire `matches:resolve-timeouts` manually, or run `sail artisan schedule:work` in a separate terminal. Same constraint as `listings:expire`.

### Locked decisions (2026-05-17)

- **Single-confirmer rule: honor the claim, don't reward voting.** "I won" + silent → confirmer wins. "I lost" + silent → *opponent* wins (the confirmer told us the opponent won, we honor it). "Draw" + silent → game-API arbitrates (one-sided draw claim can't unilaterally declare a draw). The original spec said "settle in their favor" which was ambiguous about the Lost case — clarified during Phase 7 design.
- **Platform does NOT pocket stakes on no-show.** Considered: if neither player confirms in 4h, the platform takes both stakes. Rejected — picture a player whose internet died for the 4h window. Pocketing their stake creates "Stakly scammed me" complaints for legitimate no-shows. Platform earns money on real settlements (10% fee), not from bad timing.
- **No auto-refund on `GameApi` returning `Unknown`.** Match stays in `ManualReview`, money locked in escrow, admin reviews via the Filament panel (pre-launch). Auto-refund was rejected because a losing player with a linked chess.com account could deliberately not play (API finds nothing → Unknown), claim "Draw," ghost confirmation, recover their stake. Already locked in M6; re-confirmed during Phase 7 design.
- **No admin in player match flows.** Considered: admin/support joining match chats, sending messages, observing in-flight matches. Rejected for v1 because it shifts the operational model from "automate everything, humans only on edge cases" to "humans-in-the-loop." A solo-dev project can't scale to per-match human attention. Post-MVP alternative: a "Request review" button on `ManualReview` matches lets players submit notes/screenshots → admin reads them via the Filament dashboard → decides out-of-band. Same human-makes-the-call outcome without live chat to babysit.

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

---

## M8 — Settings / Linked Accounts

**Deferred — after M9.**

Profile settings, chess.com / Lichess account linking flow with ownership verification (UI only).

> **Drawn outcome support pulled forward to M6 Phase 6.7** (planned). When M8 picks up real adapters, the settlement code already handles draws — adapters just need to map their draw responses to `GameApiConfidence::Drawn`. Design + scope live in Phase 6.7 above.

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
