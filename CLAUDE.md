<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `vendor/bin/sail artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `vendor/bin/sail artisan make:test --pest SomeFeatureTest` instead of `vendor/bin/sail artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `vendor/bin/sail artisan test --compact` or filter: `vendor/bin/sail artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>

=== stakly project context ===

# Stakly

Stakly is a P2P platform where players post listings to find opponents and stake crypto on the match outcome. Players post a listing (game, stake amount, skill range), an opponent takes it, both stakes are escrowed, the match is played, and the winner receives the pot minus a platform commission (~10-15%).

This is **not a dApp / web3 protocol**. It is a custodial web2 application that uses crypto as a payment rail. There are no smart contracts, no on-chain game logic, no NFTs. The blockchain is a deposit/payout mechanism only.

## MVP Scope (LOCKED — do not expand without approval)

- **Game**: chess only (via chess.com / Lichess APIs). Dota 2 deferred to v2.
- **Format**: 1v1 only. Team matches deferred to v2.
- **Currency**: USDT only. No other tokens, no native chain coins.
- **Chain**: a single chain (BEP20 leading candidate, not yet committed). No multi-chain for v1.
- **Solo developer** (Luka). Scope is tight on purpose to make a one-person ship realistic.

Anything outside this scope is v2 and should be flagged, not built.

## Stack & Dev Environment

- Backend: Laravel 13 + Inertia v3.
- Frontend: React 19 + TypeScript + Tailwind v4 + shadcn/ui (per `components.json`).
- Database: Postgres 18 (via Sail).
- Cache/queue/sessions: Redis (via Sail) and Postgres (Laravel default for cache/queue/sessions tables).
- Local dev: **Laravel Sail** (Docker Compose). Services in `compose.yaml`: `laravel.test` (app), `pgsql`, `redis`, `mailpit`. Run via `./vendor/bin/sail up -d`.
- All `php`, `artisan`, `composer`, `npm`, and `pest` commands should be prefixed with `./vendor/bin/sail` so they run inside the container against the right Postgres/Redis. Example: `sail artisan migrate`, `sail composer require ...`, `sail npm run dev`.

## Key Design Decisions

- **Listings board, not an order book.** Filterable marketplace (game, stake range, skill range, region), not price-time matching.
- **Stake is escrowed at listing creation**, not at match. This prevents bait-listings and makes match start instant. Refund on listing expiry.
- **Outcome verification — primary path is the game's official API.** Players link their chess.com / Lichess account at signup with verified ownership (e.g. bio-code challenge). When a match ends, the system queries the API for the result. Both-players-confirm is the fast path; on disagreement, the API is the tiebreaker. Screenshots + manual support are last-resort only — never the primary mechanism.
- **All financial state lives in a Postgres ledger.** The chain is the rail; the database is the source of truth. Every state transition (deposit, escrow, refund, payout, fee) is an immutable ledger entry. Money operations must be transactional and idempotent.
- **Auth UX — modal-only for entry points, pages for destinations, URL-driven.** Login, register, and forgot-password are presented exclusively as a single shadcn `Dialog` triggered via `useAuthModal()` (context mounted at `app.tsx` root, modal rendered inside `SiteLayout` because it needs Inertia's `usePage`). **The `?auth=login|register|forgot-password` query param is the source of truth for modal state** — opening writes the param (pushState the first time, replaceState on view-swap), closing removes it (replaceState), and the provider listens to `popstate` so back-button/manual-URL-clearing closes or restores the modal accordingly. Fortify view callbacks for these three surfaces redirect to `/?auth=*` — there are **no** `/login`, `/register`, or `/forgot-password` page components. Reset-password, verify-email, two-factor-challenge, and confirm-password remain **page-only** because users land on them from email links or post-auth redirects, where there's nothing to overlay.
- **Fortify is the auth foundation.** TOTP 2FA is already wired. SMS OTP, email OTP, magic links, passkeys, and social login are all reachable as extensions (custom columns + middleware + provider) but are not in v1.
- **Three animation systems, each with a specific job.** (1) **CSS transitions** (`transition-colors`, `hover:shadow-glow-sm`) for hover/focus/simple state changes — never wrap clickable elements in `motion.*` just for hover. (2) **`tw-animate-css`** (`data-[state=open]:animate-in fade-in-0`) for Radix/shadcn primitives where state is controlled by `data-state` — Sheet, Dialog, Popover, Tooltip. Don't fight Radix by replacing these with motion; we'd lose focus management, scroll-lock, and a11y. (3) **`motion`** (`AnimatePresence`, `motion.div layout`, spring physics) for our own React-state-driven animations: enter/exit, layout/size animation, sequenced reveals. The auth modal uses motion correctly; future cases like animated listing cards (M3), match flow state reveals (M6), and wallet success states (M7) should also use motion.

## Visual System

Stakly is **dark-only**, no light-mode toggle. `<html class="dark">` is hardcoded in `app.blade.php`; theme values are duplicated to `:root` and `.dark` for shadcn variant compatibility. Always use Tailwind token classes (`bg-card`, `text-foreground`) — never raw hex in components.

### Palette tokens

- **Surfaces**: `bg-background` (deep purple-black `#0a0710`), `bg-card` (raised `#14101c`), `bg-secondary`/`bg-muted` (`#1c1626`), `border-border` (`#2a2330`)
- **Text**: `text-foreground` (`#f5f5f7`), `text-muted-foreground` (`#8a8696`)
- **Brand**: `bg-primary`/`text-primary` (pink `#ec4899`), `bg-accent`/`text-accent` (purple `#a855f7`)
- **Status**: `bg-success`/`text-success` (emerald — online, prices, profit), `bg-warning`/`text-warning` (amber — disputes), `bg-destructive`/`text-destructive` (red — errors, losses)

