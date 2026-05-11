# Stakly Milestones

Frontend-first MVP. Build UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands later per page once the UI is validated.

## Phases (map)

- **M1** — Design Foundation + Homepage
- **M2** — Auth Flow (styled)
- **M3** — Listings Index
- **M4** — Listing Detail + Create Flow
- **M5** — User Profile
- **M6** — Match Flow (mock)
- **M7** — Wallet UI (mock)
- **M8** — Settings / Linked Accounts (chess.com / Lichess)

> Only the current milestone has a detailed task list. Future milestones expand when started. Move completed milestones to the top and mark with ✅.

---

## M1 — Design Foundation + Homepage ✅

Stakly's dark + pink/purple gradient visual system shipped: design tokens (`bg-card`, `text-gradient-primary`, `shadow-glow`, etc.), Bricolage + Inter fonts, gradient `Button` variant + `pill` size, `MarqueeStrip`, `SiteHeader`, `SiteFooter`, `SiteLayout`, `MobileMenu` (three-pill hamburger morph). Homepage `/` renders Hero (typography-only, atmospheric background — no character art per locked decision), `GameSelector` (chess selected by `border-glow`, 8 other games with "Soon" badges), and `HowItWorks` (three-step grid).

**Locked decisions referenced often:**
- `/` is hybrid: marketing sections + a featured-listings strip (strip built in M3, never ship fake data on `/`).
- Visual system rules — see CLAUDE.md "Visual System" + memory.

---

## M2 — Auth Flow ✅

Modal-only auth (`?auth=*` URL-driven), page-only destinations for reset/2FA/confirm, email verification via `MustVerifyEmail` + amber `UnverifiedChip` (60s cooldown), `ProfileMenu` + mobile account card, Inertia v3 native flash + Stakly-styled Sonner toasts, custom Fortify response bindings on register / verify / resend / forgot-password / password-reset (success → `/?auth=login` with toast), reset-token guard, `ThrottleVerificationSend` (1/min), Mailpit for local mail. **42 tests / 166 assertions.**

Locked architecture: `memory/project_milestones_state.md`.

---

## M2.5 — Pre-M3 polish ✅

Settings now renders inside `SiteLayout` (same `SiteHeader` + footer as the rest of the site) with an inline pill-tabs sub-nav (Profile / Security / Appearance). `AppLayout` / `AppShell` / `AppSidebar` / `AppSidebarHeader` / `AppContent` / `Breadcrumbs` + the rest of the starter-kit shell are deleted — settings is no longer a "separate app". `AuthModalProvider` no longer flashes the modal at logged-in users + strips stale `?auth=*` query. `ProfileMenu` "Coming soon" stubs replaced with disabled items.

---

## M3 — Listings Index

The marketplace board: `/listings` — filterable, sortable, paginated grid of open listings. **No "take listing" logic yet** (that's M4/M6) — the CTA is UI-only. **No "create listing" form yet** (that's M4). Frontend-first: build against real Postgres + seeded fake data.

### Backend
- [x] **M3.1 — Migration**: `listings` table created with `user_id` FK (cascadeOnDelete, TODO comment to harden when escrow lands), `game` (default `chess`), `stake_amount` (decimal 12,2), `skill_min` / `skill_max` (unsignedSmallInt, nullable), `time_control`, `region`, `language`, `expires_at`, `status` (default `open`), timestamps. Indexes on `status`, `stake_amount`, `expires_at`, `created_at`. Verified via Boost `database-schema`.
- [x] **M3.2 — Model + factory + enums**: `App\Enums\ListingStatus` + `App\Enums\TimeControl` (string-backed PHP enums). `Listing` model with `belongsTo(User)`, casts (`stake_amount` → decimal:2, `time_control` → enum, `status` → enum, `expires_at` → datetime), `scopeOpen()` (status=open AND not yet expired). Factory generates realistic data: stake distribution skewed low (more $10-50 than $500), 70% have a skill band / 30% "any", chess only (per MVP), varied time controls / regions / languages / expiries. Factory states: `open()`, `expired()`, `taken()`, `cancelled()`, `endingSoon()`, `highStake()`, `lowStake()`. Verified via tinker.
- [x] **M3.3 — Seeder**: `ListingSeeder` creates 20 users + 50 listings (40 open + 5 taken + 3 expired + 2 ending-soon), using `recycle()` so listings are spread across the user pool — power users own 4-5 listings each, mirroring a real marketplace. Wired into `DatabaseSeeder` so `migrate:fresh --seed` produces a full dev dataset. Verified via Boost `database-query` (42 open / 5 taken / 3 expired, 19 unique owners, ending-soon listings have ~20-40 min windows).
- [x] **M3.4 — Controller + route + request + resource**: `ListingController@index` is public, paginates 12/page (server-controlled, not URL-controlled), eager-loads `user:id,name`, and ships `ListingResource` data — whitelist only, no PII leak. Filters via `IndexListingsRequest` (strict validation: stake/skill bounded, time_control validated against enum, sort whitelisted via match-expression). Skill-range overlap query handles nullable listing bounds correctly. Route `GET /listings` named `listings.index`.
- [x] **M3.5 — Backend feature tests**: 15 tests / 147 assertions covering public route, default `scopeOpen` filter (only open + non-expired show), server-controlled pagination (12/page, ignores user-supplied `per_page`), filters (stake min/max, time_control multi, skill-range overlap, region), sorts (newest default, highest_stake, ending_soon), validation redirect to clean `/listings` on bad input (no 422 wall for stale share-links), filters echoed back for URL→form hydration, and **explicit PII guard** (`assertDontSee` on creator email). Tiny stub `listings/index.tsx` added so Inertia component-resolution passes — real page lands in M3.9.

