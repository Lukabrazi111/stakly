# Billing

How money enters, moves within, and leaves Stakly. Covers M9 Phase 0a (payment
gateway abstraction) and Phase 0b (ledger machinery + payout clearing), both
shipped and provider-agnostic.

**Status at a glance**

| Area | State |
|---|---|
| Internal ledger (`Wallet`) | ✅ shipped (M3.5) |
| `PaymentGateway` abstraction + `MockGateway` | ✅ shipped (M9 P0a) |
| Account freeze | ✅ shipped (M9 P0b) |
| Payout clearing / insurance window | ✅ shipped (M9 P0b) |
| Withdrawals (request → send → complete) | ✅ shipped (M9 P0b), against `MockGateway` |
| Optional KYC gate | ✅ shipped (M9 P0c) — **off by default** |
| 2FA step-up on withdrawal | ✅ shipped (M9 P0d) — **on by default** |
| New-address cooldown | ✅ shipped (M9 P0e) — 24h default |
| Real deposit crediting (webhook) | 🚫 Phase 1 — see [`billing-roadmap.md`](billing-roadmap.md) |
| Real payouts, fee estimates, batching | 🚫 Phase 2 — see [`billing-roadmap.md`](billing-roadmap.md) |
| Reconciliation, velocity caps | 🚫 Phase 3 — see [`billing-roadmap.md`](billing-roadmap.md) |

---

## Architecture

Stakly is **custodial-by-database**, not a dApp. There are no smart contracts,
no on-chain game logic, and no wallet-connect flows. The blockchain is a
deposit/payout rail; **the Postgres ledger is the source of truth**.

```
                  ┌──────────────────────────┐
   deposit  ──────▶                          │
                  │   wallet_transactions    │  ← source of truth
   withdrawal ◀───│   users.usdt_balance     │
                  └────────────┬─────────────┘
                               │
                     App\Services\Wallet      ← the ONLY writer
                               │
        ┌──────────────────────┼──────────────────────┐
   Withdrawals            Settlement              Listings
   (cash-out)          (payout + fee)          (escrow hold)
        │
        ▼
   PaymentGateway  ← swappable driver: mock | nowpayments | cryptomus
```

Two invariants hold at all times and are asserted in `tests/Feature/WalletTest.php`:

1. `users.usdt_balance == SUM(wallet_transactions.amount)` for every user.
2. `wallet_transactions` is **append-only** — no `UPDATE` ever touches it.

Everything below is designed around not breaking those two.

---

## The ledger — `App\Services\Wallet`

Static service, the sole writer of `users.usdt_balance` and
`wallet_transactions`. Never write a balance from a controller, seeder,
migration, factory, or tinker.

| Method | Type written | Direction |
|---|---|---|
| `deposit` | `Deposit` | credit |
| `withdraw` | `Withdrawal` | debit |
| `hold` | `EscrowHold` | debit |
| `release` | `EscrowRelease` | credit |
| `payout` | `Payout` | credit |
| `fee` | `Fee` | credit (to the platform user) |
| `reverseWithdrawal` | `WithdrawalReversal` | credit |

Read helpers: `balanceFor`, `availableBalance`, `unclearedBalance`,
`nextClearanceAt`.

**Money math is BCMath strings at scale 6.** Callers pass a *positive* string
(`'100'`, `'100.000000'`); the service applies the sign from the type. PHP
`+`/`-`/`<` on money is forbidden. Floats appear only at the API resource
boundary (`(float) $this->amount`).

**Idempotency.** Every method takes an optional `reference_id`. If a row with
that reference already exists, the existing row is returned silently — no
second balance change. This is what makes retried jobs and replayed webhooks
safe. The column is `UNIQUE`.

**Ordering inside `record()`** matters and is deliberate:

1. Assert the amount is positive.
2. Open a transaction, check the reference for a replay → return early if found.
3. Lock the user row (`lockForUpdate`).
4. **Freeze check** (debits only) — on the *locked* row, so a concurrent freeze
   can't be raced.
5. Balance-floor check (debits only).
6. Append the ledger row + update the balance.

The replay check sits *before* the freeze check on purpose: retrying a job for
work that already happened must stay a no-op even after the account is frozen.

### Reference-ID conventions