### Brand gradient + glow utilities

The pink→purple gradient is the visual signature. Apply *sparingly* — 2-3 gradient elements per page max.

- `bg-gradient-primary` — gradient background (CTAs, hero accents)
- `text-gradient-primary` — gradient-filled text (display headlines)
- `shadow-glow` / `shadow-glow-sm` — magenta glow halos (hover/focus/selected). Currently tuned to a soft `0 0 18px -7px` so they read as a subtle haze, not a block of color.
- `border-glow` — magenta border with inner glow (active tile in selector rows)

### Shadow tokens (CSS variables)

Component-specific shadow values live as CSS variables in `:root, .dark` in `app.css`, then are referenced inline via `shadow-[var(--token-name)]` (box-shadow) or `[text-shadow:var(--token-name)]` (text-shadow). This keeps shadow tuning centralized in one file. Current tokens:

- `--shadow-button-glow` / `--shadow-button-glow-hover` — used by the `gradient` button variant. The gradient button references both inline (`shadow-[var(--shadow-button-glow)] hover:shadow-[var(--shadow-button-glow-hover)]`). Tune button glow by editing the variables, not the component.
- `--text-shadow-glow` — soft white text-shadow used by the `ghost` button variant on hover. Implemented as a layered text-shadow (tight bright inner + wider outer) at full white. Mirrors the gradient button's halo concept but applied to letterforms instead of the button box.

**Ghost button hover convention:** ghost variants in Stakly use **text-shadow** (white text glow) on hover, NOT `bg-accent`/`bg-muted` like default shadcn. This was an intentional deviation — purple-background hover felt heavy on the dark theme. If you need a ghost-like button with the original `bg-accent` hover (e.g. sidebar nav items, dropdown menu items), introduce a new variant rather than reverting the global `ghost`.

When adding a new component-specific shadow, prefer this pattern over inline arbitrary values like `shadow-[0_0_24px_...]` — keep design tokens in `app.css`.

### Fonts

- `font-sans` (Inter Variable) — body, UI, paragraphs
- `font-display` (Bricolage Grotesque Variable) — headlines and large numbers. Use heavy weights (700/800) for display impact.

### General visual rules

- Pill everything: buttons, badges, chips, search bars → `rounded-full` or `rounded-lg`.
- Avoid hard right angles on top-level UI; soften with at least `rounded-md`.
- Glow is for interactive states (hover/selected/focus), not static — overuse kills the meaning.
- No character art for stakly v1. Hero uses gradient + typography + abstract atmosphere. Avatars are initials or generated.
- **Every new UI must fit the Stakly design — including shadcn primitives.** Defaults like `bg-accent` (saturated purple `#a855f7`) for hover, `bg-muted` for hover, and chunky `ring-[3px] ring-ring/50` focus glows are *not* Stakly — they leak the upstream shadcn palette. When adding a new shadcn component, immediately Stakly-skin it at the source (`components/ui/<name>.tsx`):
  - Hover/focus bg → **`bg-primary/10`** (translucent pink wash), not `bg-accent` or `bg-muted`.
  - Selected/active state → **`bg-primary/15 text-foreground border-primary/40`**, not `bg-accent`.
  - Hovered icon color → **`hover:[&_svg]:!text-primary`** (note the `!` — needed because shadcn's icon descendant selectors have higher specificity than ours; without `!` they don't take effect).
  - Focus ring → **`ring-2 ring-primary/25 border-primary/40`**, not `ring-[3px] ring-ring/50`. The chunky default reads as a bug.
  - Surface backgrounds for inputs/triggers/cards → **`bg-card/60`** or `bg-card/95` with `border-border/60`, not raw `bg-transparent` over the page background.
  - Radius → match the surrounding context; mostly `rounded-md` or `rounded-xl` for popovers / sheets / dropdown content.
