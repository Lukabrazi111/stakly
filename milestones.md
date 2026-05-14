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
- **M9** — Chain Integration (testnet) **(next)**
- **M6** — Match Flow (mock) [deferred — after M9]
- **M8** — Settings / Linked Accounts (chess.com / Lichess) [deferred — after M9]

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
- **`ChainGateway` adapter + Tatum managed-custody + TRC20** locked in M9 / pre-launch gate; M3.5 contains zero chain code.

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

## M9 — Chain Integration (testnet) **(next)**

The first real touch of crypto. Builds the entire deposit / withdrawal pipeline against **Tron testnet (Nile)** via **Tatum's Custodial Managed Wallets API**. Tatum holds the per-user TRC20 keys server-side; we orchestrate via REST. Free testnet money, real chain behavior — we exercise the full flow end-to-end before any mainnet flip. The mainnet migration plan stays in the **Pre-launch gate** section at the bottom.

After M9, the remaining work before launch is M6 (match settlement) + M8 (linked accounts) + the mainnet flip checklist.

### Why M9 now (strategic)

Locked-in pivot from the original M6 → M8 → pre-launch order. Reasoning: chain integration is the highest-anxiety unknown in the project, and de-risking it on testnet (where mistakes cost nothing) is more valuable now than another UI milestone. M6 and M8 are deferred but not dropped — they still gate launch.

### Architectural decision: managed custody via Tatum

Stakly outsources chain custody to **Tatum's Custodial Managed Wallets API**. Tatum generates and holds the private keys for per-user TRC20 deposit addresses; we never see or manage them. Withdrawals are authorized via API calls — Tatum signs and broadcasts. Deposits arrive at Tatum-managed addresses; we receive webhook notifications when they confirm.

**Tatum handles:**
- TRC20 deposit address generation (one per user, server-side keys)
- Address Events webhooks (deposit + outbound notifications, no polling needed)
- Transaction signing + broadcasting (withdrawals)
- Balance queries against the chain
- Multi-chain unified API — BEP20 v2 reuses the same integration shape

**Stakly retains:**
- The internal Postgres ledger (`wallet_transactions`) — already shipped in M3.5. Tatum's "Virtual Accounts" feature is **not** used; we own the ledger to avoid a double source of truth.
- User balance authority (`users.usdt_balance` + `App\Services\Wallet`).
- All match flow / listings / escrow / payouts logic — entirely Tatum-agnostic.

**Eyes-open tradeoffs:**
- **Vendor dependency**: Tatum's API uptime gates deposits and withdrawals. Mitigated by Tatum's key-export feature — if we ever need to leave, we can export keys and migrate to self-custody or another managed provider.
- **Recurring cost**: Custodial Managed Wallets API tier pricing — pending support inquiry. Free during dev/testnet.
- **Less control vs. self-custody**: we depend on Tatum's API surface. Operations they don't expose, we can't do.

### Why not self-DIY

We started M9 as DIY (PHP + Node sibling service + TronGrid + own HD derivation + own deposit watcher) and reached Phase 1 + 2 before backing out. The self-custody path is real ongoing operational burden — hot wallet security, key rotation, multi-chain duplication, polling watchers — and that complexity is the dominant risk for a solo-dev MVP vs. the bounded vendor risk of Tatum.

### Open questions (resolve before Phase 2)

- [ ] Tatum's response to use-case inquiry (P2P skill-staking eligibility under their ToS) — **email sent, awaiting reply**.
- [ ] Custodial Managed Wallets API pricing tier required for our launch load.
- [ ] Whether business KYC is gated at signup or only at production-volume threshold.
- [ ] Tron Nile testnet support for Custodial Managed Wallets (vs. Shasta-only or mainnet-only).
- [ ] Tatum's stance on internal liquidity / sweep model — do they pool funds automatically, or do we need a Phase 6 sweeper?

### Locked decisions

- **Provider**: Tatum — Custodial Managed Wallets API + Address Events webhooks. No alternative provider in v1.
- **Chain v1**: TRC20 (Tron USDT). M9 builds on **Tron Nile testnet**.
- **Chain v2**: BEP20 (BSC USDT) committed for post-launch. Reuses Tatum's unified API; adding it is a config + per-chain method-routing change, not a second integration.
- **Internal ledger stays in `wallet_transactions`** — Tatum's Virtual Accounts unused. Our balance + escrow logic is provider-agnostic.
- **`ChainGateway` adapter pattern** — interface + `TatumChainGateway` implementation + `MockChainGateway` for tests. DI binding via `config('chain.driver')`. Mock-driver tests don't require Tatum API access — CI stays fast and offline.
- **Confirmation finality** — Tatum's webhook delivers confirmed events; we trust their finality determination, no per-block counting in our code.
- **Replaces M7's mock address logic** — `App\Support\MockTronAddress` deleted. `users.tron_address` populated from Tatum's response. New `users.tatum_account_id` column for the managed-wallet reference. No `derivation_index` — we don't derive anymore.
- **Library policy** — no new PHP composer packages. Tatum is REST over Laravel's `Http::` facade. No Tron-specific PHP libraries.

