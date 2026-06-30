<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- filament/filament (FILAMENT) - v5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/socialite (SOCIALITE) - v5
- laravel/wayfinder (WAYFINDER) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- @laravel/echo-react (ECHO_REACT) - v2
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3
- tailwindcss (TAILWINDCSS) - v4

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

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
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

## Current scope

What we're working on right now. Not a commitment — decisions are revisitable any time. Just the present state so you know what's in-flight.

- **Game**: chess only (via chess.com / Lichess APIs).
- **Format**: 1v1.
- **Currency**: USDT.
- **Chain**: TRC20 (Tron USDT) is the planned starting chain. M9 chain integration will use **NowPayments** as the payment gateway (deposits + withdrawals via their custodial API + webhooks); not wired yet.
- **Platform fee**: `config('stakly.platform_fee_rate')` is currently flat. A tier mechanic (by stake size / rating / fill time / etc.) is **under discussion** — not decided yet; revisit with the user before assuming any particular structure (relevant when M28 fees page or fee logic changes come up).
- **Solo developer.**

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
- **Outcome verification is API-driven end to end.** Players link their chess.com / Lichess account at signup with verified ownership (bio-code challenge). A scheduled job queries the provider's API after a match, finds the game by username + timestamp, and posts a system-message result card to chat; settlement auto-fires from the card. The API is the source of truth — no "I confirm I won" step. Disputes (wrong result, or missing card) re-query the API and either auto-settle or route to admin `ManualReview`. Pending matches that time out without an API result also route to `ManualReview`. Screenshots + manual resolution are last-resort.
- **All financial state lives in a Postgres ledger.** The chain is the rail; the database is the source of truth. Every state transition (deposit, escrow, refund, payout, fee) is an immutable ledger entry. Money operations must be transactional and idempotent.
- **Auth UX — modal-only for entry points, pages for destinations, URL-driven.** Login, register, forgot-password are exclusively a shadcn `Dialog` triggered via `useAuthModal()` (provider in `app.tsx`, modal rendered in `SiteLayout`). The `?auth=login|register|forgot-password` query param is the source of truth for modal state; the provider syncs with `popstate` so back-button works. Fortify view callbacks for these three redirect to `/?auth=*` — there are **no** `/login`, `/register`, `/forgot-password` page components. Reset-password, verify-email, two-factor-challenge, confirm-password remain **page-only** (users land there from email links / post-auth redirects).
- **Fortify is the auth foundation.** TOTP 2FA is already wired. SMS OTP, email OTP, magic links, passkeys, and social login are all reachable as extensions (custom columns + middleware + provider) — not active today, easy to add later.
- **Three animation systems.** (1) **CSS transitions** (`transition-colors`, `hover:shadow-glow-sm`) for hover/focus/simple state changes — never wrap clickables in `motion.*` just for hover. (2) **`tw-animate-css`** (`data-[state=open]:animate-in fade-in-0`) for Radix/shadcn primitives driven by `data-state` (Sheet, Dialog, Popover, Tooltip) — don't replace with motion or we lose focus management + scroll-lock + a11y. (3) **`motion`** (`AnimatePresence`, `motion.div layout`, spring physics) for React-state-driven enter/exit, layout, sequenced reveals.

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
- `shadow-glow` / `shadow-glow-sm` — magenta glow halos for hover/focus/selected. Tuned soft (`0 0 18px -7px`) so they read as haze, not a block.
- `border-glow` — magenta border with inner glow. **Static decoration only** (auth modal, profile dropdown, link-account banner). For interactive selected states, use solid `border-primary` — `border-glow` reads too softly as an active cue.

### Shadow tokens (CSS variables)

Component-specific shadow values live as CSS variables in `:root, .dark` in `app.css`, referenced inline via `shadow-[var(--token-name)]` or `[text-shadow:var(--token-name)]`. Tune by editing the variables. When bumping a glow for a specific surface, **add a new variable** — don't edit the global utility (would leak to 15+ consumers).

- `--shadow-button-glow` / `--shadow-button-glow-hover` — used by the `gradient` button variant.
- `--shadow-arena-card-glow` — beefier halo for the homepage `GameSelector` tiles (hover + selected).
- `--text-shadow-glow` — soft white text-shadow used by the `ghost` button variant on hover.

**Ghost button hover:** Stakly ghost variants use white **text-shadow** on hover, NOT `bg-accent`/`bg-muted`. If you need bg-accent hover (sidebar nav, dropdown items), introduce a new variant — don't revert the global `ghost`.

### Fonts

- `font-sans` (Inter Variable) — body, UI, paragraphs
- `font-display` (Bricolage Grotesque Variable) — headlines and large numbers. Use heavy weights (700/800) for display impact.

