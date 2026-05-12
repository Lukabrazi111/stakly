# Stakly Milestones

Frontend-first MVP. Build UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands later per page once the UI is validated.

## Phases (map)

- **M1** — Design Foundation + Homepage ✅
- **M2** — Auth Flow ✅
- **M2.5** — Pre-M3 polish ✅
- **M3** — Listings Index ✅
- **M3.5** — Wallet / Ledger Foundation **(next)**
- **M4** — Listing Detail + Create Flow
- **M5** — User Profile
- **M6** — Match Flow (mock)
- **M7** — Wallet UI
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

## M3.5 — Wallet / Ledger Foundation **(next)**

### Why this milestone exists

M4 (Create Listing) is inseparable from balance debits + escrow holds — you can't ship "Create listing" without "what does Create listing actually do to money?" Doing the **bookkeeping first**, with the on-chain layer mocked, lets us iterate marketplace mechanics with $0 risk. Once the ledger is correct, the on-chain layer is glue (watcher detects on-chain deposit → `Wallet::deposit()`; user clicks withdraw → `Wallet::withdraw()` then withdrawal worker signs + broadcasts via TronGrid).

### Locked decisions

- **Custody model**: balance-based custodial. Users deposit USDT once → the system tracks an internal `usdt_balance` → listing create / take / cancel / payout / fee all draw from balance. **Per-match deposits rejected** (5x operational complexity, no UX win).
- **Source of truth**: Postgres ledger (`wallet_transactions`). **Append-only, immutable.** Every money state change writes a row. **Never mutate `users.usdt_balance` outside a `Wallet` service method** — direct writes in seeders / migrations / controllers are forbidden, lint-check in tests if useful.
- **Transactional + idempotent**: every money operation wraps `DB::transaction(...)` with `lockForUpdate()` on the user row. Each accepts a `reference_id` — repeat calls with the same reference return the existing transaction (no-op), never double-debit. Callers may wrap a larger `DB::transaction(...)` around a wallet call when multiple operations must commit atomically (e.g., M4 "create listing row + `Wallet::hold`" — both succeed or both roll back). Laravel nests transactions via savepoints, so `Wallet::hold`'s internal transaction stays safe whether it's called standalone (deposit webhook) or inside a caller-opened transaction (listing create). The rule: if a wallet call belongs to a larger atomic unit of work, the caller opens the outer transaction.
- **On-chain integration deferred** to post-MVP. M3.5 mocks deposit/withdrawal as ledger entries with no chain interaction.
- **Provider-agnostic via adapter**: all chain calls go through an `App\Services\Chain\ChainGateway` interface. The ledger + `Wallet` service never reference TronGrid, TronWeb, or any specific chain library directly. Swapping the implementation later (GetBlock, NOWNodes, our own Tron node) is changing one binding in `AppServiceProvider`, not touching wallet code. **Chain custody architecture is now committed — DIY + TRC20 + we own the master seed.** See pre-launch gate for full details.
- **Platform-as-User pattern for fee tracking**: the platform's rake revenue is tracked via a special user row with `is_platform = true` (seeded as `platform@stakly.internal`). `Wallet::fee(...)` is just a positive ledger entry on this user. No nullable `user_id`, no special-casing — ledger invariant "every entry has a user_id" stays clean, and `Wallet::balanceFor($platform)` gives total platform revenue.
- **Money type discipline**: amounts in PHP are **strings**, arithmetic via **BCMath** (`bcadd`, `bcsub`, `bccomp`). Never use `+` / `-` / `<` on money values. Database column is `decimal(18, 6)` matching Tron's 6-decimal precision. The only place we convert to `(float)` is at the API resource boundary (e.g., `ListingResource`) so the frontend gets a JSON number — but internal PHP work stays in strings end-to-end. This is non-negotiable.
- **Sign convention**: the `amount` column is signed. Credits (Deposit, EscrowRelease, Payout, Fee) write positive values. Debits (Withdrawal, EscrowHold) write negative values. The invariant `users.usdt_balance == SUM(wallet_transactions.amount WHERE user_id = X)` must hold for every user at all times — and is asserted in tests.

### Scope (step-by-step)

> Marked off as we go. Each phase is a logical checkpoint — finishing a phase = good moment to commit.

