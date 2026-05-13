# Stakly Milestones

Frontend-first MVP. Build UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands later per page once the UI is validated.

## Phases (map)

- **M1** — Design Foundation + Homepage ✅
- **M2** — Auth Flow ✅
- **M2.5** — Pre-M3 polish ✅
- **M3** — Listings Index ✅
- **M3.5** — Wallet / Ledger Foundation ✅
- **M4** — Listing Detail + Create Flow ✅
- **M5** — User Profile **(next)**
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

## M5 — User Profile **(next)**

Public, read-only player profile pages — discoverable via clicking any creator on a listing. Frame for the social/marketplace layer of Stakly: who is this player, what are they offering, how have they done.

### Locked decisions

- **URL**: `/users/{username}` via `getRouteKeyName: 'username'` on the User model. `users.show` route name.
- **Username**: new `username` column on users, unique + indexed. **Auto-generated at registration** from a slugified `name` with collision-safe suffixing (try `john-smith` first; on collision try `-1`, `-2`, …). Immutable in v1 — no rename flow. If we want renaming later it's a separate small feature in /settings/profile.
- **Bio**: new `bio` column on users, nullable, max 280 chars. Displayed on profile if set. M5 ships *display only*; an edit input in /settings/profile is a post-M5 follow-up (not blocking).
- **Avatar**: initials only for v1 (matches current state). Upload deferred to post-MVP polish.
- **Visibility**: profile is fully public, no auth needed to view. Same posture as `/listings`.
- **Stats**: split into "live now" vs "lights up later":
    - **Live now**: member-since, total open listings, total listings created (lifetime). Computed at request time.
    - **Lights up with M6**: win rate, total earnings, average opponent Elo. Cards render with `No matches yet` empty states until M6 produces match data.
- **Linked game accounts** (chess.com, Lichess): section renders with `Not linked` placeholders. M8 wires up real linking; M5 just frames the slot.
- **Entry points**: creator's name + avatar are clickable to `/users/{username}` on the ListingRow, ListingCard, and listing detail header. Requires restructuring the row/card to avoid nested `<a>` (HTML-invalid): wrap row in a non-anchor element with two side-by-side `<Link>`s (one for creator, one for listing body).
- **Layout**: SiteLayout wrapper (so flash toasts work). Two-section page: header card (avatar + name + username + member-since + bio) on top; grid of cards below (stats, open listings, match history, linked accounts).
- **Own-profile affordance**: if the viewer is signed-in and looking at their own profile, show an `Edit profile` button linking to `/settings/profile`. Otherwise no edit affordance.

### Scope (step-by-step)

**Phase 1 — Schema + model + factory + seeder**
- [ ] **1.1** Edit `0001_01_01_000000_create_users_table.php` migration: add `username` (string, unique, indexed) and `bio` (string, nullable, length 280) columns.
- [ ] **1.2** `User` model: add `username` + `bio` to `$fillable`; override `getRouteKeyName(): string` to return `'username'`.
- [ ] **1.3** Update Fortify's `CreateNewUser` action — after the user is created, derive `username` from `Str::slug($name)` with a collision-safe suffix loop, then `save()`. Wrap in a small private helper so the logic is testable. Add validation: `username` must match `/^[a-z0-9-]{3,30}$/` after slugifying.
- [ ] **1.4** `UserFactory`: generate a `username` via `Str::slug(faker->unique->userName)`. ~30% chance to populate `bio` with `faker->realText(120)`.
- [ ] **1.5** `DatabaseSeeder`: explicitly set Test User's `username = 'testuser'` (known value, easier to test against).
- [ ] **1.6** `vendor/bin/sail artisan migrate:fresh --seed` — verify every user has a username, no collisions, Test User reachable at `/users/testuser`.

**Phase 2 — Backend skeleton (route + controller + resource)**
- [ ] **2.1** New `App\Http\Controllers\UserController` with `show(User $user): Response`. No auth middleware — public route.
- [ ] **2.2** Route in `routes/web.php`: `Route::get('/users/{user:username}', [UserController::class, 'show'])->name('users.show')`. Place near the listings routes for grouping.
- [ ] **2.3** New `App\Http\Resources\UserProfileResource` — whitelisted public fields: `id`, `username`, `name`, `bio`, `member_since` (ISO `created_at`), `avatar` (nullable URL — for v1 always null, frontend falls back to initials). **Never** ship `email`, `usdt_balance`, `is_platform`, or two-factor fields.
- [ ] **2.4** `UserController::show` returns `Inertia::render('users/show', [...])` with: `user` (resource), `stats` (computed: open listings count, total listings count, member since), `openListings` (collection of `ListingResource` — top 5 most recent open listings; sort by newest).
- [ ] **2.5** Route model binding handles 404 automatically; no policy needed (public + read-only).

**Phase 3 — Wayfinder + TS types**
- [ ] **3.1** Regenerate Wayfinder typed routes via `npm run build` (or `artisan wayfinder:generate --with-form` — the latter is mandatory if going via artisan).
- [ ] **3.2** New `resources/js/types/profile.ts` with `UserProfile`, `ProfileStats`, `ProfileShowProps` interfaces. Backend source of truth: `UserProfileResource`.
- [ ] **3.3** `npm run types:check` clean.

