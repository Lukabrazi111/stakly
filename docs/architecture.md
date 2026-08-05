# Architecture

How Stakly is shaped: the layers, what each one is allowed to know, and the four
spines that carry the product — money, outcomes, real-time, and third-party
providers.

**Companion docs.** [`README.md`](../README.md) is the index and setup guide.
[`CLAUDE.md`](../CLAUDE.md) is the authoritative convention source.
[`developer-guide.md`](developer-guide.md) is how to *work* in the codebase —
recipes, testing, gotchas. [`billing.md`](billing.md) owns the money system in
detail; this doc points at it rather than restating it.

---

## What this codebase is

| Property | Reality |
|---|---|
| Rendering | Inertia v3 + React 19, server-driven. SSR via a Node sidecar |
| HTTP surface | **Web only** — there is no API layer, no `routes/api.php`, no versioning |
| Business logic | Action classes, one verb per class |
| Shared capabilities | Services — mostly static facades, **not** `*Service` suffixed |
| Money | Append-only Postgres ledger; `App\Services\Wallet` is the sole writer |
| Outcomes | Fetched from game-provider APIs; never self-reported by players |
| Real-time | Laravel Reverb + Echo |
| Admin | Filament 5 at `/admin` |
| Routing | Every web route is locale-prefixed (`/en/...`) |

Stakly is **custodial-by-database**. No smart contracts, no on-chain game logic,
no wallet-connect. Crypto is a deposit/payout rail; Postgres is the truth.

---

## 1. Layer map

```
┌──────────────────────────────────────────────────────────────────┐
│  React pages + components          resources/js/                 │
│  UI state, forms, Wayfinder URLs. No business logic.             │
└───────────────┬──────────────────────────────────────────────────┘
                │  Inertia visit  ·  Reverb push ▲
                ▼                                │
┌──────────────────────────────────────────────────────────────────┐
│  Form Request      →   Controller        app/Http/               │
│  validation +          thin: call Action, branch on its return,  │
│  authorize()           flash a toast, redirect. Nothing else.    │
└───────────────┬──────────────────────────────────────────────────┘
                ▼
┌──────────────────────────────────────────────────────────────────┐
│  Action                                  app/Actions/<Domain>/   │
│  One use-case. Owns the transaction, the lock order, the         │
│  business rules. Dispatches Jobs + Events. Returns a model or    │
│  a sentinel string.                                              │
└──────┬────────────────────────────┬───────────────────┬──────────┘
       ▼                            ▼                   ▼
┌──────────────┐        ┌────────────────────┐   ┌──────────────┐
│  Services    │        │  Jobs (queued)     │   │  Events      │
│  Wallet      │        │  AutoFetch*Job     │   │  ShouldBroad │
│  GameApi     │◀───────│  Refresh*Job       │   │  castAfter   │
│  Provider/*  │        │  ProcessWithdrawal │   │  Commit      │
└──────┬───────┘        └─────────┬──────────┘   └──────┬───────┘
       ▼                          ▼                     │
┌──────────────────────────────────────────────┐        │
│  Eloquent models          app/Models/         │        │
│  Postgres — ledger, match state, chat         │        │
└───────────────────────────────────────────────┘        │
                                                         ▼
                                                    Reverb → client
```

### Who knows about whom

| Layer | May depend on |
|---|---|
| React page | Wayfinder routes, shared props, TS types |
| Controller | Form Request, Action, Resource, Policy, Model (simple reads for props) |
| Action | Service, Model, Job, Event, Enum, Exception, other Actions |
| Job | Service, Model, Action, Enum |
| Service | Model, Enum, DTO, other Services |
| Model | Enum, Observer |

Two honest deviations from the textbook version, both deliberate:

- **Actions may call other Actions.** `TakeListingAction` injects
  `PostSystemMessageAction`; `SettleFromCardAction` composes the settle actions.
  Extracting these into a Service would produce a Service with one caller.
