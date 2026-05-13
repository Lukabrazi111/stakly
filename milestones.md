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
- **M6** — Match Flow (mock)
- **M7** — Wallet UI **(next)**
- **M8** — Settings / Linked Accounts (chess.com / Lichess)

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
- **`ChainGateway` adapter + DIY + TRC20 + TronGrid path** locked in the pre-launch gate; M3.5 contains zero chain code.

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
- **Pause / resume listing.** New `paused` status on `ListingStatus` enum; `scopeOpen` excludes it. **Soft pause** (hide from board, keep escrow held) is the right v1 flavor — atomic, no extra wallet ops, no new dispute surface. Hard pause (release escrow, re-hold on resume) adds wallet churn for marginal UX benefit. Owner-only via `ListingPolicy::pause`.

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

## M6 — Match Flow (mock)

Match-in-progress page, both-players-confirm UI, dispute opening UI. Game-API integration mocked. Match settlement = `Wallet::payout(winner)` + `Wallet::fee(platform)`.

---

## M7 — Wallet UI **(next)**

User-facing wallet pages on top of the M3.5 ledger. v1 mocks the chain layer (no real Tron addresses, no real withdrawals) — those land in the pre-launch gate. Closes the missing feedback loop today: creating a listing drains balance but nothing in the UI shows it.

### Locked decisions

- **Multi-page**, not tabbed: `/wallet`, `/wallet/deposit`, `/wallet/withdraw`, `/wallet/history`. Matches Stakly's page-per-domain pattern.
- **Spendable balance only.** No "held in escrow" widget — `usdt_balance` already nets out holds. Held-balance derivable from the ledger if users ask.
- **Deterministic mock deposit address** stored in a new `tron_address` column on `users`. Generated at registration via `UserFactory` (mock) and via `CreateNewUser` (mock for v1, real HD derivation in the pre-launch gate). Looks like a real TRC20 address (`T...`).
- **QR code** for the deposit address (client-side `qrcode.react`, ~5KB dependency).
- **Transaction history**: simple `?page=N` pagination + optional `?type=...` filter. Same Spatie query-builder pattern as M3 listings.
- **USDT only**, formatted `$1,234.56 USDT` consistent with M3–M5.
- **Withdrawal flow = Option B** — form built and validates (address regex, min, ≤ balance), but submit shows a flash notice ("Withdrawals enabled at launch — your balance is safe.") and a `back()` redirect. No ledger write. At launch the notice is removed and the worker wires up. UX is fully testable now; no risk of real-money desync if a dev/tester clicks submit.
- **Balance chip in `SiteHeader`** — compact `$1,234.56` display next to `ProfileMenu` on desktop. Closes the listing-create → balance-changed feedback loop instantly. Mobile menu shows balance inline.
- **What we borrow from MMR Angels' deposit modal** (visually): QR + copy button + bold "only send TRC20 USDT" network warning. What we don't borrow: per-transaction countdown timer, "I Have Dispatched Payment" button, manager-via-Telegram confirmation — those fit a per-booking model, not Stakly's persistent-wallet model.

### Scope (step-by-step)

**Phase 1 — Schema + factory + seeder**
- [ ] **1.1** Edit `0001_01_01_000000_create_users_table.php` migration: add `tron_address` (varchar 34, nullable) column.
- [ ] **1.2** `User` model: add `tron_address` to `$fillable`.
- [ ] **1.3** `UserFactory`: generate mock TRC20-style address (`T` + 33 base58-like chars) via a small helper. Faker uniqueness guards collisions.
- [ ] **1.4** Update Fortify's `CreateNewUser` to generate + assign an address on registration. Same `DB::transaction` retry pattern as the username — defensive against the (extremely unlikely) collision.
- [ ] **1.5** `DatabaseSeeder`: Test User + Platform User get factory-generated mock addresses.
- [ ] **1.6** `vendor/bin/sail artisan migrate:fresh --seed` — verify every user has a unique well-formed address.

