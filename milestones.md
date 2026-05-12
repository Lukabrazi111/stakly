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

## M4 — Listing Detail + Create Flow ✅

First end-to-end money flow on the platform. Public listing detail page + auth-gated create form + owner-only cancel. Wires `Wallet::hold` on listing create and `Wallet::release` on cancel, both transactional with the listing row.

### Locked decisions

- **Expires-at UX**: duration dropdown (`1h / 6h / 12h / 24h / 48h / 72h`), not a datetime picker. Removes timezone confusion, matches how chess scheduling actually works.
- **Insufficient balance handling**: two layers. `StoreListingRequest` validates `stake_amount ≤ current balance` upfront (nice 422 with form error). `Wallet::hold` still throws `InsufficientBalanceException` defensively for concurrent-tab races.
- **Detail page for non-open listings**: render normally with a status badge (`Taken` / `Expired` / `Cancelled`), Take CTA disabled. No 404 — shared links to expired listings shouldn't error out.
- **Cancel confirmation**: shadcn `AlertDialog` with copy like "Cancel this listing? Your $X stake will be refunded immediately." Money operations earn an extra click.
- **Layout for detail page**: two-column desktop (creator info + listing details on left, sticky booking widget on right), single-column mobile. Mirrors mmrangels per CLAUDE.md M1 design reference.
- **Balance display on create form**: "Available: $X USDT" shown near the stake input, submit button disabled if stake > balance.
- **Build order: hybrid** — backend skeleton first (routes + minimal controller methods so Wayfinder generates real types), then frontend pages (visual iteration), then backend hardening (validation, policy, Wallet transactions, tests). Avoids both pure-frontend-first throwaway code and backend-first delayed-visual feedback.
- **Out of scope**: listing edit/update (cancel + re-create is the v1 mental model); image uploads (stick with initials avatars); take CTA real behavior (M6); take race conditions (also M6).

### Scope (step-by-step)

> Marked off as we go. Two natural commit points: end of Phase 6 (UI shipped, money flow still mock) and end of Phase 9 (full M4 with real Wallet wiring + tests).

**Phase 1 — Backend skeleton (real routes + types, minimal logic)**
- [x] **1.1** Add `show(Listing $listing)` to `ListingController` — returns `Inertia::render('listings/show', ['listing' => ListingResource::make($listing)->resolve()])`.
- [x] **1.2** Add `create()` to `ListingController` — returns `Inertia::render('listings/create', ['balance' => Wallet::balanceFor($request->user())])`. Auth-gated.
- [x] **1.3** Add `store(StoreListingRequest)` placeholder — basic `Listing::create([...])` returning a redirect to the new listing. No `Wallet::hold` yet (Phase 7).
- [x] **1.4** Add `cancel(Listing $listing)` placeholder — basic status update to `Cancelled`. No `Wallet::release` yet, no policy yet (Phase 7).
- [x] **1.5** Routes in `web.php`: `GET /listings/create`, `POST /listings`, `GET /listings/{listing}`, `DELETE /listings/{listing}/cancel`. Auth + verified middleware on create / store / cancel.
- [x] **1.6** Create `App\Http\Requests\Listings\StoreListingRequest` with rules skeleton (full validation in Phase 7).

**Phase 2 — Wayfinder + TypeScript types**
- [x] **2.1** Regenerate Wayfinder route functions (`vendor/bin/sail npm run build` or dev server).
- [x] **2.2** Add `ListingShowProps` + `ListingCreateProps` types to `resources/js/types/listings.ts`.

**Phase 3 — Listing detail page (`pages/listings/show.tsx`)**
- [x] **3.1** Scaffold the page with `<SiteLayout>`, two-column on desktop / stacked on mobile.
- [x] **3.2** Creator/profile column: avatar (initials), name, region, language, skill range.
- [x] **3.3** Listing details column: stake amount (gradient display), time control, expires-at relative time, status badge for non-open.
- [x] **3.4** Booking widget (sticky-right on desktop): stake repeat, **Take** CTA (disabled, "Coming in M6"), **Cancel** button (only when `auth.user.id === listing.creator.id` AND `status === 'open'`).
- [x] **3.5** Cancel confirmation `Dialog` (existing shadcn primitive — `AlertDialog` would have required installing a new Radix package and conflicted with our Stakly-skinned `button.tsx`); submits `DELETE /listings/{listing}/cancel` via `router.delete`.

