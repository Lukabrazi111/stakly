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

M4 (Create Listing) is inseparable from balance debits + escrow holds — you can't ship "Create listing" without "what does Create listing actually do to money?" Doing the **bookkeeping first**, with the on-chain layer mocked, lets us iterate marketplace mechanics with $0 risk. Once the ledger is correct, the on-chain layer is glue (webhook in → `Wallet::credit()`; user clicks withdraw → `Wallet::debit()` + Tatum API call).

### Locked decisions

- **Custody model**: balance-based custodial. Users deposit USDT once → the system tracks an internal `usdt_balance` → listing create / take / cancel / payout / fee all draw from balance. **Per-match deposits rejected** (5x operational complexity, no UX win).
- **Source of truth**: Postgres ledger (`wallet_transactions`). **Append-only, immutable.** Every money state change writes a row. **Never mutate `users.usdt_balance` outside a `Wallet` service method** — direct writes in seeders / migrations / controllers are forbidden, lint-check in tests if useful.
- **Transactional + idempotent**: every money operation wraps `DB::transaction(...)` with `lockForUpdate()` on the user row. Each accepts a `reference_id` — repeat calls with the same reference return the existing transaction (no-op), never double-debit.
- **On-chain integration deferred** to post-MVP. M3.5 mocks deposit/withdrawal as ledger entries with no chain interaction.
- **Vendor-agnostic by design**: M3.5 builds the entire ledger + mock deposit/withdrawal layer without touching any external service. The chain provider (Tatum / Moralis / Alchemy+DIY / other) is **undecided** and the decision is intentionally deferred to the pre-launch gate. Choosing later costs nothing because the ledger is the contract everything else plugs into.

### Scope

- [ ] **Migration**: add `usdt_balance` (decimal 18,6, default 0) to `users`. New `wallet_transactions` table — `id`, `user_id` FK, `type` enum, `amount` (signed decimal), `balance_after` (snapshot), `related_listing_id` nullable FK, `reference_id` (unique nullable, for idempotency), `description`, `created_at`. **No `updated_at`, no soft deletes.**
- [ ] **Enum**: `App\Enums\WalletTransactionType` — `Deposit`, `Withdrawal`, `EscrowHold`, `EscrowRelease`, `Payout`, `Fee`.
- [ ] **Model + factory**: `WalletTransaction` model with `belongsTo(User)` + `belongsTo(Listing)`. Factory states for each `type`.
- [ ] **Seeder**: dev users start with $1000 via a seeded `deposit` ledger entry — **never set `usdt_balance` directly**, always via the service so ledger + balance stay consistent.
- [ ] **Service**: `App\Services\Wallet` — methods `hold`, `release`, `payout`, `fee`, `deposit`, `withdraw`. Each wraps `DB::transaction(...)` + `lockForUpdate()`, validates, appends ledger row, updates `users.usdt_balance`. Rejects negative amounts. Reference-id replay is a no-op (return existing row).
- [ ] **Exceptions**: `InsufficientBalanceException`. Idempotent replay is *not* an exception — returns the prior transaction silently.
- [ ] **Tests** (Pest): happy paths for each operation, insufficient balance, concurrent hold race (two simultaneous holds on the same balance — only one succeeds), idempotency replay (same `reference_id` twice = no-op), ledger immutability invariant (`balance_after` always matches `users.usdt_balance` after every operation), no `UPDATE` statements on `wallet_transactions`.
- [ ] **No UI** in M3.5. Wallet UI is M7.
- [ ] **CLAUDE.md** update — capture the locked decisions above + service-only-write rule.

### Out of scope for M3.5

- Deposit / withdraw UI (M7).
- Real on-chain integration (pre-launch, gated — see bottom).
- Match settlement / dispute money flow (M6).

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

Deposit address display, withdrawal form, transaction history. Backed by the real M3.5 ledger; on-chain layer still mocked here. Real Tatum wiring lives in the pre-launch gate below.

---

## M8 — Settings / Linked Accounts

Profile settings, chess.com / Lichess account linking flow with ownership verification (UI only).

---

## Pre-launch gate — Custody + Jurisdiction (BLOCKER)

Real on-chain integration (Tatum or similar) is gated by these blockers. **Do not proceed without explicit go-ahead.** Once Stakly accepts a single real deposit, it's operating a regulated money-handling business and the engineering becomes hard to unwind.

Required answers before mainnet wiring:
1. **Custody committed**: custodial via Stakly hot wallet. Key storage (AWS KMS / HashiCorp Vault / hardware). Multisig threshold for large withdrawals. Cold-wallet sweep policy.
2. **Jurisdiction committed**: where Stakly is registered + license path (e.g., Curaçao sublicense, Malta MGA, US state-by-state map, or testnet-only / fake-money for the foreseeable future).
3. **Chain-service provider committed** — see shortlist below. **Currently undecided.**
4. **Incident response plan**: hot-wallet compromise procedure, user notification template, insurance (if any).
5. **Terms of Service + dispute resolution** policy drafted.
6. **KYC/AML** required? If yes, integration with which provider, threshold that triggers it.

> No traditional banking / payment processor in scope — Stakly is **crypto-end-to-end** (USDT deposits, USDT withdrawals, USDT-denominated platform revenue). The only fiat touchpoint is the operating company's own expenses (taxes, legal), which is part of the jurisdiction decision (#2), not a user-facing gate.

### Chain-service provider shortlist (decision pending)

Need to pick one before any real-chain code lands. Honest comparison at MVP scale:

- **Tatum** — single API for both deposit-watching (webhooks) and withdrawal-signing. Lowest integration code. Free dev tier covers MVP; ~$50–100/mo production. *Easiest path.*
- **Moralis** — similar abstraction to Tatum, Web3-app-focused, often cheaper free tier. Verify BEP20 USDT support depth before committing.
- **Alchemy + DIY signing** — Address Activity webhook is free for monitoring; you write withdrawal signing yourself (Laravel job + `web3.php` + AWS KMS for key storage, ~$1–5/mo). **Cheapest viable option**; ~15 extra hours of engineering upfront, two integrations to maintain.
- **Rejected**: NOWPayments / Coinbase Commerce / BitPay (transaction-fee model stacks on top of our 10-15% rake — bad unit economics); DIY-everything with raw RPC (200+ hours of crypto-specific bug surface for a solo dev); BitGo / Fireblocks (enterprise, way overkill).

Decision criteria: free-tier limits vs MVP volume, DX of each dashboard, BSC/BEP20 USDT support depth. Action item: sign up for Tatum + Moralis free tiers, spend 30 min in each dashboard, pick the better DX.