**Phase 2 — Backend (controllers + resources + routes + form requests)**
- [ ] **2.1** New `App\Http\Controllers\WalletController` with `index`, `deposit`, `withdraw`, `withdrawStore`, `history` methods. All `auth` + `verified` middleware.
- [ ] **2.2** New `App\Http\Resources\WalletTransactionResource` — public ledger shape (`id`, `type`, `amount`, `balance_after`, `related_listing_id`, `description`, `created_at`). **Never expose `reference_id`** (idempotency keys are internal).
- [ ] **2.3** New `App\Http\Requests\Wallet\WithdrawRequest` — validates `address` (TRC20 regex `^T[1-9A-HJ-NP-Za-km-z]{33}$`), `amount` (≥ 10, ≤ balance, `decimal:0,2`).
- [ ] **2.4** New `App\Http\Requests\Wallet\IndexHistoryRequest` — validates `?page`, `?type` (Spatie query-builder pattern from `IndexListingsRequest`).
- [ ] **2.5** 5 routes: `GET /wallet`, `GET /wallet/deposit`, `GET /wallet/withdraw`, `POST /wallet/withdraw`, `GET /wallet/history`. All named `wallet.*`.
- [ ] **2.6** `WalletController::withdrawStore` — short-circuits with a flash notice ("Withdrawals will be enabled at launch — your balance is safe.") and a `back()` redirect. No ledger write.

**Phase 3 — TS types + Wayfinder regen**
- [ ] **3.1** `vendor/bin/sail artisan wayfinder:generate --with-form`.
- [ ] **3.2** New `resources/js/types/wallet.ts` with `WalletTransaction`, `WalletTransactionType`, `WalletIndexProps`, `WalletDepositProps`, `WalletWithdrawProps`, `WalletHistoryProps` interfaces. Update `types/index.ts` barrel.
- [ ] **3.3** `vendor/bin/sail npm run types:check` clean.

**Phase 4 — Wallet UI**
- [ ] **4.1** `resources/js/pages/wallet/index.tsx` — overview: big balance + 3 action cards (Deposit / Withdraw / History) + last 5 transactions inline.
- [ ] **4.2** `resources/js/pages/wallet/deposit.tsx` — address + QR + network warning + copy-to-clipboard + estimated arrival text.
- [ ] **4.3** `resources/js/pages/wallet/withdraw.tsx` — form with address input, amount input (with "All available" button), balance display, and the v1 launch-notice on submit.
- [ ] **4.4** `resources/js/pages/wallet/history.tsx` — paginated table with type-filter chips + smart-ellipsis pagination (reuse `ListingPagination` pattern).
- [ ] **4.5** New shared components in `resources/js/components/wallet/`: `balance-card`, `address-display`, `transaction-row`, `withdraw-form`, `transaction-type-chip`.

**Phase 5 — Entry points**
- [ ] **5.1** Wire `ProfileMenu` "Wallet" dropdown item to `wallet.index` (currently stubbed at `#`).
- [ ] **5.2** Wire mobile menu "Wallet" item to `wallet.index`.
- [ ] **5.3** New `BalanceChip` component in `components/site/`. Compact `$1,234.56` display, links to `wallet.index`. Render in `SiteHeader` between marquee and `ProfileMenu`. Hidden on mobile (mobile menu shows balance inline).

**🛑 Phase 6 — Manual UI walkthrough (user-driven, expect iteration)**
- [ ] **6.1** Click around: balance updates immediately after creating/cancelling a listing.
- [ ] **6.2** Deposit page: address visible, QR readable by phone camera, copy-to-clipboard works, network warning is prominent.
- [ ] **6.3** Withdraw page: form validates (bad address, amount > balance, amount < min), submit shows the launch notice.
- [ ] **6.4** History page: pagination works, type filter works, escrow holds/releases display correctly with the listing reference.
- [ ] **6.5** Edge cases: 0 balance, very high balance, empty history, very long transaction list (50+).
- [ ] **6.6** Apply polish based on observations.

**Phase 7 — Backend hardening**
- [ ] **7.1** `WalletTransactionResource` never leaks `reference_id` or `user_id` in payload (covered by Phase 8 tests).
- [ ] **7.2** All wallet routes require `auth` + `verified` middleware (Phase 8 covers).
- [ ] **7.3** Withdrawal validation: TRC20 address regex matches, min withdrawal $10 (covers gas), max = current balance, `decimal:0,2` precision pinned.