**Phase 4 — Create listing page (`pages/listings/create.tsx`)**
- [x] **4.1** Scaffold with `<SiteLayout>`, single-column form (max-w-2xl).
- [x] **4.2** Stake input with USDT suffix + "Available: $X USDT" sublabel. Submit disabled when stake > balance or empty.
- [x] **4.3** Skill range inputs (`skill_min`, `skill_max`, both optional). Backend enforces min ≤ max via `gte:skill_min`.
- [x] **4.4** Time control selector (Blitz / Rapid / Classical) — built with our `ToggleGroup` primitive for visual consistency with the index page filters.
- [x] **4.5** Region select — option list passed from `StoreListingRequest::REGIONS` (single source of truth, no frontend duplication).
- [x] **4.6** Language select — option list from `StoreListingRequest::LANGUAGES` + "Any language" sentinel mapped to empty string at the boundary (Radix `Select` can't have empty-string values).
- [x] **4.7** Duration dropdown driven by `StoreListingRequest::DURATION_HOURS`. Backend converts to `expires_at = now()->addHours($duration)`.
- [x] **4.8** Game display (chess-only header with Crown icon + "More games coming soon" copy). Hidden `game: 'chess'` field. **Deviation from spec**: skipped the full multi-tile `GameSelector` clone in the form — adds 9 visual placeholders that don't serve form completion. Phase 6 can revisit if the user wants the marketing-style tiles in the form.
- [x] **4.9** Inertia `useForm` submission with per-field error display; backend redirects to `listings.show` on success.

**Phase 5 — Wiring (links + entry points)**
- [x] **5.1** Add **Create listing** CTA to `SiteHeader` + `MobileMenu` — three-state component: unauthed (opens auth modal), unverified (disabled with tooltip pointing at the verification chip), verified (links to `/listings/create`).
- [x] **5.2** Wrap `ListingRow` in `<Link>` to `listings.show(listing.id)` with `focus-visible:ring-2` for keyboard nav.
- [x] **5.3** Wrap `ListingCard` (homepage featured strip) similarly. Also refactored away duplicated formatting helpers — now imports from the shared `lib/listings-format.ts`.
- [x] **5.4** Updated `UnverifiedChip` title attribute to "Verify your email to create listings — click to resend." Closes the loop with the disabled Create listing CTA's tooltip.

**Phase 6 — Manual UI walkthrough (user-driven, iteration) ✅**
- [x] **6.1** Click through create-listing flow as a seeded dev user. Note any spacing / copy / animation tweaks.
- [x] **6.2** Click through listing detail as the creator (Cancel visible) and as another user (Cancel hidden).
- [x] **6.3** Click through listing detail as a guest (logged out).
- [x] **6.4** Open a non-open listing — confirm status badge displays correctly, Take CTA disabled, Cancel hidden.
- [x] **6.5** Cancel a listing — confirm `AlertDialog` works, refund happens, redirect lands correctly. (Used `Dialog` not `AlertDialog` — see Phase 3 deviation.)
- [x] **6.6** Apply polish based on observations — hid logged-out Create-listing CTA (Sign up covers funnel), moved Back-to-listings to top of detail page, full-width form Selects, restyled Cancel button (outline destructive, no text-shadow smudge), success-toast flash on create + cancel via `Inertia::flash`, multi time_control + multi language (jsonb columns + `AsEnumCollection` + form ToggleGroups + `whereJsonContains` overlap filter), `h-full` + `mt-auto` on `ListingCard` to equalize Ending-soon grid heights, capped language chip at 3 + "+N" on `ListingRow`.
- [x] **6.7** Optional commit checkpoint: `feat: listing detail + create UI (M4 stage 1)`.

**Phase 7 — Backend hardening (real money wiring) ✅**
- [x] **7.1** Create `App\Policies\ListingPolicy` with `cancel(User, Listing)` — owner-only AND `status === Open`. Auto-discovery in Laravel 11+ handles registration.
- [x] **7.2** Fill in `StoreListingRequest::rules()` — full validation incl. `stake_amount ≤ user balance` via a closure rule using `bccomp` at scale 6 (matches Wallet precision so we don't lose sub-cent headroom to float rounding).
- [x] **7.3** Wrap `ListingController::store` in `DB::transaction(...)` — `Listing::create` then `Wallet::hold(user, amount, listing, reference: "listing-create:{$listing->id}")`. Both commit or both roll back.
- [x] **7.4** Wrap `ListingController::cancel` in `DB::transaction(...)` — `Gate::authorize('cancel', $listing)`, then `Wallet::release(user, amount, listing, reference: "listing-cancel:{$listing->id}")`, then status update. Cancel toast now reports the refund amount.
- [x] **7.5** Catch `InsufficientBalanceException` in `store()` and throw `ValidationException` keyed on `stake_amount` so the form re-renders with field-level feedback.
- [x] **7.5b** **Seeder update (not in original plan):** `ListingSeeder` now calls `Wallet::hold` for every open/taken/ending-soon listing so seeded data satisfies the balance ↔ ledger invariant. Test User + seeded users bumped from $1k → $10k per user for hold headroom (some users own multiple listings; $1k wasn't enough). Verified via tinker: 0 users with broken invariant after `migrate:fresh --seed`.

**Phase 8 — Backend tests (Pest) ✅**
- [x] **8.1** Show: public listing renders, props match `ListingResource` shape; 404 on bad ID. (`ListingShowTest`)
- [x] **8.2** Show: taken / expired / cancelled listings still render (with status), no 404. + sensitive-field leak check.
- [x] **8.3** Create form: unauthenticated → redirect to `route('login')`. Unverified → blocked. Verified → form renders with `balance`, `regions`, `languages`, `durations` props.
- [x] **8.4** Store: happy path — listing row created + escrow hold ledger entry written (`-stake_amount`) + `usdt_balance` decremented exactly + redirect to `listings.show`.
- [x] **8.5** Store: insufficient balance (request-level pre-check) → 422 keyed on `stake_amount`, no listing created, no ledger row, balance unchanged.
- [x] **8.6** Store: validation failures — missing required fields / invalid `time_control` enum / empty `time_control` array / duplicate `time_control` entries → 422.
- [x] **8.7** Store: mass-assignment safety — posted `user_id` / `status` / `expires_at` in the request body have NO effect.
- [x] **8.8** Cancel: non-owner → 403 (ListingPolicy), listing untouched. Guests → redirect to login.
- [x] **8.9** Cancel: owner happy path — status → Cancelled, refund ledger row (`+stake_amount`) + balance restored to pre-listing value.
- [x] **8.10** Cancel: listing not in Open status (taken / expired / cancelled) → 403, even for owner. (`->with(['taken', 'expired', 'cancelled'])` data provider.)
- [x] **8.11** BCMath round-trip: stake → cancel → balance restored *exactly* via `bccomp`.
- [x] **8.x** Toast flash assertions on store + cancel via `assertInertiaFlash` (inertia-laravel's testing macro).

**Bugs uncovered by Phase 8 + fixed:**
- **Precision mismatch** between `decimal(12, 2)` listings column and scale-6 wallet ledger. A stake of `100.456` would have held `-100.456000` in the ledger but stored `100.46` on the listing, leaving a 0.004 USDT delta on cancel. Fixed by adding `decimal:0,2` to the `stake_amount` validation rule.
- **Stale `$user` instance** in `stakeWithinBalance` closure rule. Reading `$user->usdt_balance` directly returned the pre-deposit value when the auth user instance was stale (which happens reliably in tests using `actingAs($user)` after a deposit, and could happen in production with cached user instances). Fixed by using `Wallet::balanceFor($user)` which always does `$user->fresh()->usdt_balance`.

**Test stats:** 110 tests / 655 assertions across the suite (was 88 / 533 before Phase 8). Pint clean, types clean.

**Phase 9 — Verify + commit ✅**
- [x] **9.1** `vendor/bin/sail artisan migrate:fresh --seed` clean.
- [x] **9.2** `vendor/bin/sail artisan test --compact` — 110 tests / 655 assertions green.
- [x] **9.3** `vendor/bin/sail bin pint --dirty --format agent` clean. `npm run types:check` + `lint:check` clean.
- [ ] **9.4** Commit. Suggested message: `feat: listing detail + create + cancel (M4)`.

---

## Post-MVP — Listings polish (deferred, not in M4)

Captured so the intent isn't lost. **Don't pull these into M4.** Each is a real user-facing improvement but adds scope (state machine, UX flow, or step-up auth) that doesn't earn its complexity until we see real usage.

- **Step-up auth at listing creation.** Email-verified is already enforced via middleware. *All-listings* 2FA = friction that trains users to dismiss prompts. Better: step-up only for **high-stake** listings (e.g., `stake_amount > $500`) via Fortify's `confirm-password` (already plumbed for settings). Optionally also step-up on suspicious signals (new device fingerprint, rapid-fire creates). Decision deferred until post-launch when actual abuse patterns are visible.
- **Max active listings cap.** Wallet already caps total *capital exposure* naturally (can't escrow > balance). Explicit count cap is anti-marketplace-spam only. Suggested cap: **5** (not 2 — a player wanting one Blitz + one Rapid + one Classical listing hits 2 immediately). Consider tiered caps later (KYC'd users get higher cap).
- **Pause / resume listing.** New `paused` status on `ListingStatus` enum; `scopeOpen` excludes it. **Soft pause** (hide from board, keep escrow held) is the right v1 flavor — atomic, no extra wallet ops, no new dispute surface. Hard pause (release escrow, re-hold on resume) adds wallet churn for marginal UX benefit. Owner-only via `ListingPolicy::pause`.

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