**Phase 1 — Data shape (migrations + models)**
- [x] **1.1** Edit `database/migrations/0001_01_01_000000_create_users_table.php` to add `usdt_balance decimal(18,6) default 0` and `is_platform boolean default false` columns. (Pre-launch convention: edit existing migrations, don't add incrementals.)
- [x] **1.2** Create `database/migrations/<ts>_create_wallet_transactions_table.php` — `id`, `user_id` FK cascade, `type` string, `amount` signed `decimal(18,6)`, `balance_after` `decimal(18,6)`, `related_listing_id` nullable FK, `reference_id` unique nullable string, `description` nullable text, `created_at` only. No `updated_at`, no soft deletes. Indexes on `user_id`, `type`, `reference_id`, `created_at`.
- [x] **1.3** Create `App\Enums\WalletTransactionType` with cases `Deposit`, `Withdrawal`, `EscrowHold`, `EscrowRelease`, `Payout`, `Fee`.
- [x] **1.4** Create `App\Models\WalletTransaction` with `belongsTo(User)` + `belongsTo(Listing, 'related_listing_id')`. Casts: `type` → enum, `amount` + `balance_after` → `decimal:6`, `created_at` → datetime. Fillable matches migration columns.
- [x] **1.5** Create `database/factories/WalletTransactionFactory.php` with factory states for each enum case (`deposit`, `withdrawal`, `escrowHold`, `escrowRelease`, `payout`, `fee`).

**Phase 2 — Business logic (service + exception)**
- [x] **2.1** Create `App\Exceptions\InsufficientBalanceException`.
- [x] **2.2** Create `App\Services\Wallet` with public methods `deposit`, `withdraw`, `hold`, `release`, `payout`, `fee` + a `balanceFor(User)` helper. Each money operation: wraps `DB::transaction(...)` + `lockForUpdate()` on the user row, accepts a string amount + optional `reference_id` + optional `description` + optional `Listing`, validates positive input, computes signed amount per type (debit types negate the input), checks balance won't go negative on debits (throws `InsufficientBalanceException`), appends `WalletTransaction` row with `balance_after` snapshot, updates `users.usdt_balance`. All arithmetic via BCMath. Idempotency: if a row already exists with the given `reference_id`, return it unchanged (no-op).

**Phase 3 — Tests (Pest)** — 17 tests / 59 assertions in `tests/Feature/WalletTest.php`
- [x] **3.1** Happy-path tests: each of the 6 methods writes the correct ledger row + updates `users.usdt_balance` with the correct sign and value.
- [x] **3.2** Insufficient balance: `withdraw` and `hold` from a user without enough balance throws `InsufficientBalanceException`; no ledger row written; balance unchanged.
- [x] **3.3** Idempotency replay: calling any method twice with the same `reference_id` writes only one row; the second call returns the existing row silently; balance unchanged on the second call.
- [x] **3.4** Concurrent hold race: two simultaneous `hold` calls on the same balance — only one succeeds. (Verifies `lockForUpdate` is wired correctly.)
- [x] **3.5** Balance ↔ ledger invariant: for any user, `users.usdt_balance == SUM(wallet_transactions.amount)` after every operation. Property-style test running many random ops.
- [x] **3.6** Immutability: confirm no `UPDATE` statements ever hit `wallet_transactions` (DB query log assertion).
- [x] **3.7** Conservation of money: a full match-style flow (deposit, deposit, hold, hold, payout, fee) — total balance delta across all 3 users (winner + loser + platform) = 0. No money created or destroyed.
- [x] **3.8** Negative-input rejection: passing a negative or zero amount throws `InvalidArgumentException` before any DB work.

**Phase 4 — Seeder**
- [ ] **4.1** Update `DatabaseSeeder` to create the platform user first (`is_platform = true`, `email = 'platform@stakly.internal'`, name `'Stakly Platform'`).
- [ ] **4.2** Update `DatabaseSeeder` / `ListingSeeder` so every seeded dev user (the test user + the 20 marketplace users) gets $1000 via `Wallet::deposit($user, '1000', reference: "seed:dev-deposit:{$user->id}")`. Never set `usdt_balance` directly. Use the service.

**Phase 5 — Documentation**
- [ ] **5.1** Update CLAUDE.md "Conventions for AI Assistance" with the M3.5 wallet rules: service-only-write rule, BCMath money type discipline, signed-amount convention, balance-ledger invariant, platform-as-user pattern. Brief and rule-shaped — these are guardrails for every later money-touching milestone.

**Phase 6 — Verify**
- [ ] **6.1** `vendor/bin/sail artisan migrate:fresh --seed` runs clean. Inspect a dev user's balance + ledger rows manually via `database-query` or tinker to spot-check.
- [ ] **6.2** `vendor/bin/sail artisan test --compact` — all tests pass (M3's 69 + M3.5 new ones, expect ~85+ tests / ~500+ assertions).
- [ ] **6.3** `vendor/bin/sail bin pint --dirty --format agent` — formatting clean.
- [ ] **6.4** Commit. Suggested message: `feat: wallet ledger foundation (M3.5)`.

### Out of M3.5 scope (intentionally)

- Deposit / withdraw UI (M7).
- Real on-chain integration (pre-launch gate).
- Match settlement / dispute money flow (M6 uses M3.5's `Wallet::payout` + `Wallet::fee`).
- `ChainGateway` adapter interface — that's pre-launch work. M3.5 has no chain code at all.

---

## M4 — Listing Detail + Create Flow

Builds on M3.5. Public listing detail page + create form.

_Rough scope:_
- Listing detail page (mirror mmrangels' profile + booking widget layout)
- "Create listing" form → `Wallet::hold(...)` debits balance + writes escrow ledger entry, then creates listing. Transactional + idempotent.
- Auth gate (must be logged in + email verified) on create.
- "Take listing" CTA still UI-only — real take lands in M6.
- "Cancel listing" → `Wallet::release(...)` refunds creator.
- `ListingPolicy` for owner-only cancel.

---

## M5 — User Profile

Public player profile — stats, match history, ratings, linked game accounts.

---

## M6 — Match Flow (mock)

Match-in-progress page, both-players-confirm UI, dispute opening UI. Game-API integration mocked. Match settlement = `Wallet::payout(winner)` + `Wallet::fee(platform)`.

---

## M7 — Wallet UI

Deposit address display, withdrawal form, transaction history. Backed by the real M3.5 ledger; on-chain layer still mocked here. Real TronGrid wiring + watcher + sweeper + withdrawal worker live in the pre-launch gate below.

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