- **Jobs may call Actions.** `AutoFetchLichessGameJob` calls
  `RecordAutoFetchAttemptAction` and `SettleFromCardAction`. Console commands do
  the same — they are thin wrappers that inject an Action and return its exit
  code.

The rule that *is* enforced everywhere: **controllers and React pages contain no
business logic.**

---

## 2. Actions — the orchestration layer

`app/Actions/<Domain>/<Verb><Noun>Action.php`. 38 files across `Admin`,
`Fortify`, `GameMatch`, `LinkedAccount`, `Listing`, `Lobby`, `Message`,
`Profile`, with nested `Admin/` sub-namespaces for admin-only variants.

Every domain Action has a **single public `handle()`** and constructor-promoted
`private readonly` dependencies:

```php
class TakeListingAction
{
    public function __construct(
        private readonly PostSystemMessageAction $postSystem,
    ) {}

    public function handle(User $user, Listing $listing): GameMatch|string
```

Two deliberate exceptions:

- `app/Actions/Fortify/CreateNewUser.php` and `ResetUserPassword.php` implement
  Fortify contracts, so they match Fortify's method names, not ours.
- `RefreshDisplayedRatingsAction` exposes `forListings()` / `forAccounts()` and
  has no `handle()` — it is a batch decorator over two collection shapes.

### The sentinel-return contract

This is the house pattern and the thing most likely to surprise someone arriving
from another Laravel codebase. **Actions do not throw for expected failures.**
They return a union of the success model and a set of documented sentinel
strings; the controller maps each sentinel to a toast and a redirect.

`app/Actions/GameMatch/TakeListingAction.php` returns `GameMatch|string` with
sentinels `not_linked`, `not_takeable`, `race_lost`, `owner_inactive`,
`already_in_match`, `owner_busy`. Its consumer,
`app/Http/Controllers/GameMatchController.php`:

```php
public function take(TakeRequest $request, Listing $listing, TakeListingAction $action): RedirectResponse
{
    try {
        $result = $action->handle($request->user(), $listing);
    } catch (InsufficientBalanceException) {
        throw ValidationException::withMessages([
            'amount' => __('Stake exceeds your available balance.'),
        ]);
    }

    if ($result === 'not_linked') {
        Inertia::flash('toast', [
            'type' => 'info',
            'message' => __('Link a :platform account before taking this match.', [
                'platform' => $listing->platform->displayName(),
            ]),
        ]);

        return to_route('linked-accounts.edit');
    }
    // … one branch per sentinel …
}
```

**Why sentinels rather than exceptions:** these are not errors. Losing a race for
a listing is a normal outcome of a marketplace with concurrent buyers, and each
outcome needs its own copy and its own redirect target. Exceptions would push
that mapping into a handler far from the flow it describes.

Three escape hatches remain, each with a distinct meaning:

| Mechanism | When | Example |
|---|---|---|
| Sentinel string | Expected, user-facing outcome | `race_lost` |
| `abort(403)` | Only reachable by a crafted request — the UI never offers it | self-take in `TakeListingAction` |
| Exception | A genuine invariant breach or race below the business layer | `InsufficientBalanceException` from `Wallet` |

### The contract docblock

Every non-trivial Action carries a docblock enumerating its sentinels, its
idempotency key, and its lock order. This is the one place the codebase spends
comment budget freely, because the contract is not inferable from the signature:

```php
/**
 * Takes an open listing: escrows the taker's stake and creates the match
 * row, all inside one `DB::transaction` with a row lock on the listing.
 *
 *   - `GameMatch` instance → success; controller redirects to match page.
 *   - `'not_linked'`       → taker has no verified chess provider account …
 *   - `'race_lost'`        → listing state changed between page load + submit …
 *
 * Idempotency: `Wallet::hold` is keyed `match-take:{listing_id}`. A
 * successful Take followed by a retry POST hits the race-lost branch
 * (listing is now Taken) and gets the friendly redirect — double-clicks
 * are safe.
 */
```

