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

## M9 — Chain Integration (testnet) **(next)**

The first real touch of crypto. Builds the entire deposit / withdrawal / sweep pipeline against **Tron testnet (Nile)** behind a `ChainGateway` adapter. Free, real chain behavior, fake money — we exercise the full flow end-to-end before any mainnet flip. The mainnet migration plan + custody decisions stay in the **Pre-launch gate** section at the bottom.

After M9, the missing pieces before launch are M6 (match settlement) + M8 (linked accounts) + the mainnet flip checklist.

### Why M9 now (strategic)

Locked-in pivot from the original M6 → M8 → pre-launch order. Reasoning: chain integration is the highest-anxiety unknown in the project, and de-risking it on testnet (where mistakes cost nothing) is more valuable now than another UI milestone. M6 and M8 are deferred but not dropped — they still gate launch.

### Locked decisions

- **Tron testnet (Nile)** for all of M9. Mainnet flip is a config change later. Free TRX/USDT from Nile's faucet (`https://nileex.io/join/getJoinPage`).
- **TronGrid Basic plan (free)** — 100k requests/day, 3 API keys. Comfortably fits MVP load. User signs up + provides API key.
- **`ChainGateway` adapter pattern**: an interface + two implementations (mock for dev/tests, TronGrid for testnet, swappable to GetBlock or our own node later). Same DI binding selects the right one per environment.
- **HD derivation (BIP32/39/44)** — one master seed in `.env` (dev only). Every user gets a unique deposit address derived from `(seed, user.derivation_index)`. We never store per-user private keys; they're re-derived on demand. Tron coin type is **195** (BIP44 path `m/44'/195'/0'/0/{userIndex}`).
- **Confirmation threshold**: **19 blocks** on Tron (~1 minute), matching Tron's finality recommendation.
- **Master seed in `.env` only for dev**. Production custody (KMS or Ledger-based — see Pre-launch gate) is a separate decision later, not a v1-MVP concern.
- **Library choices deferred to per-phase discussion** — no `composer require` until you've approved the specific package + alternatives (per the user's "discuss libraries first" rule).
- **Replaces M7 Phase 1 mock-address logic**. `App\Support\MockTronAddress` gets absorbed into `MockChainGateway` and deleted. The existing `users.tron_address` column stays; addresses are now HD-derived, not random.

### What you (the user) need to provide

| Item | When | Cost | Notes |
|------|------|------|-------|
| TronGrid account + 3 API keys | Before Phase 3 | Free | Basic plan. Generate at `https://www.trongrid.io/dashboard`. |
| Testnet TRX (a few hundred) | Before Phase 3 | Free | Faucet: `https://nileex.io/join/getJoinPage`. Tron txns burn energy/bandwidth; testnet TRX pays for it. |
| Testnet USDT (a few thousand) | Before Phase 4 | Free | Same faucet flow. Used to simulate user deposits. |
| Decision on production custody (KMS vs Ledger-based) | Pre-mainnet (months out) | TBD | Not needed for M9. We pick this when we know launch volume + your operational comfort. |
| Ledger Nano X | Pre-mainnet | Already owned ✅ | Useful for **cold storage** at mainnet launch (see "Custody simply explained" below). Not used during M9. |

### Custody simply explained (one-time read)

The whole crypto setup hinges on **one secret**: the **master seed** — 12 or 24 random words that mathematically derive every Stakly wallet address. Whoever holds the seed controls all the money on the platform. So protecting it on mainnet is the entire game.

