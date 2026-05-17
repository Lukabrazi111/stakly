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

## M11 — Controller Refactor to Actions Pattern

**Scheduled after M6 ships, before M8 (real chess.com / Lichess) lands.** Pure Actions pattern — no package (`lorisleiva/laravel-actions` not adopted), no Repositories. Just plain PHP classes organized by domain under `app/Actions/<Domain>/`.

### Motivation

Controllers (`GameMatchController`, `WalletController`, `ListingController`) have grown into business-logic-holders rather than thin dispatchers. `GameMatchController::confirm` alone owns: route binding, FormRequest validation, policy gate, transaction, race-check, sentinel mapping, post-commit dispute resolution, toast flashing. That's five concerns in one method. The split is overdue and gets worse with M8 — when chess.com / Lichess calls become queued jobs, we'd otherwise duplicate the dispute-resolution logic across controller and job. One Action class callable from both surfaces fixes this.

### Locked design decisions (2026-05-16)

- **No package.** Plain PHP classes. The package's main value-add (use-as-controller / use-as-job / use-as-command via traits) isn't load-bearing for Stakly until M8 introduces queued jobs — and even then, calling `app(SomeAction::class)->handle(...)` from both contexts is one line either way.
- **No Repositories.** Eloquent IS the repository in this codebase (queries live on models / scopes / `with(...)` calls). Adding a Repository layer would just be indirection without payoff.
- **Method name: `handle()`** — matches Laravel queue job convention, least cognitive load.
- **Invocation: container-resolved via constructor injection.** Controllers method-inject the action: `public function take(TakeRequest $request, Listing $listing, TakeListingAction $action) { return $action->handle($request->user(), $listing); }`. Makes deps explicit and tests can swap via `app()->bind(TakeListingAction::class, ...)`.
- **Wallet stays as a primitive at `App\Services\Wallet`.** It's not an Action — it's the ledger writer that Actions compose. Splitting it into N tiny `DepositAction` / `HoldAction` / `PayoutAction` classes would lose the "single source of money writes" invariant enforced by `WalletTest.php` (`balance == SUM(transactions)`). Same reasoning keeps `App\Services\GameApi\*` as primitives — they're external-API adapters, not use-case actions.
- **`MatchSettlement` becomes Actions.** Its two static methods (`settle`, `resolveDispute`) are use-case-shaped — they compose Wallet primitives into business operations. Becomes `SettleMatchAction` and `ResolveDisputeAction` under `app/Actions/GameMatch/`.

### Scope