| Prefix | Written by | Meaning |
|---|---|---|
| `listing-create:{id}` | `CreateListing` | creator's stake escrowed |
| `listing-cancel:{id}` / `listing-expire:{id}` | listing actions | stake refunded |
| `match-take:{id}` | `TakeListingAction` | taker's stake escrowed |
| `match-payout:{id}` (+ `:player-{id}` for teams) | settlement | winnings |
| `match-fee:{id}` | settlement | platform rake |
| `cancel-refund:{match}:{user}` | cancellation | refund fan-out |
| `wd:{id}` | `Withdrawals::request` | the withdrawal debit |
| `wd-reversal:{id}` | reject / fail | credit-back |
| `wd-margin:{id}` | `Withdrawals::markCompleted` | Stakly's withdrawal cut |

`App\Support\WalletReferenceParser` maps these prefixes to admin/public deep
links. **Add a prefix there whenever you add one here**, or the admin ledger
renders the row without a link.

---

## Payout clearing (the insurance window)

Match winnings are credited immediately but aren't **withdrawable** until they
clear.

**What it defends against:** chess.com / Lichess / FACEIT retroactively closing
an account for fair-play violations days-to-weeks after the games. That's the
chargeback-equivalent for this product. The *acute* case (wrong result now) is
already handled by the auto-detection pipeline; this covers the *retrospective*
case, where the money would otherwise be long gone.

**Only payouts clear.** Deposits are final on-chain; escrow releases are the
player's own stake coming back. Neither can be reversed by a provider.

### How it works

`wallet_transactions.clears_at` is stamped once at settlement and never
updated. Availability is *computed* against it:

```
availableBalance = usdt_balance − SUM(payout amounts where clears_at > now)
```

Consequences worth understanding:

- **No money moves.** No second balance column, no new ledger types, no
  reversal entries. The `balance == SUM(ledger)` invariant is untouched.
- **No scheduled job.** Funds clear because time passed. Nothing to monitor,
  nothing to fail, nothing to backfill after downtime.
- **Append-only survives.** The row is written once. This is also *why* there's
  no per-payout admin hold — extending one row's `clears_at` would need an
  `UPDATE`. Account-level freeze covers that case instead.

### Uncleared winnings are stakeable

They can be escrowed into new matches; they just can't leave the platform.
This keeps the product playable — winning $100 shouldn't bench you for two
days.

It's safe because **every re-stake starts a fresh clearance clock** on the
resulting winnings. Laundering a cheated pot through an accomplice moves *which
account is waiting*, never shortens the wait.

### Risk tiers

`App\Services\PayoutClearance::for($winner, $amount)` returns the timestamp.
Base window unless any one signal fires, then the elevated window:

| Signal | Default |
|---|---|
| Account younger than N days | 7 days |
| Payout at or above N | 500 USDT |
| Player has never completed a withdrawal | on |

| Config key | Default |
|---|---|
| `stakly.withdrawal_insurance_enabled` | `true` |
| `stakly.withdrawal_insurance_base_hours` | 48 |
| `stakly.withdrawal_insurance_elevated_hours` | 168 (7 days) |
| `stakly.withdrawal_insurance_risk.*` | see above |

### The kill-switch

`STAKLY_WITHDRAWAL_INSURANCE_ENABLED=false` makes withdrawals instant.

It is **retroactive**: `availableBalance()` short-circuits to the full balance
rather than merely skipping new stamps. Flipping it off therefore also releases
holds already written to existing rows, instead of stranding funds behind a
window nothing is enforcing any more.

---

## Account freeze

`users.frozen_at` + `frozen_reason`. Distinct from `banned_at`:

|  | `banned_at` | `frozen_at` |
|---|---|---|
| Blocks | product access (listings, profile, username) | money **out** |
| Debits (`withdraw`, `hold`) | allowed | **blocked** |
| Credits (`deposit`, `release`, `payout`, `fee`) | allowed | allowed |

Credits still flow while frozen **on purpose**: a frozen player's opponent must
still be able to get paid or refunded, so freezing one account can never strand
another's escrowed money.

Toggled from the admin user page (Freeze funds / Unfreeze funds), which writes
a `user_moderation_logs` row with action `freeze` / `unfreeze`.

