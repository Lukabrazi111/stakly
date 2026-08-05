# Developer Guide

How to work in the Stakly codebase — the recipes, the conventions that aren't
obvious from reading one file, and the things that will bite you.

**Companion docs.** [`README.md`](../README.md) for setup and the tour.
[`architecture.md`](architecture.md) for how the system is shaped and why.
[`CLAUDE.md`](../CLAUDE.md) is the authoritative convention source.
[`billing.md`](billing.md) for money. [`milestones.md`](../milestones.md) for
what's in flight.

---

## 1. Before you start

**Everything runs through Sail.** The app lives in Docker; running `php` or `npm`
on the host hits the wrong PHP, the wrong Postgres, and the wrong Redis.

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan migrate:fresh --seed
vendor/bin/sail npm run dev
```

Prefix every `php`, `artisan`, `composer`, `npm`, and `pest` command with
`vendor/bin/sail`. The rest of this guide writes `sail` for brevity.

**Every URL carries a locale.** Web routes are wrapped in a `{locale}` prefix, so
the app lives at `http://localhost/en`, not `http://localhost`. A bare path 301s
via `RedirectUnprefixedLocale`. Dev login: `test@example.com` / `password`
(seeded with a $10,000 balance).

**Useful surfaces:** `/admin` (Filament), `/horizon` (queues),
`localhost:8025` (Mailpit).

---

## 2. Adding a feature end-to-end

The order below is not ceremony — each step makes the next one cheap, and the
frontend gets real data from step 3 onward rather than hardcoded props.

**Step 0 — write the milestone entry first.** Add the phase line + Goal + scope
to [`milestones.md`](../milestones.md) *before* writing code. Silent work is
invisible work. If the request fits no existing milestone, propose where it goes
and confirm before starting.

Then, following the withdrawal slice as a worked example:

| # | Step | Withdrawal slice |
|---|---|---|
| 1 | Migration | `database/migrations/2026_07_28_165820_create_withdrawals_table.php` |
| 2 | Model + factory | `app/Models/Withdrawal.php`, `database/factories/WithdrawalFactory.php` |
| 3 | Seeder | `database/seeders/WithdrawalSeeder.php` — one row per status, incl. edge cases |
| 4 | Service / Action | `app/Services/Withdrawals.php` — owns the lifecycle |
| 5 | Form Request | `app/Http/Requests/Wallet/WithdrawRequest.php` |
| 6 | Controller | `WalletController::withdrawStore()` — validate, call, redirect |
| 7 | Resource | `app/Http/Resources/WithdrawalResource.php` |
| 8 | TS contract | `resources/js/types/wallet.ts` |
| 9 | Page | `resources/js/pages/wallet/withdraw.tsx` |
| 10 | Tests | `tests/Feature/WithdrawalTest.php`, `tests/Feature/Wallet/WalletWithdrawTest.php` |

```bash
sail artisan make:model Withdrawal -mf --no-interaction
sail artisan make:request Wallet/WithdrawRequest --no-interaction
sail artisan make:test --pest WithdrawalTest
sail artisan migrate:fresh --seed        # after any schema or seeder change
```

While there are **no real users**, edit existing migration files in place and
re-run `migrate:fresh` — all 24 migrations are `create_*`. Switch to incremental
`add_x_to_y` migrations only once a deployed instance holds real data.

---

## 3. Backend recipes

### Writing an Action

```bash
sail artisan make:class Actions/Listing/CancelListingAction --no-interaction
```

Rules that matter:

- **One public `handle()`.** Decompose long bodies into private helpers so
  `handle()` reads like a recipe of named steps.