### Transactions and locking

`DB::transaction` appears in 28 of the 38 Action files. Actions that touch money
or contended rows take explicit locks, **in a documented stable order** so two
concurrent takes cannot deadlock:

```php
Listing::query()->lockForUpdate()->find(...);
User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
```

Side effects that must not fire on a rolled-back transaction are deferred:
notifications are sent after commit, and broadcast events implement
`ShouldDispatchAfterCommit`.

---

## 3. Services — capabilities, not use-cases

`app/Services/`. **Services are nouns and carry no `Service` suffix** — there are
zero `*Service.php` files in the codebase. They are predominantly *static
facades*: no container binding, no interface, called as `Wallet::hold(...)`.

| Service | Kind | Purpose |
|---|---|---|
| `Wallet` | static | Sole writer of `users.usdt_balance` + `wallet_transactions` |
| `Withdrawals` | `final`, static | Cash-out lifecycle; sole writer of `withdrawals` |
| `PayoutClearance` | `final`, static | When a payout becomes withdrawable (insurance window) |
| `KycGate` | `final`, static | Whether a withdrawal needs identity verification |
| `MatchParticipants` | static | Resolves the user roster for a match (creator / taker / lobby) |
| `MatchParticipation` | static | "Which settled matches did these users play, and win" |
| `ParticipantStats` | static | Per-user career totals + win rate, batched |
| `RecentForm` | static | Per-user recent W/L/D form, batched |
| `SellerTrust` | static | Per-creator trust aggregates for the marketplace, batched |

The last four are **batch primitives**: they take a collection of users and
return keyed aggregates, existing specifically so listing and profile pages can
render trust data without an N+1.

### The exception: driver interfaces are injected

Where a capability has swappable implementations, it becomes an interface bound
in `app/Providers/AppServiceProvider.php` and injected normally:

| Contract | Implementations | Selected by |
|---|---|---|
| `Services\GameApi\GameApi` | `ChessGameApi` → `FaceitGameApi` → `MockGameApi` | `config('stakly.game_api_driver')` |
| `Services\Payments\PaymentGateway` | `MockGateway`, `NowPaymentsGateway`, `CryptomusGateway` | `config('services.payments.driver')` |
| `Services\Provider\ProfileClient` | per-provider profile clients | call site |

`GameApi` is bound as a **fallback chain** — each adapter reads the card shapes
it understands and delegates the rest downstream, ending at `MockGameApi` in
tests.

### DTOs

There is no `app/DTOs/` folder. The 13 DTOs live next to their consumers, all
`final readonly class`:

- `app/Services/Payments/Dto/` — `DepositAccount`, `FeeEstimate`, `PayoutResult`,
  `GatewayWebhookEvent`, plus `GatewayEventType` / `GatewayPayoutStatus` enums
  that normalize provider vocabulary.
- `app/Services/Provider/` — `ChessComGameResult`, `LichessGameResult`,
  `FaceitMatchResult`, `FaceitProfile`, `ChessRatings`, `ProfileFetchResult`, …
- `app/Services/GameApi/GameApiResult.php` — the arbitration outcome.

They exist to stop provider JSON leaking past the client that fetched it.

---

## 4. The money spine

Full detail lives in [`billing.md`](billing.md). The architectural facts:

- **`App\Services\Wallet` is the only writer** of `users.usdt_balance` and
  `wallet_transactions`. Not controllers, not seeders, not migrations, not
  factories, not tinker.
- Two invariants, asserted in `tests/Feature/WalletTest.php`:
  1. `users.usdt_balance == SUM(wallet_transactions.amount)` for every user.
  2. `wallet_transactions` is append-only — no `UPDATE` ever touches it.