Player-facing, a frozen account gets a clean 422 on the withdraw form
(`WithdrawRequest::after()`), not an exception — the service throw
(`AccountFrozenException`) is the backstop, not the user-facing path.

---

## New-address cooldown

`App\Services\WithdrawalAddressCooldown` — the first withdrawal to a
never-used destination waits `stakly.withdrawal_address_cooldown_hours`
(default 24) before the payout is handed to the provider. Set `0` to disable.

Closes the gap the 2FA step-up leaves open: a code proves someone holding the
device is present, but a phished or coerced code still sends funds wherever the
request says. The delay plus `WithdrawalHeldNotification` (database + broadcast
+ **mail**, unconditionally — an attacker in the session would simply not read
the in-app bell) turns an instant irreversible drain into a window where the
real owner can react.

**Held, not blocked.** The withdrawal is accepted and the balance debited
immediately, so it can't be spent twice; only the send waits.
`ProcessWithdrawal` is dispatched with a matching delay and the row carries
`hold_until`. Blocking outright would just fail every legitimate first
withdrawal. Admin Reject during the window credits the full gross back through
the existing idempotent path, and the delayed job then finds a terminal status
and no-ops — no new cancellation path was needed.

### What counts as a known address

A prior withdrawal to that address, for that user, that is **not reversed** and
**not still inside its own hold**:

| Prior row | Trusts the address? |
|---|---|
| `Completed`, or `Pending`/`Sending` past its hold | yes |
| `Pending` still inside its hold | **no** |
| `Rejected` / `Failed` | no — the money came back |

That middle row is the whole point. Without it, a 1 USDT decoy to the attacker's
address would instantly mark it "known" while still sitting in its own cooldown,
and the next request could drain the balance with no wait. A still-held row
proves nothing, because nobody has had the chance to object to it yet. There is
a test for exactly this.

Resolved under the same user row lock as the balance check, so two concurrent
requests can't both see the address as unknown and both go straight out.

Enforcement is the job delay, not a guard inside `send()` — `send()` is called
directly by seeders and would need a bypass anyway, and reaching it otherwise
already implies code execution.

---

## 2FA step-up on withdrawal

`App\Services\WithdrawalTwoFactor` — **on by default**
(`STAKLY_WITHDRAWAL_REQUIRE_2FA=true`). Closes the highest-severity money path
in the product: account takeover into a drain to an attacker's address.

**Step-up, not a prerequisite.** A fresh TOTP code is required on *every*
withdrawal, not merely "2FA is enabled on the account". Requiring only enrolment
would leave a hijacked live session able to drain freely — that session already
cleared 2FA at login. The code is what proves someone holding the device is
present at withdrawal time.

Replay is handled by Fortify: `TwoFactorAuthenticationProvider::verify()` caches
every accepted code for the length of its window, so an intercepted code is not
worth a second withdrawal.

### Where it is enforced, and why that differs

At the **HTTP boundary** (`WithdrawRequest`), not inside `Withdrawals::request()`.
Freeze and KYC are database state and must be read under the user row lock; a
TOTP code is a *credential*, and only exists in a request context. Seeders and
admin-initiated withdrawals legitimately have none to present, so pushing the
check into the service would break them for no security gain — anyone able to
call the service directly already has code execution.

The 2FA check also runs **last**, after the amount and address rules. A code is
single-use, so burning one on a request that was going to fail validation anyway
would be a small but real annoyance.

**Recovery codes are deliberately not accepted here.** A lost device already
blocks login, so the recovery path is recover-login → re-enrol in settings →
withdraw. Honouring them at this step would extend the surface to the credential
most likely to be screenshotted, to solve a lockout the login flow already owns.

### Dev

The seeded dev user carries a fixed, in-source TOTP secret
(`DatabaseSeeder::DEV_TOTP_SECRET`) so local cash-out stays testable without
re-enrolling an authenticator after every `migrate:fresh --seed`:

```bash
sail artisan stakly:dev-totp          # prints the current code
```

Or set `STAKLY_WITHDRAWAL_REQUIRE_2FA=false` locally.

---

## Optional KYC gate

`App\Services\KycGate` — **off by default** (`STAKLY_KYC_ENABLED=false`), and
nothing in the current provider model requires it. NOWPayments asks crypto-only
merchants for KYB/KYC only when a transaction is flagged suspicious, and imposes
nothing on our end users while deposits use permanent per-user addresses.