### Frontend
- [x] **M3.6 — TypeScript types**: `resources/js/types/listings.ts` defines `Listing` (mirrors `ListingResource`), `ListingFilters`, `ListingSort`, `ListingStatus`, `TimeControl`, generic `Paginator<T>`, and the `ListingsIndexProps` shape consumed by `pages/listings/index.tsx`. Re-exported from the `@/types` barrel.
- [x] **M3.7 — `ListingRow` component** (`components/listings/listing-row.tsx`): horizontal row layout (per locked design choice — list view, not grid). Avatar + creator + region / skill range badge / time control badge / language badge / time remaining (amber text when < 1h left) / stake amount (`font-display` `text-gradient-primary`) / disabled "Take" CTA with `title="Coming in M4"`. Hover lift + soft pink glow. Responsive: collapses to vertical stack on mobile. Helper fns (`formatTimeRemaining`, `formatSkillRange`, `isEndingSoon`) live at module scope so `react-hooks/purity` lint is happy with `Date.now()` reads.
- [x] **M3.8 — Filter UI (Bybit-inspired, Stakly-skinned) + game registry + currency dropdown**: top bar (desktop) = Sort dropdown + **`StakeAmountInput`** compound (input + USDT currency dropdown in one bordered box, Bybit-style, debounced 400ms) + **Time control chip row** (multi-select) + **`Filters (N)`** button (popover on desktop / sheet on mobile via `useIsMobile()`, ~420px popover with brand-glow shadow). Mobile bar: 2 rows (Sort + Filters on row 1, full-width `StakeAmountInput` on row 2; chips live in the popover only). Active filter chips strip below with × removers + "Clear all". Popover/sheet body shares the same `FilterForm` (stake range, skill range, time control, region, language, Apply/Reset). URL sync via `router.get` with `preserveState`+`preserveScroll`+`replace`. **Game registry**: `App\Enums\Game` (PHP) + `resources/js/config/games.ts` (frontend) — adding a game = enum case + registry entry. **Currency registry**: `resources/js/config/currencies.ts` — USDT enabled, BTC/ETH as `Soon` (matches GameSelector). Time control chips only render if `gameSupports(filters.game, 'time_control')`. **Stakly-skinned shadcn primitives at the source** (per CLAUDE.md rule): `select.tsx` / `input.tsx` / `toggle.tsx` / `toggle-group.tsx` / `sheet.tsx` close button — soft pink-wash hovers (`bg-primary/10`), soft pink focus rings (`ring-2 ring-primary/25`), no more `bg-accent` purple defaults, no more chunky `ring-[3px]` focus glows, no more segmented-control default (chips are independent pills by default), number inputs hide their spin-arrows. Mobile menu uses the default Sheet close button (was rendering a duplicate before). Lint compliance: `FilterForm` re-mounts on open via conditional render (avoids `react-hooks/set-state-in-effect`). Sheet `SheetTitle` + `SheetDescription` added (a11y). Added `@radix-ui/react-popover` + shadcn `Popover` wrapper. **+2 tests** for game default + invalid-game redirect (now 17 / 159 in this suite, 59 / 325 overall).
- [ ] **M3.9 — Pagination + loading skeletons**: page shell + listing row rendering already shipped in M3.7/M3.8 (`pages/listings/index.tsx` uses `SiteLayout`, renders `<ListingRow>` stack, empty state, "{total} open / matching" count). Still to do: (1) **Pagination UI** — render Laravel paginator links (Prev / 1 / 2 / 3 / Next) styled with Stakly pills; preserve filter+sort query string on page change. With 42 open seeded listings + 12/page, we have 4 pages visible. (2) **Loading skeletons** — when Inertia visit is in flight (after filter / sort / page change), show ~6 shimmer rows in place of the current rows so the user gets immediate feedback instead of a stale list during the round-trip. Use the existing `Skeleton` component (`@/components/ui/skeleton`) wrapped to mimic the `ListingRow` silhouette. Source the in-flight state from Inertia's `useRemember` or `router` events.
- [x] **M3.10 — Header "Listings" link**: `SiteHeader` + `MobileMenu` "Listings" nav items now use Wayfinder's `@/routes/listings::index` typed route generator. Bumped while touching adjacent files.
- [ ] **M3.11 — Featured strip on `/`**: top 4–6 listings rendered as a horizontal row of `ListingCard`s on the homepage (server-fetched, never seeded directly into the page). Below `Hero`, above `GameSelector` or `HowItWorks` — TBD design.

### Out of scope for M3
- "Take listing" action (M4/M6)
- "Create listing" form (M4)
- Listing detail page (M4)
- Real escrow / on-chain funds locked at listing creation (deferred until custody is decided)

---

## M4 — Listing Detail + Create Flow

Public listing detail page (two-column profile + take widget) and the "create a listing" form.

_Rough scope:_
- Listing detail page (mirror mmrangels' profile + booking widget layout)
- "Create listing" form with validation
- "Take listing" CTA (UI only, no real escrow yet)

---

## M5 — User Profile

Public player profile — stats, match history, ratings, linked game accounts.

---

## M6 — Match Flow (mock)

Match-in-progress page, both-players-confirm UI, dispute opening UI. All mocked — no real game-API integration yet.

---

## M7 — Wallet UI (mock)

Deposit address display, withdrawal form, transaction history. Mock data only; real on-chain integration is a separate backend pass.

---

## M8 — Settings / Linked Accounts

Profile settings, chess.com / Lichess account linking flow with ownership verification (UI only).