### What you (the user) need to provide

| Item | When | Cost | Notes |
|------|------|------|-------|
| Tatum account + API keys | Phase 1 | Free | `https://dashboard.tatum.io`. Separate keys for testnet vs. production. |
| Use-case approval from Tatum support | Before Phase 2 | Free | Inquiry already sent; reply pending. |
| Tron Nile testnet TRX/USDT | Phase 3 | Free | Faucet: `https://nileex.io/join/getJoinPage` or Tatum's. Used to simulate user deposits + test withdrawals. |
| Production pricing decision | Pre-launch | Open | Once we know launch load, pick the tier. Not blocking M9. |

No master mnemonic. No KMS provisioning. No Ledger device required during M9.

### Scope (step-by-step, 5 phases)

> Estimates are **focused solo dev time**, not calendar time. Each phase ships something usable before moving on; commit per phase as before.

**Phase 1 — Tatum setup + `ChainGateway` interface** (~1–2 days, no new deps)

The foundation. Define the contract, ship the mock + Tatum implementations, wire the DI binding. No real Tatum API calls beyond a health check.

- [ ] **1.1** Sign up at Tatum, generate sandbox + (later) production API keys. `.env`: `CHAIN_DRIVER=tatum`, `TATUM_API_KEY=...`, `TATUM_API_URL=https://api.tatum.io`, `CHAIN_NETWORK=nile`, `TATUM_WEBHOOK_SECRET=...`.
- [ ] **1.2** New `App\Services\Chain\ChainGateway` interface with Tatum-shaped methods:
  - `createCustodialWallet(int $userId): array{address: string, accountId: string}`
  - `getUsdtBalance(string $accountId): string`
  - `subscribeToAddress(string $address, string $webhookUrl): string` — returns subscription id
  - `sendUsdt(string $fromAccountId, string $toAddress, string $amount): string` — returns tx hash
  - `getTransactionStatus(string $txHash): string` — `'pending' | 'confirmed' | 'failed'`
- [ ] **1.3** `App\Services\Chain\MockChainGateway` — deterministic outputs for tests (same address per user_id, fake tx hashes, no Tatum API calls). Used by `phpunit.xml` so CI stays offline.
- [ ] **1.4** `App\Services\Chain\TatumChainGateway` — Laravel `Http::` wrapper. API-key header (`x-api-key`), retry on 429/5xx, timeout, structured error responses, every operation logs to `Log::channel('chain')`.
- [ ] **1.5** `config/chain.php` — `driver`, `tatum_api_url`, `tatum_api_key`, `webhook_secret`, `tron_network`.
- [ ] **1.6** `AppServiceProvider` DI binding via `config('chain.driver')` — `mock` for dev/test, `tatum` for prod.
- [ ] **1.7** Tests: interface contract, mock determinism, `TatumChainGateway` against `Http::fake()` (happy path + 4xx + connection failure), DI binding selection.

**Phase 2 — Per-user deposit addresses** (~2–3 days, no new deps)

Real Tatum-managed wallets get created at user registration. The mock-address column is replaced with a real chain address.