- Every write funnels through one private `Wallet::record()` which, in order:
  asserts the amount is positive → opens a transaction → returns early if
  `reference_id` already exists (**idempotency**) → row-locks the user → checks
  the freeze flag on the *locked* row → computes `balance_after` with `bcadd` →
  rejects a negative result → appends the row.
- **Money is BCMath strings at scale 6**, never floats. `bcadd` / `bcsub` /
  `bccomp` only; PHP `+`/`-`/`<` on money is forbidden. Floats appear at exactly
  one boundary: JSON props for the frontend.
- Amounts are always passed **positive**; direction is derived from
  `WalletTransactionType` in `Wallet::isDebit()`. The enum does not carry sign.
- `reference_id` follows a `kind:id` scheme (`match-take:412`,
  `match-payout:412`), parsed back by `app/Support/WalletReferenceParser.php`.

---

## 5. The outcome pipeline

**Match results come from provider APIs. There is no "I won" button.**

```
Listing created ──▶ stake escrowed (Wallet::hold)
       │
       ▼  opponent takes
Match Pending ─────▶ players play on chess.com / Lichess / FACEIT
       │
       │   four independent trigger surfaces, all dispatching the
       │   same ShouldBeUnique-per-match job:
       │     1. match page visit
       │     2. chat send
       │     3. 5-min cron  (+ 1-min sweep for young matches)
       │     4. Lichess game-end stream sidecar
       ▼
AutoFetch{Lichess,ChessCom,Faceit}GameJob
       │  polls provider, matches by snapshotted username + timestamp
       ▼
System message "game card" posted to match chat
       │
       ▼  settlement fires from the card
SettleFromCardAction ──▶ Wallet::payout + Wallet::fee ──▶ Settled
       │
       └── no result / disputed ──▶ Disputed ──▶ ManualReview ──▶ admin resolve
```

Two design properties worth internalizing:

- **Snapshot, don't link.** Verified provider usernames are denormalized onto
  `match_provider_snapshots` when the match is created. A player who unlinks
  mid-match cannot break dispute arbitration.
- **Redundant triggers, idempotent job.** The four surfaces exist because any one
  of them can miss (player closes the tab, stream sidecar restarts). The job is
  `ShouldBeUnique` keyed on `match.id`, so redundancy costs nothing.

Adapters live in `app/Services/GameApi/`; the raw HTTP clients live in
`app/Services/Provider/`. Every auto-fetch attempt writes an audit row
(`match_auto_fetch_attempts`) — the pipeline is debuggable after the fact.

---

## 6. Jobs, events, scheduling

### Jobs — `app/Jobs/`

| Convention | Applies to |
|---|---|
| `ShouldQueueAfterCommit` | all — a job must never observe an uncommitted row |
| `ShouldBeUnique` + `uniqueId()` | `AutoFetch*GameJob`, `Refresh*RatingJob` |
| `backoff(): array`, `retryUntil()` | all provider-touching jobs |
| `middleware(): [new RateLimited('<limiter>')]` | all provider-touching jobs |

**`$tries` is deliberately widened on rate-limited jobs.** `RateLimited`
middleware *releases* a throttled job back to the queue, and each release
consumes an attempt. A job with `$tries = 3` behind a busy limiter will exhaust
itself without ever reaching the provider. `AutoFetchFaceitGameJob` documents
this inline — widened from 7 to 15.

### Events — `app/Events/`

`MessageSent` and `LobbyUpdated`, both `ShouldBroadcast, ShouldDispatchAfterCommit`,
both with an explicit `broadcastAs()` string so the JS listener name is stable
and independent of the PHP class name.

### Scheduling — `routes/console.php`

No console kernel. All schedules live in `routes/console.php` and are executed by
the `scheduler` compose service (`php artisan schedule:work`).