**Phase 8 — Backend tests (Pest)**
- [ ] **8.1** Auth gating: all wallet routes redirect guests to login; unverified users to verification.notice.
- [ ] **8.2** Index page: balance prop matches `Wallet::balanceFor($user)`, last 5 transactions sorted desc.
- [ ] **8.3** Deposit page: address prop matches user's `tron_address`.
- [ ] **8.4** Withdraw form validation: bad address → 422, amount > balance → 422, amount < min → 422.
- [ ] **8.5** Withdraw store v1: valid submission returns flash notice, **no ledger row written**, balance unchanged.
- [ ] **8.6** History page: pagination size correct, `?type` filter scopes correctly, `?page=999` handled gracefully (empty page, not 404).
- [ ] **8.7** `WalletTransactionResource` never leaks `reference_id` or `user_id` in payload.
- [ ] **8.8** `tron_address` generated at registration is unique + matches TRC20 regex.

**Phase 9 — Verify + commit**
- [ ] **9.1** `vendor/bin/sail artisan migrate:fresh --seed` clean.
- [ ] **9.2** `vendor/bin/sail artisan test --compact` — full suite green.
- [ ] **9.3** Pint + types:check + lint:check clean.
- [ ] **9.4** Commit. Suggested message: `feat: wallet UI (M7)`.

### Out-of-scope (post-MVP or pre-launch gate)

- **Real chain integration** (TronGrid + watcher + sweeper + withdrawal worker) → pre-launch gate.
- **Live balance polling / WebSocket** → post-MVP. Page-load freshness is enough for v1.
- **"Held in escrow" widget** → post-MVP. Derivable from the ledger if users ask for it.
- **User-to-user internal transfers** → post-MVP if ever.
- **Per-listing pay-on-take modal** (MMR-Angels style) → post-MVP. Stakly's persistent-wallet model means users with balance take instantly; out-of-band top-up flow is a future enhancement, not blocking M7.
- **Fiat onramp** → out of MVP entirely (Stakly is crypto-end-to-end per CLAUDE.md).

---

## M8 — Settings / Linked Accounts

Profile settings, chess.com / Lichess account linking flow with ownership verification (UI only).

---

## Pre-launch gate — Custody + Jurisdiction (BLOCKER)

Real on-chain integration is gated by these blockers. **Do not proceed without explicit go-ahead.** Once Stakly accepts a single real deposit, it's operating a regulated money-handling business and the engineering becomes hard to unwind.

Required answers before mainnet wiring:
1. **Custody committed** ✅ — DIY level-3 (we own keys + run watcher/sweeper/withdrawal worker + use TronGrid as node provider). See "Chain custody architecture" below.
2. **Jurisdiction committed**: where Stakly is registered + license path (e.g., Curaçao sublicense, Malta MGA, US state-by-state map, or testnet-only / fake-money for the foreseeable future).
3. **Chain + provider committed** ✅ — TRC20 (Tron USDT) only for v1. TronGrid primary, GetBlock pre-configured as drop-in backup. See architecture section below.
4. **Key storage in production**: AWS KMS or HashiCorp Vault for hot wallet seed. Hardware device (Ledger / Trezor) for cold wallet. Specific KMS choice + access policy still open.
5. **Incident response plan**: hot-wallet compromise procedure, user notification template, insurance (if any).
6. **Terms of Service + dispute resolution** policy drafted.
7. **KYC/AML** required? If yes, integration with which provider, threshold that triggers it.

> No traditional banking / payment processor in scope — Stakly is **crypto-end-to-end** (USDT deposits, USDT withdrawals, USDT-denominated platform revenue). The only fiat touchpoint is the operating company's own expenses (taxes, legal), which is part of the jurisdiction decision (#2), not a user-facing gate.

### Chain custody architecture (committed)

**Path**: DIY custom integration. No managed custody service (no Tatum, no Moralis, no CryptoBot). We own keys, we run the infrastructure, we sign transactions. Eyes-open tradeoff: ~6–10 weeks of integration work vs ~1–2 weeks with a managed service; chosen for maximum self-custody, zero recurring service fees, zero counterparty risk at the custody layer.

