# Billing roadmap

What's **left** to make payments fully working, and in what order.

Companion to [`billing.md`](billing.md), which describes the system as it exists
today. That doc is the reference; this one is the plan. When a phase here ships,
fold it into `billing.md` and delete it from here — the two should never describe
the same thing twice.

**Provider: NOWPayments.** Settled. `CryptomusGateway` stays as a stub because it
costs nothing to keep and proves the abstraction is real, not because the choice
is still open.

---

## Status at a glance

| Area | State |
|---|---|
| Internal ledger (`Wallet`) | ✅ shipped (M3.5) |
| `PaymentGateway` abstraction + `MockGateway` | ✅ shipped (M9 P0a) |
| Account freeze | ✅ shipped (M9 P0b) |
| Payout clearing / insurance window | ✅ shipped (M9 P0b) |
| Withdrawals (request → send → complete) | ✅ shipped (M9 P0b), against `MockGateway` |
| **Real deposit crediting (webhook)** | 🚫 [Phase 1](#phase-1--deposit-edge) |
| **Real payouts, fee estimates, batching** | 🚫 [Phase 2](#phase-2--withdrawal-edge) |
| **Reconciliation, velocity caps** | 🚫 [Phase 3](#phase-3--hardening) |
| Optional KYC gate | ✅ shipped (M9 P0c) — **off by default**, see [D2](#d2--kyc-is-not-a-phase-3-item) |
| 2FA step-up on withdrawal | ✅ shipped (M9 P0d) — **on by default** |
| New-address cooldown | ✅ shipped (M9 P0e) — 24h default |

### The one-line summary

**Money can leave Stakly but cannot enter it.** There is no deposit webhook
receiver, so `verifyWebhookSignature` / `parseWebhookEvent` are implemented and
tested with zero callers, and every balance in the system — dev *and* any future
production — comes from seeders. Phase 1 is the only phase that fixes a
functional hole; Phases 2 and 3 replace a working mock and harden it.

---

## Open decisions

Settle these before Phase 1 starts. Each has a recommendation.

### D1 — Deposit attribution model

How does an incoming deposit get matched to a user? Three options:

| Option | Attribution | Unsolicited deposit |
|---|---|---|
| **A. Permanent per-user address** ✅ *recommended* | `address → user` lookup | Credits correctly |
| B. Per-payment invoice | `order_id → user`, stored at creation | **Unattributable** after expiry |
| C. NOWPayments Custody sub-accounts | Provider holds per-user balances | Credits correctly |

**Recommend A.** It's what `ensureDepositAccount(User): DepositAccount` already
contractually promises — idempotent, same user → same address — so it needs no
interface change. More importantly it's the only option where money sent at 3am
with no active session still lands in the right ledger. Under B that's a support
ticket and a manual ledger write, which is exactly the class of operation the
append-only ledger exists to prevent.

NOWPayments' "permanent deposit address" is created through the ordinary
`/v1/payment` endpoint; the returned address accepts repeat deposits and the
partner stores it against the user. Create-once, then treat as permanent.

⚠️ **Option C conflicts with a core invariant.** Custody (`/v1/sub-partner/balance`,
`/v1/sub-partner/write-off`) maintains per-user balances on NOWPayments' side.
That's a second balance system that can drift from
`SUM(wallet_transactions.amount)`, directly against "the Postgres ledger is the
source of truth." It isn't disqualifying — it would hand Phase 3 a concrete
reconciliation target and make mass payouts easier — but adopting it is an
architectural decision, not an integration detail. Don't drift into it.

It also carries a second cost: Custody makes players visible to the provider as
account holders, which may pull end-user identity requirements in with it. See
[D2](#d2--kyc-is-not-a-phase-3-item).

### D2 — KYC is not a Phase 3 item ✅ settled, shipped as M9 P0c

**Built as a switch, off by default** (`STAKLY_KYC_ENABLED=false`). Everything
below is the reasoning; the mechanism now exists and is inert until enabled.

`App\Services\KycGate` sits in `Withdrawals::request()` beside the freeze check,
under the same row lock. It is a **tiered volume gate**: below
`stakly.kyc_threshold` (default 1000 USDT lifetime) nothing is asked. Verification
is recorded by an admin action on the user page — there is deliberately no
document-upload flow, because picking a KYC vendor is a decision we haven't made,
and a blanket gate would brick cash-out for every player the moment it was
enabled. Set the threshold to `'0'` to require verification for any withdrawal.

Two details worth keeping:

- **The volume tally counts Pending + Sending + Completed, not just Completed.**
  Counting only settled withdrawals would let a player split one large cash-out
  into several concurrent requests, each individually under the line. The row
  lock plus the in-flight tally closes that.
- **`Pending` review does not satisfy the gate.** Submitting documents nobody has
  read yet must not unblock a large withdrawal.

Gates withdrawals only — deposits, staking, and settlement are untouched.

`billing.md` currently lists "KYC/AML thresholds" alongside velocity caps under
hardening. **Split them.** Velocity caps, address cooldown, and reconciliation
protect the money and need no personal data. Collecting identity documents is a
different kind of change with its own breach surface.

**NOWPayments does not require it for our model.** Their help pages state that for
merchants working only in cryptocurrency with no fiat, KYB/KYC is asked for only
"in a rare case when a certain transaction is marked as suspicious." Stakly is
USDT in and USDT out with no fiat leg, so the default path is no merchant KYC.
Third-party comparisons mention volume thresholds triggering a document request;
NOWPayments' own docs don't state a number, so treat that as unconfirmed.

Nor do they require KYC on **our** end users. In the permanent-address model
(D1 Option A) their customer is Stakly; players are just addresses sending money,
and there is no end-user identity surface for the provider to attach terms to.

⚠️ **Custody would change that** — and this is a second, independent reason to
avoid D1 Option C. Per-user sub-accounts make players visible to NOWPayments as
account holders, which is exactly the shape where a provider can extend
requirements down to the sub-accounts. Their sub-partner KYC terms are not
publicly documented; **get a direct answer before anyone commits to Custody.**

The no-PII controls (2FA-on-withdrawal, address cooldown, reconciliation,
velocity caps) stay in Phase 3 and are still unbuilt. Whether KYC is ever legally
required is the user's call, not an engineering one, and nothing else in this
roadmap depends on the answer.

**Player-facing verification UI is deliberately not built.** With the gate off and
verification admin-driven, the 422 on the withdraw form is the whole player
surface. A submission flow needs a vendor decision that doesn't exist yet.

### D3 — `min_stake` is declared but unenforced

`config('stakly.min_stake')` is `'20'` and has **zero consumers**;
`StoreListingRequest` still validates `min:1`. Either wire it or delete the key —
a config value that lies about system behavior is worse than no config value.

Recommend wiring it. The withdrawal floor exists because TRC20 gas makes dust
withdrawals mostly-gas; the same logic applies to dust stakes, where a $1 match
generates a $0.10 rake against real settlement and API cost.

### D4 — Stuck-payout admin lever

`ProcessWithdrawal::failed()` correctly refuses to auto-reverse once
`provider_payout_id` is set — the funds may already be moving on-chain, so
crediting back would pay twice. Those rows log `critical` and wait for a human.

But **the only admin action available is Reject, which credits back the full
gross.** If the chain send did land, that's the double-pay the guard exists to
prevent, now performed manually. The queue is missing the two actions an operator
actually needs once they've checked the provider dashboard:

- **Mark completed** — the send landed; book the margin, record the tx hash.
- **Force reverse** — confirmed never sent; credit back (what Reject does today).

Recommend adding both in Phase 2 alongside the payout webhook, since the webhook
resolves most of these automatically and the manual lever is the fallback.

---

## Phase 1 — deposit edge

**The only phase that fixes a functional hole.** Ordered; each step is
independently shippable.

1. **`NowPaymentsGateway` real methods.** Drop `use Unwired;` one method at a
   time — class methods take precedence over trait methods, so the class stays
   contract-complete throughout. `ensureDepositAccount` first; it's the only one
   Phase 1 strictly needs.
2. **`users.payments_account_id`.** The provider-side account handle.
   `DepositAccount::$providerAccountId` already exists on the DTO and is never
   persisted — this is where it lands.
3. **Lazy address provisioning.** `CreateNewUser.php:44` calls
   `ensureDepositAccount()` **eagerly at registration**. Against `MockGateway`
   that's free; against NOWPayments it's an outbound HTTP call inside the signup
   transaction, and it burns a provider address for every account that never
   deposits. Drop it and rely on the deposit-page call, which is already
   idempotent and already lazy-creates. M9 P0a anticipated this explicitly.
4. **`POST /webhooks/payments`.** Mirror the FACEIT precedent exactly —
   `routes/web.php:216` sits **outside** the `{locale}` group, carries
   `throttle:60,1`, is CSRF-excluded in `bootstrap/app.php`, and authenticates in
   a dedicated middleware. Add `VerifyPaymentWebhook` doing HMAC-SHA512 over the
   recursively key-sorted JSON body against `x-nowpayments-sig`, constant-time
   via `hash_equals`, refusing with 503 when no secret is configured rather than
   accepting unauthenticated deliveries.
5. **Idempotent crediting.** `Wallet::deposit(reference: "deposit:{$txHash}")`.
   The `reference_id` UNIQUE constraint plus `Wallet`'s replay-check makes a
   redelivered webhook a silent no-op — no new mechanism needed.
6. **`deposit:` prefix in `WalletReferenceParser`.** It has 15 prefixes and none
   for deposits, so a credited deposit currently renders in the admin ledger
   without a link. `billing.md` already states the rule: add the prefix whenever
   you add one to the ledger.
7. **Credit net, not gross.** The provider's fee comes out before the credit.

### Attacker view

Per CLAUDE.md, walked before building — not after:

| Vector | Mitigation |
|---|---|
| Replayed webhook | `reference_id` UNIQUE on `deposit:{txHash}`; `Wallet` returns the existing row |
| Forged webhook | HMAC-SHA512 constant-time; fail closed on missing secret |
| Wrong-chain / wrong-token deposit | Reject at parse; never credit a token we didn't ask for |
| Amount precision confusion | BCMath strings scale 6 end to end; never cast to float |
| Address reused across users | `users.tron_address` is UNIQUE; a collision must throw, not silently reattribute |
| Deposit to a stale address after re-provisioning | Never re-provision. Addresses are permanent by D1 |
| Webhook flood | `throttle:60,1` per IP, as on FACEIT |

**Unlike the FACEIT webhook, this one cannot be re-verified downstream.** FACEIT's
receiver is defense-in-depth because the job re-fetches the result from the Data
API before settling — a forged webhook burns quota at worst. A forged *deposit*
webhook mints money directly. The signature check is the only thing standing
between an attacker and the ledger, so it gets no `TODO` and no soft-fail path.

---

## Phase 2 — withdrawal edge

Replaces a working mock. `billing.md` notes only `Withdrawals::send()`'s body
changes — that's still true.

1. **Real `createPayout` + `estimatePayoutFee`.** `send()` already maps all four
   `GatewayPayoutStatus` cases and passes `wd:{id}` as the provider idempotency
   key, so this is genuinely a body swap.
2. **Payout webhook to resolve `Sending` rows.** ⚠️ **Today nothing completes
   them.** `markCompleted` has exactly two callers: `send()` and tests. This is
   latent only because `MockGateway` always returns `Completed` — the moment a
   real async provider returns `Queued`, rows park in `Sending` forever with no
   completer and no reconciliation sweep. This is the single highest-risk item in
   Phase 2 and must ship *with* step 1, not after it.
3. **Admin levers from [D4](#d4--stuck-payout-admin-lever)** — Mark completed /
   Force reverse.
4. **Mass-payout batching.** One gas fee for many withdrawals. This is what
   reintroduces `WithdrawalStatus::Approved`, which was deliberately omitted
   because nothing needed a queued-but-unsent state.

---

## Phase 3 — hardening

No PII required for any of these.

1. ~~**2FA required to withdraw.**~~ ✅ **Shipped early as M9 P0d** — pulled
   forward because it needed neither the provider pick nor sandbox access. Step-up
   TOTP on every withdrawal, on by default. See `billing.md`.
2. ~~**Withdrawal address cooldown.**~~ ✅ **Shipped early as M9 P0e** —
   first send to a new address is held 24h with a mail notice. See `billing.md`.
3. **Reconciliation.** Provider custody balance vs `SUM(wallet_transactions.amount)`,
   on a schedule, alerting on drift. This is how you find out something broke at
   all — worth building before the caps below.
4. **Velocity caps / daily limits.** Bounds worst-case loss from an exploit
   nobody predicted.
5. **Go-live checklist.**

---

## Cross-cutting — throttle + circuit breaker

CLAUDE.md requires every outbound third-party call to ship with client-side
throttling, 429 handling, and a circuit breaker. Payments currently has **none**:

- `ProviderCircuitBreaker` is typed on `LinkedAccountProvider` across **13 method
  signatures**. It cannot accept a payment provider without generalization —
  either a union type or a shared interface implemented by both enums.
- No `payments-api` limiter is registered in
  `AppServiceProvider::registerProviderRateLimiters()`.
- `services.payments.nowpayments.requests_per_minute` and `circuit_breaker` are
  already declared in config, waiting for a consumer.

Lands **with** the first real client, not before — M9 P0a deferred it deliberately
because refactoring the breaker shared with the game pipeline while it has no
payments caller is risk with no upside.

⚠️ Note the failure mode is worse here than for game APIs. A tripped breaker on
chess.com delays settlement; a tripped breaker on payouts freezes cash-out for
every user at once. Size the cooldown accordingly and surface breaker state in
the admin withdrawal queue.

---

## Known gaps outside the phases

Found auditing the code against `billing.md`. None block a phase; all are small.

| Gap | Where |
|---|---|
| `/wallet/withdrawals` has no inbound link anywhere in the UI | sidebar, mobile menu, wallet index |
| `min_withdrawal` enforced only at the HTTP layer — `Withdrawals::request()` checks only `amount > margin` (0.50), so a service or admin call bypasses the 10 USDT floor | `Withdrawals::request` |
| `Wallet` idempotency matches on `reference_id` alone, not user/type/amount — safe only because every prefix embeds an id | `Wallet::findByReference` |
| `user_moderation_logs.action` is an unconstrained `string(16)`; its migration comment still says "'ban' \| 'unban' for now" though freeze/unfreeze now write to it | migration + `ViewUser` |
| `withdrawals.rejected_reason` is 255 but `user_moderation_logs.reason` is 1000 | two migrations |

---

## Definition of "fully working"

Payments are done when all of these hold:

- A real on-chain deposit credits the correct user's ledger, **net**, exactly once
  under webhook redelivery.
- A real withdrawal reaches chain and resolves to a terminal status **without
  manual intervention** — including the async `Queued → Sending → Completed` path.
- No withdrawal can sit in a non-terminal status indefinitely; every state has a
  completer or a sweep.
- Reconciliation reports zero drift between provider custody and the ledger.
- `users.usdt_balance == SUM(wallet_transactions.amount)` still holds for every
  user, and `wallet_transactions` has still never been `UPDATE`d.
- Every path above has Pest coverage. No financial code ships without tests.

---

## Test plan

Money suites must stay green at every step:

```bash
sail artisan test --compact --filter='Wallet|Withdrawal|Freeze|Clearance|Payment'
```

Baseline at the time of writing: **189 passed, 758 assertions.**

Per phase, the new coverage that must exist before it counts as shipped:

- **Phase 1** — signature accept/reject, replayed webhook credits once, malformed
  payload mutates nothing, unknown address is refused, net-vs-gross crediting,
  ledger invariant after a deposit.
- **Phase 2** — each `GatewayPayoutStatus` path end to end, payout webhook
  resolves a `Sending` row, webhook replay is a no-op, both admin levers, batching
  conserves value.
- **Phase 3** — withdrawal refused without 2FA, address cooldown blocks then
  releases, velocity cap boundary, reconciliation detects an injected drift.