- **Return sentinels, don't throw**, for outcomes a user can legitimately hit.
  See [`architecture.md` §2](architecture.md#2-actions--the-orchestration-layer)
  for the full contract and why.
- **Document the contract in a docblock** — every sentinel, the idempotency key,
  the lock order. This is the one place comments are expected.
- **Own the transaction.** Wrap in `DB::transaction`, take row locks in a stable
  order (`->orderBy('id')->lockForUpdate()`), and re-read state *inside* the
  lock — never trust what the controller loaded.
- **Defer side effects.** Notifications go after commit; broadcast events
  implement `ShouldDispatchAfterCommit`; jobs use `ShouldQueueAfterCommit`.
- **Never authorize inside an Action.** Authorization is a Policy check in the
  controller or Form Request. Actions assume the caller already checked.

### Controllers stay thin

Validate via Form Request → call the Action → branch on its return → flash →
redirect. Nothing else.

```php
Inertia::flash('toast', ['type' => 'info', 'message' => __('…')]);

return to_route('listings.show', $listing);
```

`Inertia::flash('toast', …)` is the only flash mechanism — it's what
`use-flash-toast.ts` listens for on the client.

Known wart: request classes live under both `app/Http/Requests/GameMatch/` and
`app/Http/Requests/Match/`. Both are real and in use. Put new match request
classes in `GameMatch/` and leave the existing ones alone rather than inventing a
third spelling.

### Index endpoints

Use `spatie/laravel-query-builder` with explicit whitelists, plus a per-page
constant on the controller:

```php
private const MATCHES_PER_PAGE = 12;

QueryBuilder::for(GameMatch::class)
    ->allowedFilters([AllowedFilter::exact('status')])
    ->allowedSorts([...])
    ->paginate(self::MATCHES_PER_PAGE);
```

Never accept a client-supplied `per_page` without a cap, and never pass a raw
sort column through.

### Resources are whitelists

`app/Http/Resources/` shapes Inertia props. List fields explicitly; never
`$this->resource->toArray()`. These objects are how PII leaks, so a new field is
a deliberate decision. Use `whenLoaded()` for relations so a missing eager load
surfaces as an absent key rather than an N+1.

### Money

Read [`billing.md`](billing.md) before touching anything financial. The
non-negotiables:

- All balance writes go through `App\Services\Wallet`. Never write
  `users.usdt_balance` or insert `wallet_transactions` directly — not from
  controllers, seeders, factories, migrations, or tinker.
- Amounts are **positive BCMath strings** (`'100.000000'`). `Wallet` applies the
  sign from `WalletTransactionType`.
- Arithmetic is `bcadd` / `bcsub` / `bccomp` at scale 6. PHP `+`, `-`, `<` on
  money is forbidden. Floats only at the JSON boundary.
- Pass a `reference_id` on every call (`"match-take:{$listing->id}"`) — repeats
  return the existing row silently, which is what makes double-submits safe.
- **No financial code without tests.** Every deposit, escrow, payout, fee and
  refund path needs a Pest feature test asserting the invariant.

### Enums

Backed string enums in `app/Enums/`, TitleCase cases, snake_case values. Cast in
the model's `casts()`. Several implement Filament's `HasLabel` / `HasColor` so
the admin panel renders them without a mapping array — follow that when adding a
status enum that will surface in `/admin`.

---

## 4. Frontend recipes

### File naming

**Kebab-case files, everywhere, no exceptions.** `forceConsistentCasingInFileNames`
is on, so drift fails the build.

| File | Exports |
|---|---|
| `components/listings/listing-grid-card.tsx` | `ListingGridCard` (PascalCase) |
| `hooks/use-flash-toast.ts` | `useFlashToast` (camelCase) |
| `lib/wallet-format.ts` | camelCase helpers |
| `pages/wallet/withdraw.tsx` | default export `Withdraw` |

Use `.tsx` only when the file actually contains JSX — note `use-mobile.tsx` and
`use-initials.tsx` do, `use-clipboard.ts` doesn't.

shadcn's CLI writes lowercase files, which already matches. Skin primitives **at
the source** (`components/ui/<name>.tsx`) rather than per call site — see
`CLAUDE.md` for the token rules.

Components go in a **domain** folder (`components/wallet/`), not a type folder.

### Routing — Wayfinder

```tsx
import { index as listingsIndex } from '@/routes/listings';
import { store as withdrawStore } from '@/routes/wallet/withdraw';

router.get(listingsIndex().url, query, { preserveState: false });
<Link href={listingsCreate().url} prefetch>…</Link>
```

- **`@/routes/...` is the convention** (106 imports vs 3 for `@/actions`). Reach
  for `@/actions/App/Http/Controllers/...` only when you need the controller
  object itself, as the settings pages do.
- **Never hardcode a URL**, and **never pass `locale`** — `setUrlDefaults` in
  `app.tsx` injects it into every generated URL.
- Regenerating by hand needs the form flag, or `.form()` accessors silently
  vanish:

```bash
sail artisan wayfinder:generate --with-form   # or just: sail npm run build
```

### Forms

`<Form>` is the primary pattern — spread the route's `.form()` accessor:

```tsx
<Form {...store.form()}>…</Form>
```

`useForm` is for the few cases needing programmatic state (`listings/create.tsx`,
`settings/profile.tsx`, `settings/notifications.tsx`,
`components/wallet/withdraw-form.tsx`, which also uses `transform()`).
`useHttp` is for requests that shouldn't navigate — currently only
`hooks/use-two-factor-auth.ts`.

### Layouts — two mechanisms, split by route

1. **Resolver in `app.tsx`** — applies to `auth/*` and `settings/*` only:

```tsx
layout: (name) => {
    switch (true) {
        case name === 'welcome':           return null;
        case name.startsWith('auth/'):     return AuthLayout;
        case name.startsWith('settings/'): return [SiteLayout, SettingsLayout];
        default:                           return null;
    }
}
```

Those pages pass layout props via `setLayoutProps({ title, description })` or a
static `Page.layout = { title, description }` object.

2. **JSX wrapping inside the page** — everything else, and the default for new
pages:

```tsx
return <PlayerHubLayout>…</PlayerHubLayout>;
```

`pages/users/show.tsx` picks its layout conditionally
(`isOwnProfile ? PlayerHubLayout : SiteLayout`) — that's fine and sometimes right.

### Flash → toast

Server flashes `toast`; `use-flash-toast.ts` subscribes to Inertia v3's
`router.on('flash')` (not `usePage().props.flash`) and calls **sonner**. The hook
is invoked in exactly two places — `site-layout.tsx` and `auth-layout.tsx` — so a
page under a third layout would silently show no toasts.

### i18n

```tsx
const t = useT();
<h1>{t('Reset password')}</h1>
<p>{t('Welcome back, :name', { name: user.name })}</p>
```

Flat English-key JSON in `lang/{en,ka,ru}.json`; only the active locale's bag
ships to the client. **Gotcha:** build translated arrays *inside* the component,
never at module top level — module scope evaluates once, before the locale is
known.

### Real-time

```tsx
useEcho<LobbyUpdatedPayload>(`lobby.${listingId}`, '.lobby.updated', () => {
    router.reload({ only: ['lobby'] });
});
```

Treat the broadcast as a **trigger**, then re-fetch props. Only match chat
consumes the payload directly, because the message body *is* the payload.
`useEchoNotification` drives the notification bell.

### Prop types

- Shapes mirroring a backend resource → `resources/js/types/*.ts`, barrel-exported
  from `types/index.ts`. `types/listings.ts` opens with a comment naming its
  backend source of truth; follow that.
- Local UI plumbing → co-located `interface Props` in the component file.

### React specifics

- **React Compiler is enabled**, so manual `useMemo` / `useCallback` is not the
  norm. Don't add them reflexively.
- **`ssr.tsx` mirrors `app.tsx` minus `configureEcho`.** Echo's WebSocket client
  crashes under Node. If you add a provider to `app.tsx`, add it to `ssr.tsx`
  too — and if it touches `window`, guard it.
- Three animation systems with distinct jobs (CSS transitions / `tw-animate-css`
  for Radix `data-state` / `motion` for React-state-driven). See `CLAUDE.md`.

---

## 5. Testing

Pest 4 against the real Sail Postgres.

```bash
sail artisan test --compact                          # everything
sail artisan test --compact --filter=WalletTest      # one file
sail artisan make:test --pest SomeFeatureTest        # new feature test
sail artisan make:test --pest --unit SomeUnitTest    # new unit test
```

**Structure.** 150 feature tests, 6 unit tests. Organized **by domain**
(`tests/Feature/Wallet/`, `Lobby/`, `Jobs/`, `Services/Provider/`, `Admin/`, …),
not by layer — there is no `tests/Feature/Actions/`. Put a new test next to the
domain it exercises. Reach for `tests/Unit/` only for pure logic with no DB.

`RefreshDatabase` is applied **globally to `Feature` only** via `tests/Pest.php`,
along with `withoutVite()`.

### Reuse the global helpers

`tests/Pest.php` defines helpers that new tests should use rather than
re-rolling setup:

| Helper | Gives you |
|---|---|
| `platformUser()` | The `is_platform` user — required before any `Wallet::fee()`. `RefreshDatabase` doesn't run seeders, so this is not optional |
| `pendingMatch('100')` | `[$creator, $taker, $listing, $match]` with both stakes escrowed and both users funded |
| `mockGameApi()` | The `MockGameApi` singleton, reset — then `forceWinner()` / `forceDraw()` / `forceUnknown()` |
| `lichessGameFixture()`, `chessComArchiveFixture()`, `chessComPgnFixture()`, `faceitMatchFixture()`, `faceitHistoryFixture()`, `faceitOpposingRosterFixture()` | Realistic provider payloads for `Http::fake()` |

Use factory states before setting attributes by hand — `UserFactory::active()`,
`withLichess()`, `withChessCom()`, `withFaceit()`.

### The locale-prefix trap

`tests/TestCase.php` sets `$autoLocalePrefix = true` and overrides `call()` /
`json()` to prepend `/en/`, so ~300 existing tests keep using unprefixed literals:

```php
$this->get('/wallet/deposit');        // actually requests /en/wallet/deposit
$this->withoutLocalePrefix()->get('/admin');   // opt out
```

Paths whose first segment is in `TestCase::EXEMPT_FIRST_SEGMENTS` (`admin`,
`login`, `webhooks`, `horizon`, …) are never prefixed. That list is **manually
kept in sync** with `RedirectUnprefixedLocale::EXEMPT_FIRST_SEGMENTS` — if you
add a route outside the locale group, update both.

### Money tests

Assert the invariant, not just the balance:

```php
expect($user->fresh()->usdt_balance)
    ->toEqual(WalletTransaction::where('user_id', $user->id)->sum('amount'));
```

Don't write verification scripts or tinker snippets when a test can prove it, and
don't delete tests without asking.

---

## 6. Running things

Sail services in `compose.yaml`:

| Service | Role |
|---|---|
| `laravel.test` | The app |
| `pgsql` | Postgres 18 (init script creates the `testing` database) |
| `redis` | Cache, queue, session |
| `queue` | `queue:listen` worker |
| `scheduler` | `schedule:work` — cron equivalent |
| `reverb` | WebSocket server, port 8080 |
| `lichess-stream` | Long-lived Lichess game-end consumer |
| `mailpit` | SMTP sink + dashboard on 8025 |
| `ssr` | Inertia SSR sidecar — profile-gated, needs `npm run build:ssr` first |

### Dev recipes

```bash
# Rebuild all fixtures from scratch
sail artisan migrate:fresh --seed

# Watch queue work land
sail artisan horizon           # or open /horizon

# Tail application logs
sail artisan pail

# Force an auto-fetch sweep instead of waiting for the cron
sail artisan stakly:auto-fetch-pending

# Expire listings now
sail artisan listings:expire

# Inspect routes for one area
sail artisan route:list --path=wallet --except-vendor

# Prove the ledger invariant against seeded data
sail artisan test --compact --filter=WalletTest
```

---

## 7. Code quality

There are **no git hooks and no static analysis** in this repo. Pint, ESLint,
Prettier and `tsc` are the gates, and CI is what enforces them — run them
yourself before pushing.

```bash
sail bin pint --dirty --format agent    # PHP formatting (always after PHP edits)
sail npm run lint                       # ESLint --fix
sail npm run format                     # Prettier
sail npm run types:check                # tsc --noEmit
sail composer ci:check                  # everything CI runs
```

CI is `.github/workflows/lint.yml` (Pint, Prettier, ESLint, `tsc`, preceded by
`wayfinder:generate --with-form`) and `.github/workflows/tests.yml` (PHP 8.5,
Postgres 18, Pest).

Never auto-commit or push — the user commits.

---

## 8. Conventions

### PHP

- Curly braces always, even for one-line bodies.
- Constructor property promotion: `public function __construct(private readonly Foo $foo) {}`.
- Explicit return types and parameter types on every method.
- PHPDoc over inline comments; array-shape annotations in docblocks.
- **No `declare(strict_types=1)`.** It appears zero times in this codebase and
  the Pint `laravel` preset doesn't add it — don't introduce it in one file.
- Comments are scarce and small. Default to none; add one when the *why* is
  non-obvious (a lock order, a race, a library gotcha). Action contract docblocks
  are the deliberate exception.

### TypeScript

ESLint enforces some things that will bounce your first PR if you don't expect
them:

- `consistent-type-imports` with `separate-type-imports` — type imports get their
  own `import type { X } from '…'` line.
- `import/order` — builtin → external → internal → parent → sibling → index,
  alphabetized, case-insensitive.
- `@stylistic/padding-line-between-statements` — a blank line before `return`,
  `if`, `for`, `while`, `switch`, `try`, `throw`.
- `curly: all` and `brace-style: 1tbs` with no single-line blocks.
- Unused vars error unless prefixed `_`.

Generated and vendored paths are ignored: `resources/js/{actions,routes,wayfinder}/**`
and `resources/js/components/ui/*`.

### Docs

Only create documentation files when asked. `milestones.md` is the exception —
update it *before* coding.

---

## 9. Gotchas

1. **The `/en` prefix.** Missing it in a browser gets you a 301; missing it in a
   hand-built link gets you a 404. Tests auto-prefix — see §5.
2. **`wayfinder:generate` without `--with-form`** silently drops `.form()`
   accessors and breaks every `<Form>` that spreads one. Prefer `npm run build`.
3. **Echo is client-only.** Anything added to `app.tsx` that touches `window`
   must be guarded or omitted from `ssr.tsx`.
4. **`RateLimited` job middleware consumes `$tries`.** Adding it to a job without
   widening `$tries` means the job can exhaust its attempts on throttle releases
   alone, never reaching the provider.
5. **`platformUser()` before `Wallet::fee()`.** `RefreshDatabase` doesn't seed, so
   a settlement test without it fails on a missing platform user.
6. **Migrations are edited in place** while there are no real users. Don't add
   `add_column_to_*` migrations yet; edit the `create_*` file and re-run
   `migrate:fresh --seed`.
7. **`Wallet` is the only balance writer.** A direct `usdt_balance` update breaks
   the invariant that `WalletTest` asserts, and the failure surfaces far from the
   line that caused it.
8. **Frontend changes not showing?** `sail npm run dev` or `sail npm run build` —
   Vite manifest errors mean the build is stale.