### General visual rules

- Pill everything: buttons, badges, chips, search bars → `rounded-full` or `rounded-lg`.
- Avoid hard right angles on top-level UI; soften with at least `rounded-md`.
- Glow is for interactive states (hover/selected/focus), not static — overuse kills the meaning.
- No character art for now. Hero uses gradient + typography + abstract atmosphere. Avatars are initials or generated.
- **Stakly-skin shadcn primitives at the source (`components/ui/<name>.tsx`)**, not per-call. Defaults like `bg-accent`/`bg-muted` hover and chunky `ring-[3px] ring-ring/50` focus rings leak upstream shadcn palette. Replace with:
  - Hover/focus bg → **`bg-primary/10`** (translucent pink wash)
  - Selected/active → **`bg-primary/15 text-foreground border-primary/40`**
  - Hovered icon color → **`hover:[&_svg]:!text-primary`** (the `!` is needed; shadcn's icon selectors have higher specificity)
  - Focus ring → **`ring-2 ring-primary/25 border-primary/40`**
  - Surface bg for inputs/triggers/cards → **`bg-card/60`** or `bg-card/95` with `border-border/60`
  - Radius → match context; `rounded-md` or `rounded-xl` for popovers / sheets / dropdowns
- Per-usage overrides are fine (e.g. `bg-destructive/10` Log-out hover at the call site), but never re-introduce `bg-accent`/`bg-muted` as a primitive-level hover default.

### Site layout + catalog conventions

Layout reference is **mmrangels.com** (screenshots in `images-examples/`). Stakly mirrors the structure (sticky header → marquee → hero → game selector → listings + filters → listing detail with two-column profile + booking widget) but diverges on identity — dark pink/purple gradient palette, typography-only hero with radial gradients + blurred glow blobs (no character art), competitive opponent framing on listing detail.

**GameSelector catalog:** tile list is DB-backed via `App\Models\Game` (admin at `/admin/games`), not a hardcoded array. `App\Enums\Game` is the backend identity for games with real integration; admin can add `ComingSoon` display tiles without an enum case, but flipping one `Active` requires the enum addition. Tiles look identical regardless of status — small "Soon" badge for non-Active, don't dim or lock visually. **Chess is the only Active game today.** Selected tile uses solid `border-primary` + `shadow-[var(--shadow-arena-card-glow)]`, NOT `border-glow`.

### Design assistance — `ui-ux-pro-max` skill

For any UI design work (building, reviewing, layout, typography, animation timing, accessibility, component composition), **activate the `ui-ux-pro-max` skill**. It complements Stakly's visual system above — use it to inform decisions within the design system, not override it. Flag meaningful divergences.

## Component Folder Convention

Components in `resources/js/components/` are organized by **domain**, not by type. Starter-kit components (`app-*`, `nav-*`, `user-*`, `two-factor-*`, `breadcrumbs`, `heading`, `input-error`) stay at the root — don't reorganize.

**New Stakly code goes into a domain subfolder:**

- `components/ui/` — shadcn primitives (don't add domain code)
- `components/site/` — public site shell (`site-header`, `site-footer`, `marquee-strip`, `mobile-menu`)
- `components/home/` — homepage sections
- `components/auth/` — auth forms + modal infrastructure (modal-only)
- `components/listings/` — listings index
- `components/listing-detail/` — listing detail page
- `components/match/` — match flow
- `components/wallet/` — wallet UI

Shared across multiple domains → `components/shared/`. Imports use the alias path (`@/components/home/hero`), not relative.

## Frontend-First Approach

Build UI against **real DB infrastructure with seeded fake data**, not hardcoded route-closure props. For each entity:

1. Migration (`sail artisan make:migration ...`).
2. Model + factory (`sail artisan make:model -mf ...`).
3. Seeder with realistic, varied data (edge cases: empty lists, long names, very high stakes).
4. Controller returning data via `Inertia::render(...)`.
5. React page component.

When real backend logic lands, only the controller changes — data shape, frontend, routes are already wired.

- **Define a TypeScript interface for each page's props** in `resources/js/types/` (or co-located). The interface is the FE↔BE contract.
- Re-run `sail artisan migrate:fresh --seed` when schema or seeds change.
- Don't build Storybook — ship full pages.
- Design references come from the user (screenshots, links). Wait for them; do not invent UX.

## Browser verification (Playwright MCP)

The **Playwright MCP** is the agent-driven, interactive browser for verifying the *running* app — ad-hoc and throwaway (not committed, not CI; `.playwright-mcp/` is gitignored). The app runs under Sail at `http://localhost/en` (locale-prefixed); dev login `test@example.com` / `password`. Reach for it whenever the question is "how does it look / behave in the browser?":

- **Responsive / visual** passes (render at 375 / 768 / 1024), design QA, reproducing a visual bug.
- **Console errors / JS exceptions** (`browser_console_messages`) — runtime React / Inertia / hydration errors a green backend test never surfaces.
- **Network** (`browser_network_requests`) — confirm Inertia visits, prop payloads, a 419 / 500 on a form post, a wrong Wayfinder URL.
- **Full-flow walkthroughs** (login → take listing → match) + **real-time** checks via two tabs (`browser_tabs`) — the only practical way to confirm Reverb / Echo re-renders the *other* client.

**Not a replacement for Pest** — logic, money, settlement, ledger invariants stay in Pest (deterministic). Committed browser E2E would be Pest 4 browser testing, not this MCP. Gotcha: Inertia prefetches on hover, so navigate explicitly + `browser_wait_for` to stay deterministic.

## Library / Documentation Lookups

- **Use Context7 PROACTIVELY.** Before writing/editing code that uses any library/framework/API, call `mcp__context7__resolve-library-id` then `mcp__context7__query-docs`. Don't rely on training data even when confident — versions move fast. Applies to subclassing, extending, or wiring packages together.
- Boost's `search-docs` is a quick complementary lookup for installed Laravel packages; Context7 remains authoritative.
- Cite the doc version when answering.
- Skip Context7 only for pure-language syntax (PHP / TypeScript) with no framework surface, or when you verified the same API earlier in this session.

## Conventions for AI Assistance

- **Update `milestones.md` BEFORE coding.** Every new work item starts as a phase entry (e.g. `M34 P6 — dispute / cancellation buttons in team lobby`) in `milestones.md` — add the phase line + Goal + scope FIRST, then write code. Mark phase status as work progresses; archive to `milestones_archived.md` when done. The doc is how the user tracks done vs. left — silent work is invisible. If a request doesn't fit any existing milestone, propose where it goes (new phase under an active milestone, or a new milestone) and confirm with the user before starting.
- **Default to production-grade.** Don't trim scope on "solo dev" / "pre-launch" grounds. Lead with the more-correct option; surface trade-offs honestly. If a milestone defers something on solo-dev grounds, rephrase the rationale as the actual technical reason (different milestone, downstream dependency) or include it.
- **Choose tech on merit, not speed-to-ship.** If the better-fit infrastructure already exists, default to it. Concrete cases: **Reverb broadcasting over polling** for any live state (lobby, chat, notifications, match) — already wired; **DB-backed seeded data** over hardcoded route-closure props; **Action classes** over inline controller logic; **Wallet service** over any direct balance write; **Form Requests** over inline `$request->validate(...)` for non-trivial input; **framework primitives** (cache tags, queue middleware, policy gates) over hand-rolled. Watch out for "ship it simple" / "6 lines vs 60" / "revisit later" — usually wrong framing. If the shortcut is truly right (one-shot, throwaway), surface it explicitly.
- **Proactively surface suggestions, improvements, security/abuse concerns *before* building.** Don't silently pick the safest default — flag non-obvious design choices in 2 sentences ("I'd do X because Y, alt is Z — okay?"). Especially for: input validation, pagination caps, sort/filter whitelists, API resources, auth/access boundaries, rate limiting, money, PII. If you spot a security issue mid-implementation, stop and flag.
- **Frame decisions as structured questions with a recommendation, not prose paragraphs.** When something needs discussing or deciding (a design fork, a trade-off, ambiguous scope, "which approach?"), pose it as concrete question(s) with 2–4 answer options each and **mark the one you recommend** (one-line why) — the `AskUserQuestion` format — so we walk the choices together *before* building. Don't bury the options in prose.
- **Flag bigger asks, don't refuse them.** Team matches, Dota 2, multi-chain, non-USDT — surface the added surface area (schema, abuse, time) so we can weigh together. No scope-grounds rejection.
- **Don't add Solidity, smart-contract escrow, or wallet-connect flows without explicit go-ahead.** Current model is custodial-by-database; switching is a real architectural change.
- **Chain integration (M9) — NowPayments selected as the payment gateway; not wired yet.** Until then: `users.tron_address` is populated by `App\Support\MockTronAddress` (placeholder, not on-chain), the deposit page shows the mock address, and `WalletController::withdrawStore` short-circuits with a notice toast (no ledger write). No NowPayments client / webhook receiver / API-key wiring without explicit go-ahead. (Provider is custodial — they hold keys; our side will be API client + webhook receiver only. No Solidity / on-chain signing / key-storage code.) The internal ledger (`wallet_transactions` + `App\Services\Wallet`) is provider-agnostic and stays as source of truth.
- **No financial code without tests.** Deposit watchers, escrow, payout, fee, refund — every path needs Pest feature tests. `RefreshDatabase` works against Sail Postgres.
- **All money writes go through `App\Services\Wallet`.** `users.usdt_balance` and `wallet_transactions` are written ONLY by Wallet methods (`deposit`, `withdraw`, `hold`, `release`, `payout`, `fee`). Never write the balance directly from controllers, seeders, migrations, factories, or tinker — breaks the invariant `users.usdt_balance == SUM(wallet_transactions.amount)` (asserted in `WalletTest.php`). Platform rake credits the seeded `is_platform = true` user via `Wallet::fee(...)`, not a nullable `user_id`. Idempotency: every Wallet call accepts an optional `reference_id` — repeats return the existing row silently.
- **Money math is BCMath strings, not floats.** Pass amounts as positive strings (`'100'`, `'100.000000'`) — Wallet applies sign per `WalletTransactionType`. Internal arithmetic uses `bcadd` / `bcsub` / `bccomp` at scale 6 (matches Tron USDT + `decimal(18, 6)`). PHP `+` / `-` / `<` on money is forbidden. Floats only at the API resource boundary (e.g. `(float) $this->stake_amount` in `ListingResource`).
- **No production payout code without a dispute resolution flow.** If money can leave the platform, the dispute path must exist.
- **Do not raise jurisdiction, licensing, legal, regulatory concerns.** The user handles all legal matters; stay in engineering scope. Exception: when asked to integrate a real chain wallet, confirm we're still on testnet/dev (technical clarifying question, not legal).
- **Migrations while there are no real users:** edit existing migration files directly and `sail artisan migrate:fresh`. Once a deployed instance has real users, switch to incremental "add_X_columns_to_Y_table.php" migrations.
- **Wayfinder regen via artisan needs `--with-form`.** Vite's wayfinder plugin generates `.form` accessors automatically on `npm run dev` / `npm run build`. `vendor/bin/sail artisan wayfinder:generate` defaults to NO form variants and silently breaks `<Form>` spreading `.form()`. Always pass `--with-form` when using artisan, or just use `npm run build`.
- **Performance is always a concern.** Eager-load related models, queue external API calls, cache slow reads, code-split routes, enable SSR — baked into initial implementation, not deferred. If a query/controller/page is about to ship with a known issue ("we'll cache it later," "fix the N+1 later"), flag and fix before calling it done.
- **Self-throttle every outbound third-party API call.** Apply client-side rate limiting on every external integration (chess.com, Lichess, FACEIT, future chain provider, mail). Use `RateLimiter::for(...)` + `RateLimited` job middleware. Conservative per-minute cap (default **30 req/min** when the real limit is undocumented) in `config/services.php`. Layer with: 429 handling via `RateLimitHeaderParser` (`Retry-After` / `X-RateLimit-Reset`) AND a per-provider `ProviderCircuitBreaker`. **Why:** a production 429 trips the breaker, freezing settlement for every Pending match in that provider's pipeline until it recloses. A 3-min latency burst beats total settlement freeze. New integrations ship with all three (throttle + 429 + breaker), not retrofit.
- **High-stakes-milestone mode.** When the user flags work as critical / money-touching — OR when the work touches Wallet, settlement / escrow / payout, dispute resolution, the outcome pipeline (auto-fetch jobs / `GameApi` adapters / system card schema), webhook receivers, OAuth, key/secret storage, admin impersonation, or any new third-party integration — switch to stricter mode. Don't ship a slice the same session a meaningful question was open at the start. Run Context7 + `search-docs` + relevant skill on every API touchpoint, even known ones. Surface every non-trivial default (pagination caps, retry budgets, idempotency keys, time windows, exposed fields, error classification, anti-abuse) for explicit sign-off. Walk through the attacker view at every endpoint, job, or external call — webhook auth, replay, rate limits, scope leaks, identity confusion, races, snapshot drift — and call them out even if the mitigation is "nothing yet because X". Prefer interruption to wrong assumption.
- **Comments are scarce and small.** Default to no comments. Add one only when the WHY is non-obvious (hidden constraints, subtle invariants, library gotchas, surprising behavior). Skip task tags, docblocks on self-explanatory props, callsite references, historical context — those belong in commit messages, not files. One short line beats a paragraph.
- **Wrap up tasks human-first.** When a task / slice finishes, lead with 2–3 plain-language sentences about what changed in product terms (what users can do now, what's safer, what's fixed) — not a files-changed dump. Keep the commit title + a brief manual test plan; skip the verbose files-list + design-choices sections (that detail belongs in the milestone entry, not the end-of-task message).