- [ ] **2.1** Migrate `users` table: add `tatum_account_id varchar(64) UNIQUE NULL`. Keep `tron_address` (now sourced from Tatum's response). Pre-launch migration — edit the existing `0001_01_01_000000_create_users_table.php` directly.
- [ ] **2.2** Refactor `App\Actions\Fortify\CreateNewUser`: after the DB insert, call `$gateway->createCustodialWallet($user->id)`, store `address` → `tron_address`, `accountId` → `tatum_account_id`. Wrap in a transaction with rollback on Tatum failure — registration is not committed if the wallet can't be created.
- [ ] **2.3** Delete `App\Support\MockTronAddress`. Refactor `UserFactory` to route through `app(ChainGateway::class)->createCustodialWallet(...)` — mock driver in tests means deterministic addresses, no Tatum calls.
- [ ] **2.4** Update M7 tests that referenced `MockTronAddress`. Suite stays green on mock driver.
- [ ] **2.5** Re-seed dev data: `sail artisan migrate:fresh --seed` — verify every user has a Tatum-generated address + account id. Platform user excluded from wallet creation (platform doesn't need a deposit address).
- [ ] **2.6** Manual walkthrough: register a new user via the auth modal → check Tatum dashboard shows a new managed wallet → verify the address in our DB matches Tatum's.

**Phase 3 — Deposit detection via webhooks** (~2–3 days, no new deps)

Tatum's Address Events fire a webhook when USDT arrives at a managed wallet. We credit the ledger.

- [ ] **3.1** Extend `CreateNewUser` (or a follow-up queued job): after wallet creation, call `$gateway->subscribeToAddress($user->tron_address, route('webhooks.tatum.deposit'))`. Store subscription id on the user.
- [ ] **3.2** New `POST /webhooks/tatum/deposit` route — public (no `auth` middleware), CSRF-exempt, signature-verified.
- [ ] **3.3** `TatumWebhookController`:
  - Verify HMAC signature against `config('chain.webhook_secret')` using `hash_equals`.
  - Parse payload: `{address, amount, asset, blockNumber, txId, type, chain}`.
  - Look up user by `tron_address`. Unknown address → 200 + log warning (don't leak which addresses are ours via 404).
  - Asset filter: only credit USDT events; skip native TRX, fee notifications, other tokens.
  - Idempotency: `Wallet::deposit($user, $amount, reference: "tatum-deposit:{txId}")` — duplicate webhook deliveries are no-ops.
  - Return 200 on success, 401 on signature mismatch (Tatum retries on non-2xx).
- [ ] **3.4** `App\Console\Commands\ChainReconcile` — manual reconciliation command in case webhooks miss an event. Iterates users, queries `$gateway->getUsdtBalance($accountId)`, compares to `Wallet::balanceFor($user)`, surfaces gaps. Idempotent — re-running doesn't double-credit (uses the same `tatum-deposit:{txId}` references).
- [ ] **3.5** Tests with mocked webhook payloads: valid signature credits, invalid signature 401, replay is no-op, unknown address 200+log, non-USDT asset 200+ignore, fee notifications ignored.
- [ ] **3.6** End-to-end manual test on Nile: send testnet USDT from your wallet to one of our user's addresses → confirm webhook fires → ledger credited → `BalanceChip` updates on next nav.

**Phase 4 — Withdrawal worker (replaces M7's noop)** (~2–3 days, no new deps; Redis queue used)

Real withdrawal: user clicks Withdraw → queued job authorizes Tatum to sign + broadcast → webhook confirms → ledger and UI reflect the final state.

- [ ] **4.1** New `withdrawals` migration: `id, user_id, destination_address, amount, status enum('pending'|'broadcast'|'confirmed'|'failed'), tx_hash NULL, wallet_transaction_id NULL FK, failed_reason NULL, requested_at, broadcast_at NULL, confirmed_at NULL, timestamps`.
- [ ] **4.2** Replace `WalletController::withdrawStore` (currently M7's `Inertia::flash` noop):
  - `WithdrawRequest` already validates (regex, min $10, ≤ balance, `decimal:0,2`) — keep as-is.
  - Create `withdrawals` row with status `pending`.
  - Dispatch `ProcessWithdrawal` job.
  - Flash success toast ("Withdrawal received — usually completes in 1–2 minutes").
- [ ] **4.3** `App\Jobs\ProcessWithdrawal` queued job:
  - `User::lockForUpdate()`, re-verify balance ≥ amount (TOCTOU defense).
  - `Wallet::withdraw($user, $amount, reference: "withdrawal:{$id}")` — debits the ledger; the withdrawals row stores the resulting `wallet_transaction_id`.
  - Call `$gateway->sendUsdt($user->tatum_account_id, $destination, $amount)`.
  - On broadcast success: update withdrawal → status `broadcast`, store `tx_hash`.
  - On Tatum API failure: refund via `Wallet::deposit($user, $amount, reference: "withdrawal-refund:{$id}")`, set status `failed`, store reason.
- [ ] **4.4** Webhook handler extension: also process **outbound** address events from our managed wallets. When a `broadcast` withdrawal's tx_hash appears in an event, transition to `confirmed` + set `confirmed_at`.
- [ ] **4.5** New `/wallet/withdrawals` UI page — paginated list of recent withdrawals with status pill + Tronscan link for `broadcast`/`confirmed` ones. Reuses existing transaction-color conventions.
- [ ] **4.6** Configure queue: `QUEUE_CONNECTION=redis` in `.env` (Redis already in Sail). Dev workflow: `sail artisan queue:work` in a second terminal. Production: supervisor or Horizon.
- [ ] **4.7** Tests with `MockChainGateway`: happy path, refund on broadcast failure (conservation-of-money still holds), concurrent withdrawals can't double-spend, status transitions via webhook.
- [ ] **4.8** Manual end-to-end on Nile: trigger a withdrawal in the UI → watch the job process → check Tatum dashboard for the outbound tx → verify Tronscan confirmation → UI updates to `confirmed`.

**Phase 5 — Polish + Nile smoke test** (~1–2 days, no new deps)

Robustness + the documented path to mainnet.

- [ ] **5.1** Rate-limit Tatum API calls (cap to ~80% of the relevant tier budget — exact numbers locked once we have the support response).
- [ ] **5.2** Circuit breaker: if Tatum fails 5+ times in 60s, back off + alert via `chain` log channel.
- [ ] **5.3** Structured logging on every chain operation — request/response shape, latency, status, user id. PII-conscious (don't log full withdrawal destinations forever).
- [ ] **5.4** Full smoke test on Nile: register 3 dev users → fund their addresses from the faucet → deposits land via webhook → create + cancel listings (escrow flow) → withdraw to a faucet wallet → confirm everything balances on-chain (Tronscan) and in the ledger (`SUM(wallet_transactions.amount)` per user = `users.usdt_balance`). **This is the milestone gate** — if smoke passes, M9 ships.
- [ ] **5.5** Documentation: `docs/chain-runbook.md` — Tatum dashboard tour, webhook debugging, common failure modes, mainnet flip checklist (swap API key + chain config, fund the platform account, etc.). References the Pre-launch gate.
- [ ] **5.6** Final commit. Suggested message: `feat: chain integration on testnet via Tatum (M9)`.

### Tools and services summary

What's added to the stack by M9:

| Tool | Role | Where |
|------|------|-------|
| Tatum — Custodial Managed Wallets API | Key management, signing, balance queries | External SaaS |
| Tatum — Address Events / Webhooks | Deposit + outbound transaction notifications | External SaaS |
| Tron Nile testnet | Testnet chain for M9 dev | External |
| Laravel queues (Redis driver) | Async withdrawal processing | Already in Sail |
| Tron testnet faucet | Free testnet TRX + USDT | External |
| `nileex.io/tronscan` | Block explorer for verification | External |

No new composer packages. Tatum integration is HTTP calls via Laravel's `Http::` facade. Mainnet pricing decision deferred to the Pre-launch gate.

### Out of scope for M9 (explicitly deferred)

- **Mainnet anything** — different milestone, different blockers.
- **Sweep / hot-wallet consolidation** — with Tatum's managed wallets, sweeps may not be needed (Tatum likely handles internal liquidity within their custody). Confirm in their docs before launch; if needed, add a Phase 6.
- **GetBlock or any backup chain RPC** — Tatum is sole provider. The `ChainGateway` adapter makes a backup a future config change, not a v1 commitment.
- **Multi-chain (BEP20)** — committed for v2, not in M9.
- **Live balance push (WebSocket / SSE)** — page-load freshness via `auth.user.usdt_balance` Inertia share is sufficient for v1.
- **Tatum's Virtual Accounts (off-chain ledger)** — `wallet_transactions` is already our ledger; using Tatum's would be a double source of truth.

---

## M6 — Match Flow (mock)

**Deferred — after M9.**

Match-in-progress page, both-players-confirm UI, dispute opening UI. Game-API integration mocked. Match settlement = `Wallet::payout(winner)` + `Wallet::fee(platform)`.

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
1. **Custody committed** ✅ — DIY level-3 (we own keys + run watcher/sweeper/withdrawal worker + use TronGrid as node provider). See "Chain custody architecture" below.
2. **Jurisdiction committed**: where Stakly is registered + license path (e.g., Curaçao sublicense, Malta MGA, US state-by-state map, or testnet-only / fake-money for the foreseeable future).
3. **Chain + provider committed** ✅ — TRC20 (Tron USDT) only for v1. TronGrid primary, GetBlock pre-configured as drop-in backup. See architecture section below.
4. **Key storage in production**: AWS KMS or HashiCorp Vault for hot wallet seed. Hardware device (Ledger / Trezor) for cold wallet. Specific KMS choice + access policy still open.
5. **Incident response plan**: hot-wallet compromise procedure, user notification template, insurance (if any).
6. **Terms of Service + dispute resolution** policy drafted.
7. **KYC/AML** required? If yes, integration with which provider, threshold that triggers it.

> No traditional banking / payment processor in scope — Stakly is **crypto-end-to-end** (USDT deposits, USDT withdrawals, USDT-denominated platform revenue). The only fiat touchpoint is the operating company's own expenses (taxes, legal), which is part of the jurisdiction decision (#2), not a user-facing gate.

### Chain custody architecture (committed)

**Path**: Managed custody via **Tatum's Custodial Managed Wallets API**. Tatum holds per-user TRC20 private keys server-side; we orchestrate via REST + receive deposit notifications via webhooks. Eyes-open tradeoff: ~1–2 weeks of integration work vs ~6–10 weeks for the DIY path — chosen for solo-dev viability, bounded vendor risk (Tatum's key-export endpoint is our migration escape hatch), and multi-chain readiness (Tatum's unified API covers BEP20 v2 without a second integration).

**Chain (v1)**: TRC20 (Tron USDT) only. **Chain (v2)**: BEP20 (BSC USDT) committed for post-launch. Adding the second chain is a config + per-chain method-routing change at the gateway level — Tatum exposes the same API surface across chains.

**Custody model**: Tatum holds the keys; we hold the right to export them. The escape hatch makes "what if Tatum goes down or freezes us" a recovery question, not an existential one. Worst-case migration: export keys via Tatum's API → import into our own KMS or hardware wallet → swap `ChainGateway` implementation → operations resume with a different chain provider. ~1 day of work in a worst case, not weeks.

**Internal ledger**: Stays in our Postgres `wallet_transactions` table — same as M3.5. Tatum's "Virtual Accounts" feature is **not used**; we keep a single source of truth for balances and avoid two ledgers that could disagree.

**Components to build at M9 (pre-launch testnet phase)**:
- `App\Services\Chain\ChainGateway` interface
- `App\Services\Chain\TatumChainGateway` implementation (Laravel `Http::` facade — no Tatum SDK, no Tron-specific PHP libraries)
- `App\Services\Chain\MockChainGateway` for tests
- `TatumWebhookController` — receives Tatum's Address Events for deposits + outbound confirmations
- `App\Jobs\ProcessWithdrawal` — queued withdrawal worker authorizing Tatum to sign + broadcast
- `App\Console\Commands\ChainReconcile` — periodic reconciliation in case webhooks miss events

**Components NOT needed (vs. the DIY path)**:
- HD derivation library (Tatum derives)
- Master seed in env / KMS (Tatum stores)
- Tron-specific signing primitives — tronweb, `iexbase/tron-api`, BIP32/39/44 libraries (Tatum signs)
- Polling deposit watcher (webhooks instead)
- Sweeper / hot-wallet consolidation (Tatum likely handles internal liquidity — confirm pre-launch)
- Hot/cold wallet split (single Tatum-managed pool)

**Operational continuity**: provider-block risk is the main concern. Mitigated by (a) the use-case inquiry filed in M9's open questions — written yes/no before we commit production volume, (b) key-export escape hatch, (c) the `ChainGateway` adapter that makes a provider swap a code-change, not a re-architecture. If Tatum ever cuts us off: export keys → migrate to self-custody or another managed provider → resume operations.

**Recurring cost**: Custodial Managed Wallets API tier pricing — locked in pre-launch when load is known. Free during M9 testnet phase.

### App-level hardening (deferred from dev)

Small code-level cleanups noticed during M3–M4 development. Not blocking until we're approaching a real deployment, but they must land before the first non-developer touches the platform.

- **Platform user credentials.** Seeder currently creates `platform@stakly.internal` via the default `UserFactory`, which means `Hash::make('password')` + `email_verified_at = now()`. Pre-launch: override the seeder to use an unguessable random password (e.g. `Hash::make(bin2hex(random_bytes(32)))`) and set `email_verified_at = null`. Add a `Fortify::authenticateUsing(...)` hook in `FortifyServiceProvider` that explicitly rejects any user where `is_platform = true` — defense in depth against future code paths that might re-grant the password. When M5 ships, `UserController::show` also needs to 404 on `is_platform = true` users so the platform account isn't enumerated alongside real players.
- **Marquee copy.** `resources/js/layouts/site-layout.tsx` `defaultMarqueeItems` currently advertises a `STAKLY30 30% off` promo and other aspirational claims that don't reflect reality. Replace with honest copy (or move to per-page overrides) before any user-facing surface.