- The above defaults are already applied to `select.tsx`, `input.tsx`, `toggle.tsx`, `dropdown-menu.tsx`, `popover.tsx`. New shadcn components must match.
- If a per-usage override is needed (e.g. a specific component wants a `bg-destructive/10` Log-out hover), apply it at the call site — but never re-introduce `bg-accent`/`bg-muted` as a hover default at the primitive level.

### M1 design references + decisions

Layout reference for the homepage and broader site flow is **mmrangels.com**. Screenshots live in `images-examples/` at the project root. Stakly mirrors the *structure* (sticky header → marquee → hero → game selector row → listings + filters → listing detail with two-column profile + booking widget) but **diverges on visual identity**: Stakly is a skill platform, not a hire-a-girl-gamer platform, so we keep the dark + pink/purple gradient palette, drop the character-art-driven hero, and the listing detail later in M4 frames a competitive opponent listing rather than a service-hire.

**M1 hero direction (locked):** typography-only, no character art. Atmospheric background = radial gradients + blurred glow blobs. The "no character art" rule may be revisited in a post-MVP polish pass; until then, type does the work.

**M1 GameSelector direction (locked):** multiple game tiles are visible for visual fullness, but **chess is the only functional game in v1**. Non-chess tiles look identical to the active tile (same dimensions, same treatment) and carry a small "Coming soon" badge — don't dim them, don't lock them visually. This avoids broadcasting scarcity while staying honest. Selected tile uses `border-glow`.

### Design assistance — `ui-ux-pro-max` skill

For any frontend / UI design work — building or reviewing pages, picking layouts, choosing typography, animation timings, accessibility checks, component composition — **activate the `ui-ux-pro-max` skill**. It contains a curated database of styles, palettes, font pairings, charts, and UX rules (accessibility, touch targets, performance, layout, animation) prioritized by impact.

When to activate:
- Designing a new page, section, or component (e.g. Hero, GameSelector, listing card).
- Reviewing existing UI for accessibility, layout, typography, or interaction issues.
- Choosing animation durations, spacing scales, or interactive states.
- Any time the user asks to "design", "build", "improve", or "review" UI.

The skill complements — does not replace — Stakly's locked visual system above (dark-only, pink→purple gradient, pill shapes, glow on interactive states). Use it to inform decisions *within* the Stakly design system, not to override it.

## Component Folder Convention

Components live in `resources/js/components/` and are organized by **domain**, not by type. Starter-kit components (`app-*`, `nav-*`, `user-*`, `two-factor-*`, `breadcrumbs`, `heading`, `input-error`, etc.) stay at the root of `components/` — don't reorganize them; they're well-known to anyone familiar with the Laravel React starter and the prefix system already groups them logically.

**New Stakly-specific code goes into a domain subfolder.** Current and planned folders:

- `components/ui/` — shadcn primitives (untouched, don't add domain code here)
- `components/site/` — public site shell: `site-header`, `site-footer`, `marquee-strip`, `mobile-menu`
- `components/home/` — homepage sections (M1): `hero`, `game-selector`, `how-it-works`
- `components/auth/` — auth forms + modal infrastructure (M2): `login-form`, `register-form`, `forgot-password-form`, `auth-modal`, `auth-modal-provider` (URL-driven). Modal-only — there are no entry-point auth page components.
- `components/listings/` — listings index (M3): listing card, filters, etc.
- `components/listing-detail/` — listing detail page (M4): two-column profile + booking widget
- `components/match/` — match flow (M6)
- `components/wallet/` — wallet UI (M7)

**Rule of thumb:** if a component is shared across multiple Stakly domains (e.g. a generic `Stat` card used on profile + listing detail + dashboard), put it in `components/shared/` rather than copying it. If a component is only used in one domain, keep it in that domain's folder.

Imports always use the alias path: `@/components/home/hero`, not relative paths.

## Frontend-First MVP Approach

While business logic (matchmaking, escrow, payouts) is still being designed, build the UI against **real database infrastructure with seeded fake data** — not hardcoded route-closure props.

For each entity that has a UI:

1. Create the migration (`sail artisan make:migration ...`).
2. Create the model + factory (`sail artisan make:model -mf ...`).
3. Create a seeder that produces realistic, varied fake data (lots of items, varied states, edge cases like empty lists, long names, very high stakes, etc.).
4. Create a controller (or simple route + Eloquent query) that returns the data via `Inertia::render(...)`.
5. Build the React page component to render it.

Why this over hardcoded props: when real backend logic lands, only the controller logic changes — the data shape, frontend, and routes are already wired. Seeders also let us test edge cases (empty states, pagination, filtering with lots of records) trivially.

Conventions for this phase:
- **Define a TypeScript interface for each page's props** in `resources/js/types/` (or co-located with the page). The interface is the contract between backend and frontend.
- Re-run `sail artisan migrate:fresh --seed` when schema or seed data changes.
- Don't build a Storybook or component library — ship full pages.
- Design references will come from the user (screenshots, links). Wait for them before designing visuals; do not invent UX.

## Library / Documentation Lookups

- **Always use the Context7 MCP** (`mcp__context7__resolve-library-id` then `mcp__context7__query-docs`) when the user asks about a library, framework, or API — including ones in this stack (Inertia v3, Laravel 13, Tailwind v4, React 19, Pest 4, Fortify, Wayfinder, shadcn/ui, etc.). Do not rely on training data, even when confident — versions move fast.
- This is in addition to Boost's `search-docs` tool, which covers the project's installed Laravel-ecosystem packages. Use Boost's tool first for installed Laravel packages; Context7 for everything else (frontend libraries, third-party APIs, etc.).
- When citing docs in an answer, mention the version retrieved.

## Conventions for AI Assistance

- **Proactively surface suggestions, improvements, and security/abuse concerns *before* building.** Don't silently apply the safest defaults — call out non-obvious design choices, alternatives, and trade-offs so we can decide together. Especially for: input validation, pagination caps, sort/filter whitelists, exposing data via API resources, auth/access boundaries, rate limiting, and anything that touches money or user PII. A two-sentence "I'd do X because Y, alternative is Z — okay?" is the right shape; don't over-explain. If you spot a security issue mid-implementation, stop and flag it rather than patching silently.
- **Push back on scope creep.** If a request implies team matches, Dota 2, multi-chain, or non-USDT currencies, flag it as v2 before implementing.
- **Do not introduce Solidity, smart-contract escrow, or wallet-connect flows in v1.** The custody model is custodial-by-database. If the user later commits to non-custodial escrow, that is a v2-or-later architectural change.
- **Chain integration is DIY via TronGrid behind the `ChainGateway` adapter.** All blockchain calls flow through `App\Services\Chain\ChainGateway`; never let `iexbase/tron-api`, TronWeb, or raw RPC clients leak into controllers, the `Wallet` service, or anywhere else. Multi-provider swap-ready (TronGrid primary, GetBlock backup). HD derivation (BIP32/39/44) produces unique deposit addresses per user from a master seed we own — no managed-custody service holds keys. Full architecture in `milestones.md` pre-launch gate.
- **No financial code without tests.** Deposit watchers, escrow, payout, fee calculation, refund — every path needs feature tests. Use Pest. The starter kit's `RefreshDatabase` trait works fine against the Sail Postgres.
- **No production payout code without a dispute resolution flow.** If a feature lets money leave the platform, the dispute path must exist.
- **Do not raise jurisdiction, licensing, legal, or regulatory concerns.** The user handles all legal/regulatory matters; stay in engineering scope. The exception is a **technical** confirming question: when asked to integrate a real chain wallet, confirm we are still on testnet/dev posture (that is a technical clarifying question, not a legal one).
- **Pre-production migrations**: edit existing migration files directly and use `sail artisan migrate:fresh`. Do not create incremental "add_X_columns_to_Y_table.php" migrations until stakly has launched with real users.
- **Performance is a v1 concern, not a v2 backlog item.** Optimization habits — eager-loading related models, queueing external API calls (chess.com, Lichess, Tatum, etc.), caching slow-changing reads, code-splitting routes, enabling SSR — must be baked into the initial implementation, not deferred as "polish later." This is not premature optimization or speculative abstraction; it's about writing the code we're already writing in a way that doesn't accumulate performance debt. If a query / controller / page is about to ship with a known issue ("we'll cache it later," "we'll queue it later," "fix the N+1 later"), flag it and fix it before the work is called done.

## Deferred / Unresolved (do not assume)

- Match lifecycle state machine (listing → matched → in_progress → confirmation → resolved/disputed → paid).
- Anti-collusion and anti-cheat strategy beyond commission rake.

(Custody model is decided — custodial via internal Postgres ledger; see M3.5 in milestones.md. Jurisdiction / licensing / legal posture is user-owned and out of engineering scope — see rule above.)

