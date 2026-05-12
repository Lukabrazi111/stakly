# Stakly Milestones

Frontend-first MVP. Build UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands later per page once the UI is validated.

## Phases (map)

- **M1** — Design Foundation + Homepage ✅
- **M2** — Auth Flow ✅
- **M2.5** — Pre-M3 polish ✅
- **M3** — Listings Index ✅
- **M3.5** — Wallet / Ledger Foundation ✅
- **M4** — Listing Detail + Create Flow **(next)**
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

## M4 — Listing Detail + Create Flow **(next)**

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