| Command | Cadence | Purpose |
|---|---|---|
| `listings:expire` | every minute | Refund + close listings past expiry |
| `matches:resolve-timeouts` | every 10 min | Matches stuck in Pending past the 4h window |
| `stakly:auto-fetch-pending` | every 5 min | Auto-fetch backstop |
| `stakly:auto-fetch-pending --min-age-minutes=1 --max-age-minutes=10` | every minute | Young-match tier — closes the chess.com settle seam |
| `lobbies:sweep-ready-check-timeouts` | every minute | 5-min ready deadline |
| `lobbies:sweep-fill-timeouts` | hourly | 24h lobby fill timer |
| `horizon:snapshot` | every 5 min | Horizon metrics |

Everything except `horizon:snapshot` uses `->withoutOverlapping()`.

---

## 7. Third-party API defence

Every outbound provider call sits behind three independent layers. New
integrations ship with all three — this is not retrofit work.

```
  Job
   │
   ├─[1]─ RateLimited middleware ──▶ named RateLimiter  (proactive: stay under quota)
   │
   ├─[2]─ ProviderCircuitBreaker  ──▶ open?  skip the call entirely
   │
   └─[3]─ RateLimitHeaderParser   ──▶ 429 Retry-After → RateLimitedError($retryAt)
```

**1. Named limiters** — `AppServiceProvider::registerProviderRateLimiters()`.
Six limiters, caps from `config/services.*`:

```php
RateLimiter::for('chess-com-api', fn () => Limit::perMinute(
    (int) config('services.chess_com.requests_per_minute', 30),
));
```

Rating refreshes get **their own budget per provider** (`faceit-rating-api`,
`chess-com-rating-api`, `lichess-rating-api`). A ratings backfill must never be
able to starve the settlement-critical fetch path.

**2. Circuit breaker** — `app/Services/Provider/ProviderCircuitBreaker.php`, a
cache-backed sliding window (600s window, 5 min attempts, 0.5 error-rate
threshold, 300s cooldown). Injected into job `handle()` signatures and surfaced
in the admin panel by `app/Filament/Widgets/ProviderCircuitBanner.php`.

**3. Retry hints** — `RateLimitHeaderParser::parseRetryAt()` reads `Retry-After`
/ `X-RateLimit-Reset` and feeds `RateLimitedError::$retryAt`, which jobs catch,
audit, and re-throw into Laravel's retry machinery.

**Why all three.** A production 429 that trips the breaker freezes settlement for
every Pending match in that provider's pipeline until it recloses. A three-minute
latency burst beats a total settlement freeze.

Provider errors are a typed hierarchy — `TransientProviderError`,
`PermanentProviderError`, `RateLimitedError`, `ProfileNotFoundException` — so
retry policy is a property of the error, not a guess at the call site.

### Outbound egress safety

User-supplied URLs (chat link previews, avatars) go through
`app/Support/SsrfGuard.php` and `app/Support/SafeHttpClient.php` (PSR-18, max 3
redirects) so a pasted link cannot reach internal network space.

---

## 8. Real-time

Reverb (WebSockets) + Echo. Channel authorization is declared in
`routes/channels.php` and implemented in **channel classes** so it stays
directly testable:

```php
Broadcast::channel('App.Models.User.{id}', fn ($user, $id) => (int) $user->id === (int) $id);
Broadcast::channel('match.{matchId}', MatchChannel::class);
Broadcast::channel('lobby.{listing}', LobbyChannel::class);   // implicit model binding
```

**The house rule: a broadcast is a trigger, not a payload.** Clients react to an
event by asking the server for fresh props, rather than trusting what arrived
over the socket:

```tsx
useEcho<LobbyUpdatedPayload>(`lobby.${listingId}`, '.lobby.updated', () => {
    router.reload({ only: ['lobby'] });
});
```

This keeps authorization and shaping in one place (the controller + Resource) and
means a socket message can never surface data the viewer isn't entitled to. The
one exception is match chat, where the message body itself is the payload.

---

## 9. Request lifecycle and locale routing

Every web route is wrapped in a locale prefix:

```php
Route::prefix('{locale}')
    ->whereIn('locale', config('stakly.locales'))   // en, ka, ru
    ->middleware(SetLocale::class)
    ->group(...);
```

The moving parts:

1. **`RedirectUnprefixedLocale` is prepended globally**, not added to the `web`
   group. An unprefixed path like `/listings` matches no route, so the framework
   would 404 *before* group middleware ever ran. Global middleware fires
   regardless of route match, so the 301 lands first.
2. **`SetLocale`** validates the segment, sets the app locale, sets
   `URL::defaults(['locale' => …])`, queues the plaintext `stakly_locale` cookie,
   then calls `$request->route()?->forgetParameter('locale')` so the parameter
   doesn't shift controller argument binding.
3. **`URL::defaults`** is also set in `AppServiceProvider` so `route()` calls from
   queued jobs, console commands and Filament resolve correctly with no request
   in scope.
4. On the client, `resources/js/app.tsx` calls
   `setUrlDefaults(() => ({ locale: currentLocale }))`, which is why **no
   Wayfinder call site ever passes `locale`**.

Two routes deliberately sit **outside** the locale group, because a third party
calls them and has no locale to offer: the FACEIT OAuth callback and
`webhooks/faceit` (which is also CSRF-exempt, authenticated instead by a
shared-secret header in `VerifyFaceitWebhook`).

### Middleware registered in `bootstrap/app.php`

`HandleInertiaRequests`, `AddLinkHeadersForPreloadedAssets`,
`ThrottleVerificationSend`, `HandleImpersonationExpiry` are appended to `web`.
Four cookies are exempted from encryption because client JS reads them
(`sidebar_state`, `player_sidebar_collapsed`, `listings_view_layout`,
`stakly_locale`) — all four are re-validated server-side against a whitelist.

---

## 10. Error handling

Configured once, in `bootstrap/app.php`:

```php
$exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
    if (app()->environment('local') && config('app.debug')) {
        return $response;                       // keep Whoops in dev
    }

    if (! $request->header('X-Inertia') && ! $request->wantsJson()) {
        return $response;
    }

    $status = $response->getStatusCode();

    if (in_array($status, [403, 404, 500, 503], true)) {
        return Inertia::render('errors/error', ['status' => $status])
            ->toResponse($request)
            ->setStatusCode($status);
    }

    return $response;
});
```

**419 and 422 are deliberately untouched.** Inertia already renders validation
errors inline, and a full-page takeover is the wrong UX for an in-flight form
whose CSRF token expired.

Domain exceptions are few and deliberate — `app/Exceptions/` holds exactly
`AccountFrozenException`, `InsufficientBalanceException`, `KycRequiredException`.
Everything else that a user can legitimately hit is a sentinel return.

---

## 11. Admin panel (Filament 5)

Single panel, `app/Providers/Filament/AdminPanelProvider.php`: path `/admin`,
custom Vite theme, Fortify-backed MFA required via `RequireAdminTwoFactor`,
database notifications polling every 30s.

Filament 5 **file-per-concern** layout — the resource class is a thin router:

```
app/Filament/Resources/GameMatches/
├── GameMatchResource.php          ← nav label "Disputes", slug `disputes`
├── Pages/{ListGameMatches,ViewGameMatch}.php
├── Schemas/GameMatchInfolist.php
└── Tables/GameMatchesTable.php
```

```php
public static function infolist(Schema $schema): Schema { return GameMatchInfolist::configure($schema); }
public static function table(Table $table): Table       { return GameMatchesTable::configure($table); }
```

**Money-facing resources are read-only** (`canCreate() => false`). Mutations
happen through header actions that call the same domain Actions and Services the
web app uses — an admin settling a dispute goes through
`AdminSettleToWinnerAction` → `Wallet`, never through a form write. This is what
keeps the ledger invariant true regardless of who moved the money.