It exists so verification is a **switch, not a rewrite**: the guard sits in
`Withdrawals::request()` beside the freeze check, under the same row lock.

**Tiered by volume, not a wall.** Below `stakly.kyc_threshold` (default 1000 USDT
lifetime) nothing is asked. That matters because verification is admin-driven —
recorded via a Filament action on the user page, writing `user_moderation_logs` —
with no document-upload flow. A blanket gate would brick cash-out for everyone
the moment it was switched on. Set the threshold to `'0'` to gate every
withdrawal.

| Signal | Behaviour |
|---|---|
| `KycStatus::Verified` | Never gated, any amount |
| `Unverified` / `Rejected` | Gated above the threshold |
| `Pending` | **Also gated** — unread documents must not unblock a cash-out |

The tally counts **Pending + Sending + Completed** withdrawals including the one
being requested. Counting only `Completed` would let a player split one large
cash-out into several concurrent requests, each individually under the line;
the row lock plus the in-flight tally closes that.

Gates withdrawals only. Deposits, staking, and settlement are untouched — an
unverified player can still play and be paid, they just can't take an
above-threshold amount off the platform. Player-facing, it's a clean 422 on the
withdraw form (`WithdrawRequest::after()`), with `KycRequiredException` as the
service backstop — same split as account freeze.

---

## Withdrawals

### Lifecycle

```
                    ┌──────────────► Completed   (margin booked here)
                    │
request ──► Pending ┼──► Sending ───┤
   │                │               └──► Failed      (debit reversed)
   │                │
   │                └──► Rejected                    (debit reversed)
   ▼
 balance debited immediately
```

`WithdrawalStatus` has **no `Approved` state**. The anti-abuse hold lives on the
payout's clearing window, so anything withdrawable has already cleared and
there's nothing left to gate. There is no admin approval on the happy path.
`Approved` returns if Phase 2.3 mass-payout batching lands, which needs a
queued-but-unsent state.

### Money flow

For a 100 USDT withdrawal with a 0.50 margin and 1.50 gas:

| Party | Change |
|---|---|
| Player balance | −100.00 (at request) |
| Platform user | +0.50 (at **completion**) |
| Leaves custody | 99.50, of which ~1.50 is burned as gas |
| Player receives on-chain | ~98.00 |

**The margin is booked at completion, never at request** — a rejected
withdrawal must not record phantom revenue. Reject and fail both credit back
the full gross, so there is nothing to unwind on the platform side.

### Safety properties

- **Available-balance check under a row lock.** `Withdrawals::request` locks the
  user before computing availability, so two concurrent requests can't both see
  the same headroom. `WithdrawRequest` validation is the friendly 422; the
  locked re-check is the real guard.
- **Provider idempotency.** `createPayout` receives `wd:{id}` as its
  idempotency key, so a retried job can't double-send.
- **All four gateway statuses are mapped** — `Queued`, `Sending`, `Completed`,
  `Failed` — even though `MockGateway` only ever returns `Completed`. The async
  paths are what a real provider uses; leaving them unhandled would make the
  provider swap a silent breakage.
- **Exhausted retries reverse the debit — but only if the provider never saw
  the payout.** `ProcessWithdrawal::failed` reverses only while
  `provider_payout_id` is still null. Once it's set the funds may already be
  moving on-chain (the job can fail *after* a successful `createPayout`, e.g. a
  DB blip while booking the margin), and crediting back would pay the player
  twice. Those rows are logged `critical` and left in the admin queue for a
  human, where Reject is still available once the real state is known.
- **`ShouldQueueAfterCommit`** on the job: the withdrawal row and its ledger
  debit are written in one transaction, so dispatching earlier could hand a
  worker an id that doesn't exist — or pay out against a debit that rolls back.

---

## The payment gateway

`App\Services\Payments\PaymentGateway` — the whole app depends on this contract
and the DTOs in `Dto/`, never on a provider's payload shape or SDK.

```php
public function ensureDepositAccount(User $user): DepositAccount;
public function createPayout(string $amount, string $address, string $reference): PayoutResult;
public function estimatePayoutFee(string $amount, string $address): FeeEstimate;
public function verifyWebhookSignature(Request $request): bool;
public function parseWebhookEvent(Request $request): GatewayWebhookEvent;
```