**Phase 4 — Profile page + components**
- [ ] **4.1** New `resources/js/pages/users/show.tsx` — page-level layout (SiteLayout, Head with `{user.name}'s profile`).
- [ ] **4.2** `components/profile/profile-header.tsx` — avatar (initials), display name, `@{username}`, member-since (`Joined Mar 2026`), bio card if `bio !== null`. Right-side `Edit profile` button only when `auth.user.id === user.id`.
- [ ] **4.3** `components/profile/stats-card.tsx` — grid of stat tiles. **Live now**: Open listings, Total listings, Member since. **Empty-state tiles**: Win rate (`No matches yet`), Total earnings (`No matches yet`), Avg opponent rating (`No matches yet`). Visually consistent; empty states use `text-muted-foreground` with a subtle dashed border so they don't read as zero values.
- [ ] **4.4** `components/profile/listings-section.tsx` — reuses the existing `ListingRow` for open listings. Heading `Active listings · N`. Empty state: `No active listings right now.` with a soft border + muted copy.
- [ ] **4.5** `components/profile/match-history-section.tsx` — empty-state-only for v1. Heading `Match history`, body `No matches yet — match flow lands in M6.` Internal-facing copy; we can soften before public launch.
- [ ] **4.6** `components/profile/linked-accounts-section.tsx` — two rows (chess.com, Lichess) each with a `Not linked` muted chip. Heading `Linked game accounts`. Footer copy: `Link your accounts in settings.` (no real link until M8).
- [ ] **4.7** Compose all four sections in the page below the header.

**Phase 5 — Profile entry points (restructure ListingRow / ListingCard)**
- [ ] **5.1** `ListingRow` — currently wrapped in an outer `<Link>`. Restructure to a non-anchor outer element with two interior `<Link>`s: one wrapping the creator avatar + name (→ `users.show`), one wrapping the rest of the row body (→ `listings.show`). Verify keyboard nav (tab order makes sense), right-click → "Open in new tab" works on both targets, no nested-anchor warnings in the console.
- [ ] **5.2** `ListingCard` — same restructuring. Creator chip on top links to user profile; rest of card links to listing detail.
- [ ] **5.3** `pages/listings/show.tsx` header card — wrap the creator avatar + name in a Link to `users.show`. The status badge stays outside the link.

**🛑 Phase 6 — Manual UI walkthrough (user-driven, expect iteration)**
- [ ] **6.1** Click around as Test User: own profile (Edit button visible) and other seeded users' profiles (Edit hidden).
- [ ] **6.2** Verify empty-state sections render gracefully (match history, linked accounts, no-listings empty state).
- [ ] **6.3** Click creator name/avatar on listings index → lands on profile. Confirm row body click still goes to listing detail.
- [ ] **6.4** Click creator on listing detail page → lands on profile.
- [ ] **6.5** Edge cases: long username (30 chars), long bio (280 chars), very long name.
- [ ] **6.6** Apply polish based on observations.

**Phase 7 — Backend hardening + edge cases**
- [ ] **7.1** Username validation: enforce `/^[a-z0-9-]{3,30}$/` post-slug, fail registration with a clear error if the slug derivation produces an empty string (e.g., name = "!!!"). Defensive — registration validation already prevents empty/whitespace names, but cover the slug-collapse case.
- [ ] **7.2** Collision-loop safety: confirm the suffix loop in `CreateNewUser` terminates (bound it to N attempts; if exceeded, append `-{$id}` as a last resort).
- [ ] **7.3** UserController `show`: query `openListings` with `->with('user:id,name,username')` to avoid N+1 if `ListingRow` derefs creator (it does — username is now part of the link target).

**Phase 8 — Backend tests (Pest)**
- [ ] **8.1** Show: public profile renders for any visitor (guest, authed, authed-as-self).
- [ ] **8.2** Show: 404 on unknown username (route model binding miss).
- [ ] **8.3** Resource never leaks email or `usdt_balance` — hard `assertDontSee` on a seeded sensitive value.
- [ ] **8.4** `openListings` on the profile only contains the profile owner's *open* listings (filters out other users' listings, filters out taken/expired/cancelled).
- [ ] **8.5** Stats: open count + total count match the seeded reality for a known user.
- [ ] **8.6** Bio renders when set; absent in props when `bio === null`.
- [ ] **8.7** Registration auto-generates a username from the name (`Str::slug`).
- [ ] **8.8** Registration username collision: when "John Smith" registers twice, second user gets `john-smith-1` (or similar). Third gets `-2`. No duplicate-key DB violation.
- [ ] **8.9** Edge case: name with no alpha-numeric content → registration still produces a non-empty unique username via fallback.

**Phase 9 — Verify + commit**
- [ ] **9.1** `vendor/bin/sail artisan migrate:fresh --seed` clean.
- [ ] **9.2** `vendor/bin/sail artisan test --compact` — full suite green.
- [ ] **9.3** Pint + types:check + lint:check clean.
- [ ] **9.4** Commit. Suggested message: `feat: public user profiles (M5)`.

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

### App-level hardening (deferred from dev)

Small code-level cleanups noticed during M3–M4 development. Not blocking until we're approaching a real deployment, but they must land before the first non-developer touches the platform.

- **Platform user credentials.** Seeder currently creates `platform@stakly.internal` via the default `UserFactory`, which means `Hash::make('password')` + `email_verified_at = now()`. Pre-launch: override the seeder to use an unguessable random password (e.g. `Hash::make(bin2hex(random_bytes(32)))`) and set `email_verified_at = null`. Add a `Fortify::authenticateUsing(...)` hook in `FortifyServiceProvider` that explicitly rejects any user where `is_platform = true` — defense in depth against future code paths that might re-grant the password. When M5 ships, `UserController::show` also needs to 404 on `is_platform = true` users so the platform account isn't enumerated alongside real players.
- **Marquee copy.** `resources/js/layouts/site-layout.tsx` `defaultMarqueeItems` currently advertises a `STAKLY30 30% off` promo and other aspirational claims that don't reflect reality. Replace with honest copy (or move to per-page overrides) before any user-facing surface.
