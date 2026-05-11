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

Modal-only auth (login / register / forgot-password) with URL as source of truth (`?auth=*`); page-only destinations (`reset-password`, `two-factor-challenge`, `confirm-password`) all Stakly-skinned. Email verification enforced via `MustVerifyEmail`; unverified users see the amber `UnverifiedChip` in header + mobile menu (server-driven 60s cooldown via Inertia flash). `ProfileMenu` (avatar dropdown with My profile / Wallet / Settings / Log out) replaces Sign in / Sign up when logged in. `/dashboard` removed entirely. Inertia v3 native flash + Sonner toasts (top-center, Stakly-styled). Custom Fortify response bindings flash on register / verify / resend / forgot-password. `ThrottleVerificationSend` middleware caps resends at 1/min per user. Mailpit wired for local mail testing. **Tests: 40 passing, 158 assertions** (incl. flash assertions + rate-limit 429).

Locked architecture lives in `memory/project_milestones_state.md` (auto-loaded each session).

### Manual flow verification — still to walk through
- [ ] **Register** — submit → toast + amber chip; mailpit shows verification email; click link → "Email verified" toast; chip disappears
- [ ] **Forgot password → reset** — modal → submit → modal closes + toast; click reset link from mailpit → set new → log in
- [ ] **Login** — happy path + invalid-creds errors stay inside modal
- [ ] **Confirm-password** — trigger via 2FA setup or `/user/profile-information`
- [ ] **2FA challenge** — enable → log out → log in → 2FA page → recovery-code toggle works
- [ ] **URL-driven modal** — `/?auth=register` → Sign in inside → URL flips to `/?auth=login`; clear URL → closes; back-button → restores
- [ ] **Verify-email spam** — immediately after signup, click chip → "Resend in 60s"; spam via direct API → 429

---

## M2.5 — Pre-M3 polish (small, ~1-2 hour pass)

Three small items surfaced after M2 shipped. Quick to fix; worth clearing before opening M3 so the auth surface is fully polished.

- [ ] **Settings shell restyle.** `/settings/profile` and friends still render starter-kit `AppLayout` / `AppHeader` / `AppSidebar` chrome (neutral palette, breadcrumbs, sidebar). Jarring transition from the Stakly site shell. Wrap settings in `SiteLayout` instead, or restyle the existing app shell with Stakly tokens (dark + gradient + pill). Likely the simpler win is "settings is a centered card inside `SiteLayout`" — same shell as the rest of the site.
- [ ] **Logged-in user visiting `/?auth=login` flashes the modal.** Provider's `useEffect` watches `user && open` and closes after mount → brief visual flash. Fix: in `AuthModalProvider`'s `readState()`, return `{ open: false }` if `usePage().props.auth.user` is non-null. Note: `readState()` is called from `useState` initializer (no React context yet) — refactor so the user check lives in a separate effect, or read the initial Inertia page directly. Side benefit: sharing a Stakly URL with `?auth=*` won't pop the modal at logged-in users.
- [ ] **`ProfileMenu` stubs (`#`) jump to page top on click.** Replace the two `<Link href="#">` items (My profile, Wallet) with disabled `<DropdownMenuItem>` entries plus a small "Coming in M5/M7" hint, or wire to `toast.info('My profile is coming in M5.')`. Anything but a `#` href.

After these, M2 surface is fully shipped and we can open M3 cleanly.

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