- [ ] **11.1** Create `app/Actions/<Domain>/` directories: `Listing/`, `GameMatch/`, `Wallet/` (the Wallet/ folder is for wallet-flow actions like `RecordDepositAction` once M9 chain integration lands — not for the existing `Wallet` primitive).
- [ ] **11.2** Extract from `ListingController`: `CreateListingAction`, `CancelListingAction`. Leave `index` / `create` / `show` in the controller (they're read-only thin wrappers).
- [ ] **11.3** Extract from `GameMatchController`: `TakeListingAction`, `ConfirmOutcomeAction`, `OpenDisputeAction`. Each owns the transaction + race-check + sentinel resolution that currently lives in the controller. Controller methods shrink to 3-5 lines.
- [ ] **11.4** Convert `MatchSettlement::settle` → `SettleMatchAction::handle`, `MatchSettlement::resolveDispute` → `ResolveDisputeAction::handle`. Delete the old `MatchSettlement` shell.
- [ ] **11.5** Extract from `App\Console\Commands\ListingsExpire`: `ExpireListingsAction` (the artisan command's body becomes a single Action call). Same refactor for any other current artisan commands.
- [ ] **11.6** Wallet UI controllers (`WalletController`) — most methods are read-only or short. Only `withdrawStore` has logic worth extracting (and it's currently a no-op short-circuit). Defer this controller's refactor to whenever the withdraw flow becomes real (post-M9).
- [ ] **11.7** Update existing tests — most should keep working unchanged (they hit the controller routes). Add a few service-level tests directly on Action classes for the more complex ones (`ConfirmOutcomeAction`, `ResolveDisputeAction`).
- [ ] **11.8** Update CLAUDE.md "Application Structure & Architecture" section to document the convention, so future-me / future-AI doesn't re-introduce business logic in controllers.

### Why "after M6, before M8" specifically

- **After M6** because refactoring code we're still actively writing means re-doing the same extract twice. M6 Phase 6 (match list + integrations) and Phase 7 (timeouts + polish) are still adding controller methods — let those settle first.
- **Before M8** because M8 introduces real chess.com / Lichess API calls that will be queued jobs. With Actions in place, the same `ResolveDisputeAction` runs from both the controller (manual `openDispute`) and the queued job — no duplication. Doing M11 after M8 means writing the duplication first then deleting it.

### Out of scope for M11 (defer)

- DTOs / Value Objects per Action input. Current pattern (typed positional arguments) is fine.
- `Repository` layer. Eloquent is the repository.
- Adopting `lorisleiva/laravel-actions` package. If the manual pattern proves insufficient (e.g. we end up writing the same controller-to-action plumbing 10 times), revisit.

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

**Phase 4 — Dispute path + mock game-API** ✅ **shipped 2026-05-16** (~half day, no new deps)

- [x] **4.1** `App\Services\GameApi\GameApi` interface — `getMatchResult(GameMatch $match): GameApiResult`.
- [x] **4.2** `App\Services\GameApi\GameApiResult` readonly value object — `winner_user_id`, `confidence` (`App\Enums\GameApiConfidence` enum: `Confirmed` | `Unknown`), `raw_response` (array, persisted to `game_matches.api_response` for audit).
- [x] **4.3** `App\Services\GameApi\MockGameApi` — winner picked deterministically by `match.id` parity (even=creator, odd=taker). Test helpers `forceWinner(int)`, `forceUnknown()`, `reset()` for targeted scenarios. Singleton-bound so forced state persists across the request.
- [x] **4.4** Config goes in `config/stakly.php` under `game_api_driver` (not a separate `config/match.php` — less file proliferation, matches existing `platform_fee_rate` location). DI binding in `AppServiceProvider::register` resolves the driver via `match` expression and throws on unknown values.
- [x] **4.5** `MatchSettlement::resolveDispute(GameMatch $match)` — re-fetches under lock, idempotent on terminal states (Settled, ManualReview), throws if not Disputed. Persists `api_response` + `api_resolved_at` before branching so audit survives even if settlement throws. Confirmed → delegates to `settle()` (savepoints handle nesting). Unknown → flips to ManualReview, money stays locked.
- [x] **4.6** "Open dispute" inline link on match page (`OpenDisputeButton` component) — gated on `myConfirmedOutcome !== null`, so the player must make their own claim first. Subordinate styling (small text + alert icon) keeps the cooperative path primary. Confirmation Dialog warns that API result is final and money moves immediately.
- [x] **4.7** `GameMatchController::openDispute` (POST `/matches/{match}/dispute`, named `matches.openDispute`) — auth-gated, lockForUpdate transition Pending → Disputed (records `dispute_opened_by`), then calls `resolveDispute` outside the transaction. Same `postDisputeResolutionSentinel` helper as the auto-dispute path so toasts stay consistent.
- [x] **4.8** Auto-dispute path also wired to immediate API resolution — when both players make conflicting claims via `confirm()`, controller flips to Disputed inside the transaction, then calls `resolveDispute` after commit. Same flat structure as openDispute (no nested transactions).
- [x] **4.9** Migration: added `api_response` jsonb + `api_resolved_at` timestamptz to `game_matches`. Edited the existing migration in place (pre-launch rule).
- [x] **4.10** Tests: 18 new (5 in `MatchSettlementTest` for `resolveDispute`, 10 in `GameMatchOpenDisputeTest` for the manual route, 3 updated + new in `GameMatchConfirmTest` for the auto-dispute flow). Covers Confirmed/Unknown branches, idempotency on terminal states, sanity guard (Pending throws), BCMath precision through API path, race-safety, toast assertions.
- [x] **4.11** `mockGameApi()` global helper in `tests/Pest.php` — resolves the singleton, asserts driver type, calls `reset()` to clear forced state from prior tests in the same process.

**Bonus additions:**
- Updated dispute placeholder banner copy on match page (was "ships in next phase" — now describes async resolution for the M8 case).
- Added two new toast sentinels (`settled-by-api`, `manual-review`) to `confirmRedirect` so the confirm-then-disagree flow can flash the right post-resolution message.

**ManualReview placeholder tracker.** With the mock driver, no production match will ever land in ManualReview (the mock only returns `Unknown` when forced in tests). When real chess.com / Lichess adapters land in M8 and the first ManualReview match hits prod (game not found, ambiguous, abandoned), the next deliverable is **admin resolution UI**: list ManualReview matches, let admin pick a winner or refund, write through `MatchSettlement::settle` or a new `refundDispute` method. Tracked here rather than as a separate milestone since it's tightly coupled to M8 going live with a real API.

**277 tests / 1345 assertions (was 259 / 1273 end of Phase 3 — +18 tests / +72 assertions).**

**Phase 5 — Settlement service** ✅ folded into Phase 3 so the agree-path is end-to-end testable in one commit.

**Phase 6 — Match list + profile + listing integration + pause/resume** ✅ **shipped 2026-05-16**

- [x] **6.1** `/matches` page — your active + past matches, status filter chips, pagination 12/page (Spatie query-builder pattern from `/listings`).
- [x] **6.2** Profile page (`/users/{username}`): "Match history" section — replace empty state with paginated last-N matches (winner, opponent, stake, date). Public, no PII beyond what's already exposed.
- [x] **6.3** Listing detail: when status `Taken`, show a "View match →" link visible only to participants.
- [x] **6.4** Wallet history: payout / fee transactions render with match context (clickable listing → match navigation).
- [x] **6.5** `BalanceChip` continues to refresh on navigation post-settlement (already works via Inertia share).
- [x] **6.6** Tests: page renders, only your matches visible (not others'), filters work, profile match history loads.

**Pause/resume listings (added 2026-05-16, pulled from Post-MVP "Listings polish"):**

- [x] **6.7** Backend: `ListingStatus::Paused` enum case, `scopeOpen` excludes it. `ListingPolicy::pause` / `resume` (creator-only, status-gated). `ListingPolicy::cancel` extends to allow Paused. `POST /listings/{listing}/pause` + `resume` controller methods — row-locked atomic status flip, NO wallet ops (soft pause = escrow stays held). `ExpireListings` artisan handles Paused → Expired the same as Open → Expired.
- [x] **6.8** Frontend: Pause / Resume button next to Cancel on listing detail (owner view; outline when Open, gradient when Paused). Status badge gains amber "Paused" tone. Inline pause / resume icon on own-profile listing cards. Toast on success, no dialog (reversible action). Loading state during async (`Pausing…` / `Resuming…`).
- [x] **6.9** Tests: pause / resume happy paths, owner-only enforcement, can't-pause-Taken/Expired/Cancelled, paused listings hidden from `/listings` index, can't-take-paused-via-direct-link, cancel-from-paused refunds correctly, `ExpireListings` handles Paused, race-safety on concurrent pause + take / pause + cancel.

**Pause/resume locked decisions (2026-05-16):**

- **Soft pause** — escrow stays held during pause; resume is a status flip only. Hard pause (release on pause, re-hold on resume) was rejected: extra wallet churn + resume can fail with `InsufficientBalanceException` if the balance was spent meanwhile.
- **Global Active Mode replaces per-listing pause (revised twice; final state 2026-05-16 in Phase 6.5)** — initial v1 plan was per-listing only. First reversal: added global Active Mode as an *additive overlay* on top of per-listing pause. Second reversal (same day, after seeing it in practice): per-listing pause/resume removed wholesale. Global Active Mode is now the *only* visibility control. Listings live in `Open` status the whole time; the user's `is_active_mode` flag gates whether they appear on public surfaces. Simpler mental model: "I'm online" or "I'm offline."
- **Cancel-from-paused allowed** — `ListingPolicy::cancel` accepts both Open and Paused. Lets the owner free up escrow without resuming first.
- **Expiry clock keeps ticking during pause** — a paused listing past `expires_at` is treated by `listings:expire` the same way as an open one: refund escrow + flip to Expired. Pausing is visibility-only, not a time freeze. Pause-extends-expiry isn't a v1 feature.
- **Pause/resume removed entirely in Phase 6.5 (2026-05-16).** The whole per-listing pause/resume model shipped in Phase 6 was undone in 6.5. Asymmetric-risk handling, the `Paused` enum case, ListingPolicy::pause/resume, the ResumeListingDialog, profile inline icons, and `Listing::scopeOpenOrPaused` are all removed. Global Active Mode now does the visibility job at the user level. Cancel keeps its existing dialog. The history of the original "Confirmation dialog on Resume" reasoning lives in git — Phase 6 commit message + pre-revision milestones — for anyone wanting to see why we tried per-listing first.
- **Two entry points (initially)** — listing detail page (button next to Cancel) + own-profile listing cards (inline icon). *Note: Phase 6.5 adds a third — the `/listings/mine` management dashboard. The two original entry points stay; they serve different audiences (the detail page for focused single-listing review, the profile inline icon for quick toggles while browsing your own page).*
- **Status badge color** — amber / warning. Distinct from Open (success green), Taken (primary pink), Expired (muted), Cancelled (destructive red). Communicates "paused — your action needed to reactivate."

**Test count: 324 / 1648 (was 277 / 1345 end of Phase 4 — +47 tests / +303 assertions).**

---

## Phase 6.5 — Listings management UI + design polish **(shipped 2026-05-16)** (~2 days, no new deps)

Filed 2026-05-16 after design review against Bybit's P2P management UX (My Ads / Orders / P2P User Center). Three concrete extensions to the Phase 6 work plus one reversal of a Phase 6 locked decision (Resume confirmation dialog).

This phase is filed as **separate scope from Phase 6** so the Phase 6 commit can ship cleanly and 6.5 can be reverted independently if the design direction needs to change.

### Scope

- [x] **6.5.1** `/matches` table-style redesign — wrap all match rows in a single container card (`bg-card/40 border-border/60 rounded-2xl`) with a column header at the top (Opponent · Status · Stake · Date) and rows stacked inside, separated by `border-t border-border/40`. Hover state changes from "lift + glow" to a calmer `bg-primary/5` row highlight — table-like rather than card-like. Visual reference: Bybit's Orders page, Stakly-skinned. Update `MatchListRow` (or replace with a thinner row component) so the same row renders correctly inside the new container.
- [x] **6.5.2** `/listings/mine` page + global Active Mode toggle + max-2-listings cap — new authenticated route `GET /listings/mine` (controller method on `ListingController`).
  - **Two tabs**: **Listed** (default — Open only, what's actually on the public board); **All Ads** (every status the owner has ever held: Open, Paused, Taken, Expired, Cancelled).
  - **Table layout** with column header: Status · Stake · Time control · Created · Expires · Actions. Pagination 12/page via Spatie query-builder pattern. Mirrors the `/matches` table from 6.5.1 for visual consistency.
  - **Actions column**: Pause / Resume icon button (status-gated, with Resume dialog from 6.5.3) + Cancel icon button (opens existing Cancel dialog). Take is never an action here — owners can't take their own listings.
  - **Page header** includes the gradient "Post listing" button (top-right) AND the **Active Mode toggle** (top-right, sibling to the Post listing button). The toggle is the *only* surface for the global active state; not in `SiteHeader`, not on `/listings`, not on profile.
  - **Inactive banner** inside `/listings/mine` when global state is inactive: "Your listings are hidden from the marketplace. Click the toggle to go active." Pattern lifted from Bybit's My Ads page.
  - **Confirmation dialog on Inactive → Active toggle**, same asymmetric-risk reasoning as Resume: reactivating makes listings takeable within seconds. Copy: *"Your listings will reappear on the marketplace immediately. Any opponent could take them and start a match. Ready to play?"* Direction Active → Inactive is dialog-free (safe direction).
  - **No auto-resume logic needed** — per-listing pause/resume was removed wholesale (see locked decisions below). The Active Mode toggle just flips `users.is_active_mode`; listings never change `status`. Visibility is enforced by `Listing::scopeOnPublicMarketplace` which filters by both `status = Open` AND `user.is_active_mode = true`.
  - **Max 2 active listings cap** enforced at `StoreListingRequest` validation: a user can hold at most 2 listings in {Open, Paused} states. Taken / Expired / Cancelled don't count toward the cap (they're concluded). Validation returns a 422 with a clear message; the `/listings/create` form gates the submit button when the cap is hit.
  - **PII-safe** — only the owner's own listings, scoped by `user_id`. Returns 403 for the platform user (defense in depth even though only auth+verified users hit the route).
- [x] **6.5.3** ~~Resume confirmation dialog~~ — superseded. Per-listing pause/resume was removed wholesale in 6.5 (see locked decisions below). The asymmetric-risk dialog moved to the Active Mode toggle in `ActiveModeToggle` instead: Inactive → Active opens a confirmation dialog ("Your listings will reappear on the marketplace immediately. An opponent could take one within seconds…"), Active → Inactive is a direct action with a toast. Shared `ResumeListingDialog` component deleted.
- [x] **6.5.4** Tools dropdown on `/listings` page — shipped as **"More" dropdown** (renamed from "Tools" during build for a more neutral label, with `MoreHorizontal` three-dots icon instead of a wrench). Compact pill trigger in the `/listings` page header, sibling to the Filters button on the right. Items: **Post listing** → `/listings/create`, **My listings** → `/listings/mine`, **Match history** → `/matches`. Auth-gated (`null` for guests). Mobile = icon-only (label + chevron hidden under `md:`) to keep the action row uncluttered next to Sort + Filters. Bordered-ghost hover pattern (`hover:bg-primary/10 hover:border-primary/40 hover:[text-shadow:none]`) — explicit override of the ghost variant's text-shadow glow because it reads busy layered on top of a background + border. Same hover treatment retro-applied to the Filters trigger so they match. `data-[state=open]:` keeps the highlight on while the menu is open.
- [x] **6.5.5** Tests — 27 new tests added covering Phase 6.5 surfaces:
  - `tests/Feature/ListingMineTest.php` (12 tests): auth gates (guest / unverified / platform), page renders with expected props, ownership scoping (only auth user's listings), tab scoping (`listed` = Open only, `all` = every status), default tab fallback for bad query params, pagination at 12/page, `activeCount` / `maxActive` props, inactive owner sees their own listings (cross-cut).
  - `tests/Feature/ActiveModeTest.php` (9 tests): auth gates, both flip directions (inactive → active, active → inactive) with appropriate toasts, same-state idempotency (no DB write, no toast — verified via `updated_at` unchanged using `$this->travel()`), validation (missing `active`, non-boolean values).
  - `tests/Feature/ListingStoreTest.php` (+2 tests): max-2 cap enforcement (`active_listings_cap` 422), only Open status counts toward cap.
  - `tests/Feature/ListingIndexTest.php` (+2 tests): inactive owner's listings hidden from public marketplace, flipping back to active republishes them.
  - `tests/Feature/UserShowTest.php` (+3 tests): visitor on inactive owner's profile sees no listings, owner viewing own inactive profile still sees their listings (manage), republish on re-activation.
  - Tools dropdown links covered implicitly — the three destination routes (`/listings/create`, `/listings/mine`, `/matches`) all have their own dedicated feature tests.

**Polish + bug fixes shipped during 6.5.4 / 6.5.5 (2026-05-16):**

- [x] Cancel listing now redirects to `/listings/mine` instead of `/listings/{id}` — cancel is a management action; users are most likely on / coming from the management dashboard, and the cancelled detail view has no actions left.
- [x] Active Mode toggle contextual hint — when the user has 0 active listings AND Active Mode is on, the toggle's description swaps to amber "Post a listing to appear on the board" instead of "Listings visible to the marketplace." Surfaces that the toggle is honored but inert without inventory, without auto-flipping state (which would surprise the user on next create).
- [x] `DropdownMenuItem` source skinned at `components/ui/dropdown-menu.tsx` — replaced upstream `bg-accent`/`text-accent-foreground` hover/focus with Stakly defaults: `bg-primary/10` wash, `cursor-pointer`, `hover:[&_svg]:!text-primary` icon highlight, `rounded-md px-2.5 py-2`, smooth `transition-colors duration-150`. Destructive variant uses `data-[variant=destructive]` with `bg-destructive/10` + `text-destructive`. Existing `ProfileMenu` + `CurrencyDropdown` inline overrides become redundant but still work.
- [x] `Button` source focus ring skinned at `components/ui/button.tsx` — replaced chunky shadcn `focus-visible:ring-[3px] ring-ring/50 border-ring` with Stakly `focus-visible:ring-2 ring-primary/25 border-primary/40` (the chunky default reads as a bug per CLAUDE.md visual rules). Affects all buttons globally.
- [x] Filters PopoverContent `shadow-glow-sm` removed — was creating a heavy pink halo around the open Filters panel that didn't match the calmer More dropdown next to it. Now both popovers have the same surface treatment (border + bg + backdrop-blur, no halo).
- [x] Profile menu dropdown completely restructured to match the auth modal's glow pattern. `DropdownMenuContent` becomes a transparent positioning wrapper (`!bg-transparent !border-0 !shadow-none !overflow-visible !p-0`). Inside: an ambient radial-gradient blur layer (460px, `blur-[100px]`, 22% pink — identical to the auth modal's ambient) as a sibling BEFORE the actual styled menu box. The styled menu box (`border-glow bg-card border rounded-xl p-1.5 backdrop-blur-md`) paints on top, covering the inner glow and leaving only the outer halo visible — same painting order as the auth modal. Trigger Avatar gets a `ring-2 ring-primary/50` on hover + `data-[state=open]` (no `focus-visible:` to avoid the ring sticking after dropdown close).

**Polish + bug fixes shipped during 6.5.1 / 6.5.2 (2026-05-16):**

- [x] Pause/resume removed wholesale — see Phase 6.5 "Locked decisions" below for the asymmetric-risk rationale (Active Mode now does the whole job at the user level).
- [x] Toast position changed from `top-center` → `bottom-right` so it stops overlapping the sticky nav (`components/ui/sonner.tsx`).
- [x] "Matches" link removed from the desktop `SiteHeader` nav — global nav is back to `Listings · How it Works · Support`. Matches still reachable via the avatar dropdown + mobile menu account card.
- [x] New users default to **Inactive Mode** (`users.is_active_mode` column default = `false`). `UserFactory` overrides to `true` so test fixtures + seeders keep working; new `inactive()` factory state for tests that exercise the off-state.
- [x] Listing-create redirect changed from `/listings/{id}` → `/listings/mine` (`ListingStoreTest` updated to match).
- [x] Click-through fix on `ListingCard`, `ListingRow`, `ProfileListingRow`, `MineListingRow` — content cells use `pointer-events-none` so cursor + click fall through to the row's absolute-overlay Link, matching the wallet's `ActionCard` "whole card clickable" pattern. Action buttons (Cancel) override with `pointer-events-auto` so they remain interactive.
- [x] Take button on listing cards/rows: now a real `<Button asChild><Link>` to listing detail with `relative` positioning, so cursor:pointer + gradient hover-glow boost work naturally (was `pointer-events-none` visual-only before).

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

Filed and shipped same-day as a UX follow-on after the user noted that navigating between management surfaces (listings, matches, wallet) required opening the profile dropdown each time. Bybit-style scoped sidebar pattern — only on management pages, not site-wide.

### Scope

- [x] **`PlayerHubLayout`** (`resources/js/layouts/player-hub-layout.tsx`) — wraps `SiteLayout` and adds a sticky left `PlayerSidebar`. Used by `/listings/mine`, `/matches`, `/wallet`, `/wallet/deposit`, `/wallet/withdraw`, `/wallet/history`. Public pages (`/`, `/listings`, `/listings/{id}`, `/users/{username}`) keep plain `SiteLayout` — sidebar would feel out of place on browsing surfaces and would shrink content for no benefit.
- [x] **`PlayerSidebar`** (`resources/js/components/site/player-sidebar.tsx`):
  - Three items: **My listings** (`ListChecks`) → `/listings/mine`, **Matches** (`Swords`) → `/matches`, **Wallet** (`WalletIcon`) → `/wallet` (Wallet's `matchPrefix = /wallet` lights up across all sub-pages).
  - Sticky `top-28` (just below sticky SiteHeader `h-16` + MarqueeStrip `~h-12`).
  - Full viewport height: `h-[calc(100vh-7rem)]` — right border extends the full visible height.
  - Active state: pink left accent bar (`w-1 h-6 absolute left-0 top-1/2 -translate-y-1/2 rounded-r-full bg-primary` with soft `--gradient-glow` shadow) + `bg-primary/15` wash + pink icon (`text-primary`).
  - Collapsible: rail mode toggles between `w-60` (expanded) and `w-16` (icon-only). `PanelLeftClose` / `PanelLeftOpen` button at top-left of sidebar. Smooth `transition-[width] duration-200 ease-out`. Tooltips on hover when collapsed (Radix Tooltip with 300ms delay).
  - **Preference persists in `localStorage`** under `stakly:player-sidebar:collapsed` so collapsed/expanded state survives reloads + navigation.
- [x] **Mobile**: sidebar is `hidden md:flex` — disappears entirely. Mobile users navigate via the existing SiteHeader hamburger menu (already has links to all three management surfaces). Avoids duplicating navigation patterns on small screens.
- [x] **Smart `BackLink`** (`resources/js/components/site/back-link.tsx`) — replaces 5 hardcoded "Back to X" links across listing detail, match detail, wallet deposit / withdraw / history. Generic "Back" label + `ChevronLeft` icon. On left-click, calls `window.history.back()` if `window.history.length > 1`; otherwise falls through to Inertia `<Link>` to the `fallback` URL (e.g. `/listings`, `/wallet`). Modifier-clicks (cmd/ctrl/shift/middle) bypass the smart behavior — those keep `<Link>` semantics for "Open in new tab". Why generic "Back" instead of context-specific labels: matches browser semantics, handles arbitrary referrers (user came from `/listings/mine` vs `/listings` vs a profile), survives weird flows (deep links, opened-in-new-tab) gracefully.

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

Real chess games can end in draws (stalemate, threefold repetition, 50-move rule, agreement, time-out vs insufficient material). The original `MatchOutcome` enum was `Won | Lost` only — when M8 swaps in real chess.com / Lichess adapters, the API will return draw results that the settlement code would have had no handler for.

Originally filed as an M8 prerequisite. Pulled forward to Phase 6.7 so the state machine is complete *before* Phase 7's timeout job builds on top of it — landing Phase 7 first would have meant revisiting it after Drawn lands.

### Scope

- [x] **6.7.1** Added `MatchOutcome::Drawn` enum case.
- [x] **6.7.2** Added `GameApiConfidence::Drawn` enum case (per locked design — extending the enum, not adding a separate `is_draw` flag on `GameApiResult`).
- [x] **6.7.3** New `MatchSettlement::settleDraw(GameMatch $match)` — row-locked, idempotent on `Settled`, calls `Wallet::release` for both players' stakes (refs `match-draw-creator:{id}` / `match-draw-taker:{id}`), flips status to `Settled` with `winner_user_id = null` + `settled_at = now()`. The `ManualReview` rejection guard (shared with `settle`) lands as part of Phase 7's 7.3.
- [x] **6.7.4** `GameMatchController::resolveBothConfirmed` rewritten with explicit branches: both `Drawn` → `settleDraw`; mirror `Won`/`Lost` → `settle`; anything else (including any disagreement involving `Drawn`) → dispute.
- [x] **6.7.5** `MatchSettlement::resolveDispute` extended: `confidence === Drawn` → `settleDraw`. Existing `Confirmed` / `Unknown` branches unchanged.
- [x] **6.7.6** `postDisputeResolutionSentinel` distinguishes `Settled` with a winner (`'settled-by-api'`) from `Settled` with no winner (`'settled-by-api-draw'`). New toast strings in `confirmRedirect` + `openDispute` for the agree-on-draw and draw-via-API paths.
- [x] **6.7.7** `MockGameApi::forceDraw()` test helper alongside `forceWinner()` / `forceUnknown()`. Winner-id ternary refactored to a positive `=== Confirmed` check so future enum cases don't accidentally inherit a non-null winner.
- [x] **6.7.8** Frontend: third "Draw" button (Handshake icon) in `ConfirmButtons` (2-col → 3-col). `SettlementSummary` gained a draw branch (refund both, no winner, no fee row, neutral tone, `Match drawn` header). `MatchListRow` + `ProfileMatchRow` gained `Draw` result chips. `match/show.tsx` derives `isDraw`, drops the `&& match.winner` guard on summary render. `MatchOutcome` TS type gained `'drawn'`.
- [x] **6.7.9** Tests: 11 new tests / 50 new assertions. `MatchSettlementTest` covers `settleDraw` happy path / conservation / idempotency / BCMath precision + `resolveDispute` Drawn branch. `GameMatchConfirmTest` covers both-Drawn settles + toast, Drawn-vs-Won routes to dispute (both directions), API-ruled draw refunds both + toast.

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

**Phase 7 — Timeouts + edge cases + polish** (~1–2 days, no new deps)

- [ ] **7.1** `App\Console\Commands\MatchesResolveTimeouts` Artisan command + scheduled task (`->everyTenMinutes()->withoutOverlapping()` in `routes/console.php`):
  - For each `Pending` match older than 4h: if exactly one player confirmed → settle in their favor; if neither → trigger dispute (game-API).
  - Idempotent via `match-timeout:{$match->id}` reference.
  - **Deadline contract shared with frontend.** `MatchTimer` (shipped in Phase 3) already shows the 4h countdown using `match.created_at + 4h` and flips to "Expired" state when the deadline passes. Phase 7's job uses the SAME deadline calculation — its role is to actually flip the match status server-side. Until Phase 7 lands, the timer hits "Expired" but the match stays `Pending` indefinitely (no auto-resolution).
- [ ] **7.2** Inertia flash toasts: "Listing taken — match started", "Match settled — you won/lost $X", "Dispute opened, awaiting resolution".
- [ ] **7.3** Positive status guard on `MatchSettlement::settle` + `MatchSettlement::settleDraw`. Today the only check is "skip if `Settled`." Change to: no-op on `Settled` (preserves idempotency), proceed on `Pending` / `Disputed`, throw `InvalidArgumentException` on `ManualReview` or any unexpected status. Future admin tools resolving `ManualReview` must take their own code path — silently piggybacking on regular `settle` would bypass the review intent. No behavior change for current callers (`resolveBothConfirmed` and `resolveDispute` always run on `Pending` / `Disputed`).
- [ ] **7.4** Final test sweep + manual end-to-end run: create listing, take from another account, confirm both ways (agree, disagree, draw, timeout, dispute).
- [ ] **7.5** Suggested commit: `feat: match flow with mock game-API (M6)`.

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