**Three real options for protecting the seed on mainnet** (we'll pick one closer to launch — not now):

1. **KMS only** (AWS KMS, Google Cloud KMS, HashiCorp Vault)
   - Cloud service. Seed lives **inside** the KMS, never on your application server.
   - Laravel app says "sign this transaction" — KMS signs and returns the result. Key never leaves.
   - Even if your server is fully compromised, the attacker can only request signatures while connected; they can't extract the key. Rate-limit + audit logs included.
   - Cost: ~$1–5/month. Good for automated daily withdrawals (instant UX).
   - Trade-off: depends on a cloud provider being available.

2. **Ledger Nano X for cold + KMS for hot** (industry standard for custodial platforms)
   - **Hot wallet** (KMS): holds ~1 week of expected payout volume. Automated signing of routine withdrawals.
   - **Cold wallet** (your Ledger): holds the other 90%+ of platform reserves. Physical button-press required for every signature, so malware can't auto-drain it. Pulled out manually to sweep hot ↔ cold every week or two.
   - Best security/UX balance. Most real platforms run this setup.
   - Cost: $0 extra (you already own the Ledger) + KMS fees.

3. **Ledger only with batched withdrawals** (no KMS)
   - All funds in the Ledger. Withdrawals queued; you sign them in batches once a day (or whenever you're at your computer).
   - Cheapest and most secure. But user UX is slower — "withdrawal in up to 24h" instead of "instant".
   - Works if Stakly's volume is low enough that manual ops is realistic.

**For M9 (testnet)**: none of this matters. We use a plain `.env` seed. Even if it leaks, the keys it derives are testnet — worthless. The KMS / Ledger decision happens months from now when we're prepping the mainnet flip.

### Scope (step-by-step, 7 phases)

> Estimates are **focused solo dev time**, not calendar time. Crypto integration has a learning curve — calendar time may be 1.5–2× estimates. Each phase ships something usable before moving on; you commit per phase as before.

**Phase 1 — `ChainGateway` adapter + Mock implementation** (~1–2 days, no new deps)

The foundation. Define the contract that all chain operations flow through; ship a mock implementation that mimics current M7 behavior so nothing breaks while we build the real one.

- [ ] **1.1** New `App\Services\Chain\ChainGateway` interface with methods:
  - `deriveAddressForUser(int $userIndex): string` — given a derivation index, return the Tron address
  - `getUsdtBalance(string $address): string` — chain-side USDT balance (BCMath string)
  - `getNewDeposits(string $address, ?int $sinceBlock): array` — return USDT transfers TO this address since a given block; each item has `{tx_hash, from, amount, block_number, timestamp}`
  - `sendUsdt(string $fromAddress, string $toAddress, string $amount, string $privateKey): string` — sign + broadcast, return tx hash
  - `getTransactionStatus(string $txHash): string` — `'pending' | 'confirmed' | 'failed'`
  - `latestBlockNumber(): int` — current chain head
- [ ] **1.2** New `App\Services\Chain\MockChainGateway implements ChainGateway`. Mimics M7's mock-address generation, returns 0 balance, empty deposit list, fake tx hashes. Deterministic per user index (same input → same output) so tests are stable.
- [ ] **1.3** New `config/chain.php` with `driver`, TronGrid endpoint, USDT contract address, confirmations required, master seed env var.
- [ ] **1.4** Register `ChainGateway` binding in `AppServiceProvider` driven by `config('chain.driver')` — `mock` for dev/test, `tron-grid` for testnet (Phase 3 will add the TronGrid binding).
- [ ] **1.5** Refactor M7's `App\Support\MockTronAddress` and `CreateNewUser`: instead of calling `MockTronAddress::generate()`, call `app(ChainGateway::class)->deriveAddressForUser($user->id)`. Delete `MockTronAddress.php`.
- [ ] **1.6** Update `users` migration: add `derivation_index BIGINT UNIQUE` column (auto-assigned at registration = max+1). This is the BIP44 leaf index.
- [ ] **1.7** Tests:
  - Interface contract test (every method returns the documented shape)
  - Mock returns the same address for the same user_index twice (deterministic)
  - DI binding selects the correct implementation based on `CHAIN_DRIVER` env
- [ ] **1.8** Update M7's tests where they referenced `MockTronAddress`. Suite stays green.

**Phase 2 — HD derivation (real keys from a seed)** (~3–5 days, 1 new dep)

Real BIP32/39/44 derivation: master seed → unique private key + Tron address per user index. Mock-driver tests stay deterministic; the math underneath is now real.

- [ ] **2.1** **Library discussion** before installing:
  - **Option A**: `bitwasp/bitcoin-php` — mature, popular PHP library for BIP32/39/44 + ECDSA. ~5MB. Last released ~2024. Recommended.
  - **Option B**: hand-rolled BIP32 using `simplito/elliptic-php` + `kornrunner/keccak`. Smaller surface, more code we maintain ourselves, more cryptographic risk.
  - **Option C**: A Tron-specific PHP library (e.g., `iexbase/tron-api`) that bundles HD. Convenient but couples our HD layer to a possibly-stale Tron library.
  - **My recommendation**: Option A — battle-tested for HD, separate from any Tron-specific code so we can swap Tron libraries independently. Tron's address encoding (keccak + base58check with version byte `0x41`) is small enough to write ourselves.
- [ ] **2.2** New `App\Services\Chain\HdDerivation` helper class with:
  - `mnemonicToSeed(string $mnemonic, string $passphrase = ''): string`
  - `derivePrivateKey(string $seed, int $userIndex, bool $testnet = true): string` — BIP44 path `m/44'/195'/0'/0/{userIndex}`
  - `privateKeyToTronAddress(string $privateKey): string` — Tron address derivation (keccak256 → last 20 bytes → prepend `0x41` → base58check encode)
- [ ] **2.3** `MockChainGateway::deriveAddressForUser($userIndex)` now uses real HD derivation. Still "mock" in the sense of no chain RPC calls — but the addresses are real and reproducible from the seed.
- [ ] **2.4** `.env` additions:
  - `CHAIN_MASTER_SEED="abandon ability ... (12 words, dev only)"` — generate once via `php artisan chain:generate-seed` (Phase 2.5).
  - `CHAIN_NETWORK=testnet`
- [ ] **2.5** New `App\Console\Commands\ChainGenerateSeed` artisan command — generates a random BIP39 mnemonic + prints it for the user to copy into `.env`. **Never** writes to `.env` automatically.
- [ ] **2.6** Migrate seeded data: drop dev users' `tron_address`, re-derive based on `derivation_index`.
- [ ] **2.7** Tests:
  - Same mnemonic + same index → same private key + address (deterministic, every time)
  - Different indices → different addresses
  - All generated addresses match the TRC20 regex `^T[1-9A-HJ-NP-Za-km-z]{33}$`
  - **External test vector**: a specific known BIP39 mnemonic produces a specific known address (sanity check against `iancoleman.io/bip39` or equivalent)

**Phase 3 — TronGrid client (real testnet calls)** (~5–7 days, possibly 1 new dep)

`TronGridGateway` implementation makes actual HTTP calls to TronGrid's Nile testnet endpoint. Read operations first (balances, transactions), then signing + broadcasting.

- [ ] **3.1** **Pre-Phase setup** (USER ACTION):
  - Sign up at `https://www.trongrid.io/dashboard` (free Basic plan)
  - Generate 3 API keys
  - Visit `https://nileex.io/join/getJoinPage` to claim testnet TRX
  - Send testnet USDT to one of your dev addresses (faucet flow or DEX)
- [ ] **3.2** **Library discussion**:
  - **Option A**: Raw HTTP via Laravel's `Http::` facade. Maximum control, zero new deps. We assemble TRC20 transfers ourselves using Phase 2's signing primitives.
  - **Option B**: `iexbase/tron-api` — bundles everything. Last meaningful update ~2–3 years ago; may have stale dependencies but probably still works.
  - **My recommendation**: Option A. The TronGrid REST API is documented and stable; the only complex bit (signing) is already covered by Phase 2. Avoids a possibly-unmaintained dependency.
- [ ] **3.3** New `App\Services\Chain\TronGridGateway implements ChainGateway`. All `ChainGateway` methods routed to TronGrid HTTP endpoints:
  - `getUsdtBalance` → `triggerconstantcontract` calling USDT contract's `balanceOf(address)`
  - `getNewDeposits` → `/v1/accounts/{address}/transactions/trc20` filtered by `min_timestamp` or `min_block`
  - `sendUsdt` → build TRC20 transfer tx → sign locally (Phase 2 primitives) → broadcast via `/wallet/broadcasttransaction`
  - `getTransactionStatus` → `/wallet/gettransactioninfobyid`
  - `latestBlockNumber` → `/wallet/getnowblock`
- [ ] **3.4** TronGrid HTTP client wrapper with API-key header (`TRON-PRO-API-KEY`), retry on 429/5xx, timeout, structured error responses.
- [ ] **3.5** Config:
  - `TRON_GRID_API_KEY=...`
  - `TRON_GRID_ENDPOINT=https://nile.trongrid.io`
  - `TRON_USDT_CONTRACT=...` (Nile testnet USDT contract address — verified at setup time)
  - `CHAIN_DRIVER=tron-grid` to flip from mock to real
- [ ] **3.6** Integration tests against Nile (marked `@group integration`, run separately from CI unit tests). Mock-based tests cover the same paths for CI speed.
- [ ] **3.7** Manual walkthrough: spin up `php artisan tinker`, instantiate the gateway, query a known testnet address's USDT balance. Sanity check the math.

**Phase 4 — Deposit watcher** (~4–6 days, no new deps)

A background process that polls TronGrid every N seconds, detects new USDT arrivals at user addresses, and credits the ledger via `Wallet::deposit()`. This is the heart of the deposit flow — crypto has no "push" notification when funds arrive, you have to poll.

- [ ] **4.1** New `chain_watch_cursors` migration:
  ```sql
  id, address (unique), last_checked_block, last_checked_at, created_at, updated_at
  ```
  Tracks how far we've scanned per address so we don't re-scan from genesis every cycle.
- [ ] **4.2** New `App\Console\Commands\ChainWatchDeposits` artisan command:
  - Locked: chunk through all users' addresses (e.g., 100 at a time) to respect TronGrid rate limit
  - For each address: `getNewDeposits($address, $cursor->last_checked_block)` via gateway
  - For each returned tx: skip if `tx.block > latestBlock - CONFIRMATIONS_REQUIRED` (still pending finality)
  - For confirmed txs: lookup user by `tron_address` → `Wallet::deposit($user, $amount, reference: "chain-deposit:{$txHash}")` — idempotent via reference, so double-runs never double-credit
  - Update cursor's `last_checked_block`
  - Logs every action, alerts on failures
- [ ] **4.3** Schedule in `routes/console.php` — `->everyMinute()->withoutOverlapping()`. (Or supervisor-managed daemon for sub-minute polling if needed.)
- [ ] **4.4** Tests with MockChainGateway returning fake deposits:
  - Single deposit → `Wallet::deposit` called with correct args
  - Idempotency: re-running the watcher on the same data doesn't double-credit
  - Confirmation gate: tx in block `latestBlock - 5` is skipped (need 19); tx in `latestBlock - 25` is processed
  - Cursor advances after each successful run
  - Multiple users in one batch
- [ ] **4.5** End-to-end manual test on Nile: send testnet USDT to a dev user's derived address from your testnet wallet → run watcher → verify balance updates + history shows the deposit.

**Phase 5 — Withdrawal worker (real, replaces M7's Option B noop)** (~5–7 days, no new deps; needs Redis queue)

Real withdrawal: user clicks Withdraw → queued job signs + broadcasts → status updates → ledger reflects. Replaces M7's flash-toast noop.

- [ ] **5.1** New `withdrawals` migration:
  ```sql
  id, user_id, destination_address, amount, status ('pending'|'broadcast'|'confirmed'|'failed'),
  tx_hash NULL, wallet_transaction_id NULL FK, failed_reason NULL,
  requested_at, broadcast_at NULL, confirmed_at NULL, created_at, updated_at
  ```
- [ ] **5.2** Replace `WalletController::withdrawStore`:
  - Validate (already done — `WithdrawRequest`)
  - Create `withdrawals` row with status `pending`
  - Dispatch `ProcessWithdrawal` job to the queue
  - Flash success toast ("Withdrawal received, processing — usually 1–2 minutes")
- [ ] **5.3** New `App\Jobs\ProcessWithdrawal` queued job:
  - Lock user row (`lockForUpdate`)
  - Re-verify balance ≥ amount (defense against TOCTOU race)
  - `Wallet::withdraw($user, $amount, reference: "withdrawal:{$withdrawalId}")` — debits the ledger; rolled back on later failure
  - Call `ChainGateway::sendUsdt($from, $to, $amount, $signingKey)` — derive signing key from master seed on-the-fly
  - On broadcast success: update withdrawal row → status `broadcast`, set `tx_hash`
  - On broadcast failure: refund via `Wallet::deposit` with `reference: "withdrawal-refund:{$id}"`, set status `failed`
- [ ] **5.4** New `App\Console\Commands\ChainWatchWithdrawals`:
  - For each `broadcast` withdrawal: query `getTransactionStatus($txHash)`
  - If confirmed: status `confirmed`, set `confirmed_at`
  - If on-chain failure (rare but possible): status `failed`, refund the user
  - Scheduled every minute
- [ ] **5.5** Configure Laravel queue: `QUEUE_CONNECTION=redis` in `.env` (Redis already in Sail). Worker started via `php artisan queue:work` (Sail dev) / supervisor (prod).
- [ ] **5.6** New `/wallet/withdrawals` UI page (small) showing pending/recent withdrawals with status + tx hash link to Tronscan.
- [ ] **5.7** Tests:
  - Job processes a valid withdrawal end-to-end (with mock gateway)
  - Refund on broadcast failure (balance returns, ledger conservation holds)
  - Race-condition: two concurrent withdrawals can't both spend the same balance
  - Tx-status worker transitions `broadcast` → `confirmed` correctly
  - Real Nile testnet test: actually broadcast a withdrawal, watch it confirm

**Phase 6 — Sweeper** (~4–6 days, no new deps)

Move USDT from individual user deposit addresses into a central platform "hot wallet" address. Without this, USDT accumulates on user addresses forever and the platform can't actually fund payouts.

- [ ] **6.1** Decide sweep threshold (e.g., when address balance ≥ 100 USDT, or when address balance > daily expected payout × 0.5).
- [ ] **6.2** Address platform's "hot wallet" address: derived from the same master seed at a reserved index (e.g., index 0 for hot, user indices start at 1). Stored in config.
- [ ] **6.3** Pre-fund concern: Tron txns burn TRX (energy/bandwidth). User addresses won't have TRX. Two approaches:
  - **Approach A**: Pre-fund each user address with ~1 TRX when first created (in `CreateNewUser`). Cheap on testnet, ~$0.30/user on mainnet. Burns a tiny amount of operating capital per user.
  - **Approach B**: Tron fee delegation (a separate "fee-paying" transaction covers gas for the sweep). More complex, no per-user burn.
  - **Recommendation**: Approach A for testnet + small launch. Approach B is a post-launch optimization once volume justifies it.
- [ ] **6.4** New `App\Console\Commands\ChainSweepDeposits` artisan command:
  - For each user address with on-chain balance ≥ threshold:
    - Pre-fund TRX if needed (test for energy/bandwidth first)
    - Sign + broadcast USDT transfer from user address → hot wallet
    - Log sweep tx hash; don't touch the ledger (the user's balance is already credited from Phase 4 deposit watcher)
- [ ] **6.5** Schedule daily or weekly (lower freq is fine; sweeping is operational, not user-facing).
- [ ] **6.6** Tests with MockChainGateway. End-to-end manual test on Nile: deposit USDT to a user → wait for credit → trigger sweep → verify USDT moves to hot wallet on Tronscan.

**Phase 7 — Polish + pre-mainnet checklist** (~3–5 days, no new deps)

Robustness + the documented path from testnet to mainnet.

- [ ] **7.1** Rate-limiting on TronGrid calls (cap to ~80% of free-tier daily budget to leave headroom).
- [ ] **7.2** Circuit breaker: if TronGrid fails 5+ times in 60 sec, back off + alert via log. Switch to backup gateway if/when GetBlock is wired (post-MVP).
- [ ] **7.3** Structured logging on every chain operation (`Log::channel('chain')`). Failures emit at `error` level.
- [ ] **7.4** End-to-end smoke test on Nile: register 3 dev users, fund them via faucet → testnet USDT → derived addresses, watch the deposits land, create + cancel listings (escrow/release flow), withdraw, confirm everything balances. **This is the milestone gate** — if smoke test passes, M9 ships.
- [ ] **7.5** Documentation: `docs/chain-runbook.md` (or in-code comments) covering:
  - How to start the watcher / sweeper / queue worker locally
  - Common failure modes + how to diagnose
  - The mainnet migration checklist (config swap, hot wallet funding, etc.) — references Pre-launch gate below
- [ ] **7.6** Final commit. Suggested message: `feat: chain integration on testnet (M9)`.

### Tools and services summary

What's added to the stack by M9:

| Tool | Role | Where |
|------|------|-------|
| TronGrid (Basic, free) | Tron RPC node provider | External service |
| Tron Nile testnet | Pretend Tron network | External |
| `bitwasp/bitcoin-php` (Phase 2) | BIP32/39/44 HD derivation primitives | `composer require` |
| Laravel queues (Redis driver) | Async withdrawal processing | Already in Sail |
| Tron's testnet faucet | Free testnet TRX + USDT | External |
| `nileex.io/tronscan` | Block explorer for verifying our txns | External |

No paid subscriptions for M9. Mainnet costs (KMS, optional Tron node hosting) are deferred to the Pre-launch gate.

### Out of scope for M9 (explicitly deferred)

- **Mainnet anything** — wallet, broadcasting, real money. Different milestone, different blockers.
- **KMS / Ledger integration** — only env-based seed for M9. Custody hardening is its own decision.
- **GetBlock backup** — TronGrid is sole provider during M9. Adapter is ready; second implementation lands when one is actually needed.
- **Multi-chain** — Tron only. Ethereum / BSC / etc. are post-MVP if ever.
- **Live balance push (WebSocket / SSE)** — page-load freshness via the existing `auth.user.usdt_balance` Inertia share is sufficient for v1.

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
- **Mock TRC20 addresses** (v1) via `App\Support\MockTronAddress` (`T` + 33 base58 chars, no `0`/`O`/`I`/`l`). Real HD derivation replaces this at the pre-launch chain integration gate. UNIQUE constraint at DB level + collision retry in `CreateNewUser` (same `DB::transaction` savepoint pattern as username).
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
