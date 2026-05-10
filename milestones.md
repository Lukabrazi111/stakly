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

## M1 — Design Foundation + Homepage

**Goal:** visiting `/` shows a styled, responsive homepage matching the dark + neon design direction. A small reusable component library and design-token system emerges in the process.

**Definition of done:**
- `/` renders correctly on mobile, tablet, and desktop.
- Design tokens are in `tailwind.config` (or Tailwind v4 CSS theme) — not hardcoded hex values in components.
- Header + Footer are reused across the layout.

### Design tokens & fonts
- [x] Install Bricolage Grotesque (display) and Inter (body) via `@fontsource-variable`
- [x] Configure Tailwind v4 color tokens — background, card, border, foreground, muted-foreground, primary, accent, success, warning, destructive
- [x] Configure font tokens — `font-display` (Bricolage), `font-sans` (Inter)
- [x] Add gradient utilities — `bg-gradient-primary`, `text-gradient-primary`
- [x] Add glow utilities — `shadow-glow`, `shadow-glow-sm`, `border-glow`
- [x] Force dark-only mode in `app.blade.php` (`<html class="dark">`)

### Base components
- [x] Customize shadcn `Button` — added `gradient` variant + `pill` size
- [x] `MarqueeStrip` — scrolling promo banner under nav, CSS-only `animate-marquee`
- [x] `SiteHeader` — logo + search bar (visual only) + nav links + Sign in / Sign up CTAs, mobile menu button stub
- [x] `SiteFooter` — minimal logo + tagline + nav links + copyright
- [x] `SiteLayout` — composes header + marquee + main + footer; accepts custom marqueeItems

### Homepage (`/`)
- [x] `Hero` section — typography-only headline ("Stake your skill. Find your match."), subheadline, primary CTA, atmospheric background (blurred glow blobs + dot grid). No character art per locked decision.
- [x] `GameSelector` row — horizontal snap-scroll, chess selected by default with 2px `border-glow` + lift, other games (Dota 2, LoL, CS2, Valorant, Apex, Rocket League, Overwatch, Fortnite) shown with "Soon" badge and clickable.
- [x] `HowItWorks` section — three-step grid (Post listing → Match opponent → Play & get paid), anchored at `#how-it-works`.
- [x] Wire `/` route to render the homepage.
- [ ] Verify responsive on mobile / tablet / desktop in browser

**Notes / decisions deferred:**
- Search bar functionality (UI only for now)
- Whether `/` and `/listings` are the same page or separate — decide in M3 based on visual flow
- Hero copy / final wording — placeholder for now, polish later

---

## M2 — Auth Flow (styled)

Fortify is already installed and routed. The work here is purely styling the existing auth views (login, register, password reset, email verification, two-factor if enabled) to match the design.

_Rough scope (flesh out when starting):_
- Style login + register pages
- Style password reset + email verification
- Decide on social login (Steam / Google) — likely deferred to v2
- Test full flow end-to-end with seeded users

---

## M3 — Listings Index

The marketplace board. Filterable, sortable, paginated grid of listings.

_Rough scope:_
- Listings table migration + model + factory + seeder
- Listing card component
- Filters: stake range, skill range, language, region
- Sort: newest, highest stake
- Empty state, loading state, pagination

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