Widgets: `OpsOverview`, `PipelineHealth` (both polling stats overviews) and
`ProviderCircuitBanner`, which hides itself via `canView()` unless a circuit is
actually open.

---

## 12. Folder structure

```
app/
├── Actions/<Domain>/          # orchestration, one verb per class
│   └── <Domain>/Admin/        # admin-only variants
├── Broadcasting/              # channel authorization classes
├── Concerns/                  # shared validation traits
├── Console/Commands/          # thin wrappers that inject an Action
├── Enums/                     # 14 backed string enums
├── Events/                    # broadcast events
├── Exceptions/                # 3 domain exceptions
├── Filament/                  # admin panel (file-per-concern)
├── Http/
│   ├── Controllers/           # thin; Settings/ and Webhooks/ subfolders
│   ├── Middleware/
│   ├── Requests/<Domain>/     # FormRequest validation
│   ├── Resources/             # Inertia prop shaping — whitelists, never PII
│   └── Responses/             # Fortify response contract overrides
├── Jobs/                      # queued provider work
├── Listeners/                 # wired via Event::listen in AppServiceProvider
├── Models/
├── Notifications/
├── Observers/                 # attached via #[ObservedBy] attribute
├── Policies/                  # GameMatchPolicy, ListingPolicy — auto-discovered
├── Providers/
├── Services/                  # capabilities; GameApi/, Payments/, Provider/
└── Support/                   # SsrfGuard, SafeHttpClient, WalletReferenceParser, …
```

```
resources/js/
├── app.tsx / ssr.tsx          # entries; ssr mirrors app MINUS configureEcho
├── components/<domain>/       # by domain, not by type; ui/ = shadcn primitives
├── config/                    # static catalogs (games, platforms, currencies)
├── hooks/                     # use-*.ts
├── layouts/
├── lib/                       # formatters, query builders, i18n
├── pages/                     # Inertia pages, kebab-case matching the URL
├── types/                     # page prop contracts, barrel-exported
├── actions/ routes/ wayfinder/  # Wayfinder-generated — never hand-edit
```

### Naming

| Kind | Pattern | Example |
|---|---|---|
| Action | `<Verb><Noun>Action` | `TakeListingAction` |
| Service | plain noun, **no suffix** | `Wallet`, `SellerTrust` |
| Job | `<Verb><Noun>Job` | `AutoFetchLichessGameJob` |
| Form Request | `<Verb><Noun>Request` | `StoreListingRequest` |
| Resource | `<Noun>Resource` | `ListingResource` |
| Enum | `<Noun><Type>` | `MatchStatus`, `WalletTransactionType` |
| React file | **kebab-case**, always | `listing-grid-card.tsx` |
| React export | PascalCase component / camelCase hook | `ListingGridCard`, `useFlashToast` |
| Inertia page | kebab-case path matching the URL | `pages/wallet/withdraw.tsx` |

`tsconfig.json` sets `forceConsistentCasingInFileNames: true`, so casing drift
fails the build rather than only breaking on case-sensitive filesystems.

---

## 13. What is deliberately absent

Documented so nobody re-adds them thinking they were an oversight:

| Absent | Why |
|---|---|
| API layer / `routes/api.php` / versioning | Inertia is the only consumer. The handful of JSON endpoints (`NotificationController`) are served from web routes |
| `declare(strict_types=1)` | Not used anywhere; the Pint `laravel` preset does not add it. Don't introduce it in one file |
| `Model::preventLazyLoading()` | Not enabled. N+1 is prevented by explicit `->with([...])` and batch Services |
| `Inertia::defer()` / `Inertia::optional()` | Zero usages. Pages ship their props in one payload |
| PHPStan / Larastan / Deptrac / Rector | Not installed. Pint + ESLint + `tsc` are the gates, enforced in CI |
| Git hooks | None. `.git/hooks` holds only samples — CI is the gate |
| Smart contracts / on-chain escrow / wallet-connect | Custodial by design; switching is a real architectural change, not a feature |