Bound as a singleton in `AppServiceProvider::bindPaymentGateway()` from
`config('services.payments.driver')`. Switching providers is a config flip plus
one class.

| Driver | State |
|---|---|
| `mock` (default) | Deterministic, no network. Keyed off the caller's reference. |
| `nowpayments` | Stub — every method throws via `Concerns\Unwired`. |
| `cryptomus` | Stub — same. |

`Unwired` is a trait, and class methods take precedence over trait methods, so
a real gateway can be implemented **one method at a time** by dropping
`use Unwired;` last.

### Current consumers

| Method | Called from |
|---|---|
| `ensureDepositAccount` | `CreateNewUser` (registration), `WalletController::deposit` |
| `createPayout` | `Withdrawals::send` |
| `estimatePayoutFee` | `WalletController::withdraw` (fee preview) |
| `verifyWebhookSignature` | — none yet (Phase 1) |
| `parseWebhookEvent` | — none yet (Phase 1) |

### Config

```
PAYMENTS_DRIVER=mock
PAYMENTS_MOCK_NETWORK_FEE=1.500000
PAYMENTS_MOCK_WEBHOOK_SECRET=          # openssl rand -hex 32
```

Per-provider blocks (`base_url`, keys, `requests_per_minute`,
`circuit_breaker`) are declared in `config/services.php` ahead of their
consumers. The `payments-api` throttle and `ProviderCircuitBreaker` reuse land
with the real clients, not before — they'd have no caller today.

---

## Not built yet

Full detail, ordered steps, open decisions, and the attacker view live in
**[`billing-roadmap.md`](billing-roadmap.md)**. The short version:

⚠️ **Deposits cannot be credited today — dev or otherwise.** There is no webhook
receiver, so balances come from seeders. `verifyWebhookSignature` /
`parseWebhookEvent` are implemented and tested on `MockGateway` but have no
caller. Money can leave Stakly but cannot enter it.

- **Phase 1 — deposit edge.** Provider client, `users.payments_account_id`, real
  per-user deposit addresses replacing `MockTronAddress`, and
  `POST /webhooks/payments` crediting **net** amounts idempotently by tx hash.
- **Phase 2 — withdrawal edge.** Only `Withdrawals::send()`'s body changes: real
  payout call, real fee estimate, mass-payout batching, and a payout webhook to
  resolve `Sending` rows — nothing completes them today.
- **Phase 3 — hardening.** 2FA-on-withdrawal, address cooldown, reconciliation,
  velocity caps, go-live checklist. KYC is tracked separately; it needs a
  non-engineering answer and nothing else depends on it.

**What changes when the provider is wired:** the gateway implementation class,
plus a webhook receiver. Nothing in the ledger, the clearing model, the
withdrawal lifecycle, the admin surfaces, or the UI.

---

## Dev recipes

```bash
# Swap driver (stubs throw — proves the binding, not a working provider)
PAYMENTS_DRIVER=cryptomus sail artisan tinker --execute 'app(App\Services\Payments\PaymentGateway::class)::class;'

# Instant withdrawals — also releases existing holds
STAKLY_WITHDRAWAL_INSURANCE_ENABLED=false

# Rebuild fixtures: clearing + cleared winnings, one withdrawal per status,
# and a frozen user
sail artisan migrate:fresh --seed

# Prove the invariant against seeded data
sail artisan tinker --execute '
App\Models\User::all()->each(function ($u) {
    $sum = (string) $u->walletTransactions()->sum("amount");
    if (bccomp((string) $u->usdt_balance, $sum, 6) !== 0) { echo "DRIFT: {$u->id}\n"; }
});'
```

**Money tests** (no financial path ships without them):

```bash
sail artisan test --compact --filter='Wallet|Withdrawal|Freeze|Clearance|Payment'
```

Key suites: `WalletTest` (service contract + both invariants),
`WithdrawalTest` (lifecycle, idempotency, conservation),
`PayoutClearanceTest` (tiers, kill-switch, stakeable-not-withdrawable),
`AccountFreezeTest` (debit/credit asymmetry),
`PaymentGatewayTest` (driver swap + mock behaviour),
`Admin/WithdrawalAdminTest` (reject + freeze actions).