**Chain (v1)**: TRC20 (Tron USDT) only. Adding other chains is post-v1 work — would just mean adding a second `ChainGateway` implementation alongside the Tron one.

**Node provider**: **TronGrid** (TRON Foundation, official) as primary — most native, best Tron docs. Their free Basic tier is **100k requests/day + 3 API keys** which comfortably covers production at MVP scale (estimated usage ~20–30% of that budget at launch). Paid Developer/Team/Business tiers are listed as "Coming Soon" with no published prices yet; Custom enterprise is contact-only. **GetBlock** pre-configured as drop-in backup behind the `ChainGateway` adapter — swap is a config change, ~30 min. Both providers see only RPC calls, not app context. Long-term endgame: run our own Tron full node (~$200/mo VPS) once volume justifies it; no third party can cut us off then.

**Master seed (custody)**: ours. BIP32/39/44 HD derivation produces unique deposit addresses per user. Master seed never touches a third party. Generated by us, stored by us (env var for dev/testnet, AWS KMS for production hot wallet, hardware-device-derived for production cold wallet).

**Wallet architecture (production target)**:
- **Cold wallet** — hardware device (Ledger / Trezor), holds bulk of platform reserves, signs only occasional large transfers. Offline-by-default.
- **Hot wallet** — smaller balance for daily user withdrawals (~1 week of expected payout volume), seed stored in AWS KMS, signing happens server-side.
- **User deposit addresses** — derived from master xPub, watched by our watcher service, swept into hot wallet on schedule.

**Components to build at pre-launch**:
- `App\Services\Chain\ChainGateway` interface
- `App\Services\Chain\TronGridGateway` implementation (using `iexbase/tron-api` or equivalent) + HD derivation library (e.g., `bitwasp/bitcoin-php` for BIP32)
- `App\Services\Chain\GetBlockGateway` (drop-in backup, same interface)
- **Watcher**: long-running Artisan command polling TronGrid for new deposits on user addresses. Idempotent (no double-credit), handles reorgs (waits N confirmations, typically 19 blocks on Tron), tracks last-checked block, resumable after crash.
- **Sweeper**: scheduled command moving USDT from user deposit addresses into hot wallet. Handles Tron's energy/bandwidth model (each address needs TRX to pay for transfer; pre-fund or use fee delegation).
- **Withdrawal worker**: queued Laravel job that signs + broadcasts USDT transfers from hot wallet. Sequencing on nonce, retry on stuck txs, monitoring for never-mined txs.
- **Key storage**: AWS KMS (production hot), hardware device (production cold), env var (dev/testnet only).
- **Cold-wallet sweep policy**: operational runbook for periodic hot → cold sweeps.

**Operational continuity**: provider-block risk is low for Tron specifically (gambling-friendly ecosystem, no known prohibited-use clauses) but the `ChainGateway` adapter makes it an operational annoyance, not existential. If TronGrid ever cuts us off, switching to GetBlock is a binding change; our keys, addresses, and funds are unaffected.

**Recurring cost**: $0/mo TronGrid free tier covers MVP launch + likely well beyond. ~$200/mo if we eventually run our own Tron node.

### App-level hardening (deferred from dev)

Small code-level cleanups noticed during M3–M4 development. Not blocking until we're approaching a real deployment, but they must land before the first non-developer touches the platform.

- **Platform user credentials.** Seeder currently creates `platform@stakly.internal` via the default `UserFactory`, which means `Hash::make('password')` + `email_verified_at = now()`. Pre-launch: override the seeder to use an unguessable random password (e.g. `Hash::make(bin2hex(random_bytes(32)))`) and set `email_verified_at = null`. Add a `Fortify::authenticateUsing(...)` hook in `FortifyServiceProvider` that explicitly rejects any user where `is_platform = true` — defense in depth against future code paths that might re-grant the password. When M5 ships, `UserController::show` also needs to 404 on `is_platform = true` users so the platform account isn't enumerated alongside real players.
- **Marquee copy.** `resources/js/layouts/site-layout.tsx` `defaultMarqueeItems` currently advertises a `STAKLY30 30% off` promo and other aspirational claims that don't reflect reality. Replace with honest copy (or move to per-page overrides) before any user-facing surface.
