# Stakly — Billing Execution Plan

Companion to **`.ai/billing-plan.md`** (the decision doc). This is the step-by-step build plan.

**Legend:** ✅ safe to build now (pure ledger, no chain) · 🚫 paused M9 (needs explicit go-ahead) · 🧪 has required tests.

**Golden rules carried in (CLAUDE.md):** all money writes go through `App\Services\Wallet`; BCMath strings at scale 6; idempotency via `reference_id`; no financial path without Pest tests; migrations edited in place + `migrate:fresh` while there are no real users; `vendor/bin/sail` prefix on every command; Pint before finalizing; regenerate Wayfinder with `--with-form` (or `npm run build`).

---

## Phase 0 — Ledger foundation (✅ build now)

Goal: the freeze + two-phase withdrawal machinery, wired end-to-end against seeded data with a **mock send** (mirrors how `MockTronAddress` / the deposit page already mock the chain). When M9 resumes, only the "send" step swaps to NOWPayments — nothing else changes.

### Step 0.1 — Money config (centralize the knobs) 🧪
- **Files:** new `config/stakly.php` (or extend an existing project config if present — verify first).
- **Keys:**
  ```php
  'rake_percent'            => 10,      // platform commission on the pot
  'withdrawal_margin'       => '0.50',  // flat platform margin (USDT string)
  'withdrawal_review_days'  => 1,       // anti-cheat hold window (0 = instant)
  'min_stake'               => '20',    // raised from $5–10 so gas isn't a big %
  'min_withdrawal'          => '10',
  ```
- **Refactor:** move `WithdrawRequest::MIN_WITHDRAWAL` to `config('stakly.min_withdrawal')`. Keep `TRC20_REGEX` where it is.
- **Why first:** every later step reads these; avoids magic numbers in money code.

### Step 0.2 — Account-level freeze 🧪
- **Migration (edit in place):** `database/migrations/0001_01_01_000000_create_users_table.php` → add:
  - `frozen_at` `timestamp` nullable
  - `frozen_reason` `string` nullable (audit)
- **Model:** `User` — cast `frozen_at` to `datetime`; helper `isFrozen(): bool`.
- **Guard in `App\Services\Wallet`:** in the private `record()` (or specifically in `withdraw` + `hold`), if `$user->isFrozen()` throw a new `App\Exceptions\AccountFrozenException`.
  - **Decision:** freeze blocks **both** `withdraw` *and* `hold` — a frozen user can neither cash out nor enter/stake a new match. `release`/`payout`/`fee`/`deposit` (credits) remain allowed so in-flight matches can still settle and refunds can land.
- **Run:** `vendor/bin/sail artisan migrate:fresh --seed`.
- 🧪 **Tests** (`WalletTest.php` or new `AccountFreezeTest`): frozen user → `withdraw` throws; `hold` throws; `deposit`/`release`/`payout` still succeed; balance unchanged on blocked ops.

### Step 0.3 — Withdrawal lifecycle: schema + enum + model 🧪
- **Enum:** `app/Enums/WithdrawalStatus.php` — `Pending`, `Approved`, `Sending`, `Completed`, `Rejected`, `Failed`.
- **Enum addition:** `app/Enums/WalletTransactionType.php` — add `WithdrawalReversal = 'withdrawal_reversal'` (credit) for rejected/failed withdrawals. *(Alternative: reuse `Deposit` with a clear description — rejected for cleaner reporting.)* Update the credit/debit sign map and any frontend `WalletTransactionType` union + history filter chips.
- **Migration (new):** `create_withdrawals_table`:
  | column | type | notes |
  |---|---|---|
  | `id` | bigint pk | |
  | `user_id` | fk → users | cascade |
  | `amount` | decimal(18,6) | gross debited from balance |
  | `platform_fee` | decimal(18,6) | the flat margin |
  | `destination_address` | varchar(42) | TRC20 now; widen for BEP20 later |
  | `status` | varchar | cast `WithdrawalStatus` |
  | `review_until` | timestamp nullable | now + `withdrawal_review_days` |
  | `debit_transaction_id` | fk → wallet_transactions nullable | the `Withdrawal` ledger row |
  | `provider` | varchar nullable | `'mock'` now, `'nowpayments'` later |
  | `provider_payout_id` | varchar nullable unique | NOWPayments payout id |
  | `tx_hash` | varchar nullable | on-chain hash when sent |
  | `network_fee` | decimal(18,6) nullable | actual gas (filled at send) |
  | `rejected_reason` | varchar nullable | |
  | `reviewed_by` | fk → users nullable | admin who actioned |
  | timestamps | | |
  - Index `user_id`, `status`, `review_until`.
- **Model + factory:** `Withdrawal` with states for each status; `User hasMany withdrawals`.
- 🧪 **Tests:** factory builds each status; relationships resolve.

### Step 0.4 — Withdrawal service (money logic) 🧪
- **File:** `app/Services/Withdrawals.php` (mirrors `Wallet` static style, or inject `Wallet`).
- **Ledger accounting per the decision doc:** on **request**, debit the full requested `amount` from the user via `Wallet::withdraw` and credit the `platform_fee` margin to the platform user via `Wallet::fee`. The remaining `amount − margin` is what gets sent on-chain (gas deducted from it by NOWPayments at send time). Conservation holds: `user −amount`, `platform +margin`, `(amount − margin)` leaves custody.
- **Methods:**
  - `request(User $user, string $amount, string $address): Withdrawal`
    1. Validate not frozen, amount ≥ `min_withdrawal`, ≤ balance (BCMath `bccomp`), address matches `TRC20_REGEX`.
    2. Create `Withdrawal` row (`pending`, `review_until = now + review_days`).
    3. `Wallet::withdraw($user, $amount, reference: "wd:{$withdrawal->id}")` → store `debit_transaction_id`.
    4. `Wallet::fee(config('stakly.withdrawal_margin'), ...)` *(margin to platform; see note)* — **or** defer margin capture to send time. **Decision:** capture margin at send (so a rejected withdrawal doesn't book phantom revenue).
  - `approve(Withdrawal $w, User $admin)` → status `approved` (only if past `review_until`, not frozen).
  - `reject(Withdrawal $w, string $reason, User $admin)` → `Wallet::deposit`/`WithdrawalReversal` credit back the full `amount` (reference `wd-reversal:{id}`, idempotent), status `rejected`.
  - `send(Withdrawal $w)` → **MOCK now:** mark `sending` → set fake `tx_hash`/`network_fee` → `completed`; book the margin via `Wallet::fee`. *(Phase 2 swaps the mock body for the NOWPayments payout call — signature unchanged.)*
  - `markFailed(Withdrawal $w, string $reason)` → reverse the debit (credit back), status `failed`.
- **Idempotency:** every `Wallet` call uses the withdrawal id in `reference_id`.
- 🧪 **Tests** (`WithdrawalTest`): request debits + creates pending row; reject restores balance exactly; double-reject is a no-op (idempotent); cannot approve before `review_until`; frozen user can't request; `users.usdt_balance == SUM(wallet_transactions.amount)` invariant holds across request→reject and request→send; insufficient balance refused.

### Step 0.5 — Controller wiring 🧪
- **File:** `app/Http/Controllers/WalletController.php`.
- Replace the launch-gated short-circuit in `withdrawStore` with `Withdrawals::request(...)`; flash success ("Withdrawal requested — under review"). Keep `abort_if($user->is_platform, 403)`.
- Add `GET /wallet/withdrawals` (or fold into history) returning the user's withdrawals via a resource.
- **Resource:** `WithdrawalResource` (floats only at the boundary — `(float) $amount`).
- **Routes:** add under the existing `wallet.` group; regenerate Wayfinder with `--with-form` (or `npm run build`).
- 🧪 **Tests:** `withdrawStore` happy path creates pending + debits; validation 422s (bad address, below min, over balance) still exercised; platform user → 403.

### Step 0.6 — Admin review UI (Filament v5) 🚫→✅ (safe; internal only)
- **Filament resources:** `WithdrawalResource` (list pending/under-review, approve/reject actions gated on `review_until`), `UserResource` action to freeze/unfreeze (`frozen_at` + reason).
- Activate `laravel-best-practices`; check existing Filament panel/resources for conventions before adding.
- 🧪 **Tests:** Filament action tests for approve/reject/freeze (or feature tests on the underlying service if panel testing is heavy).

### Step 0.7 — Frontend (two-phase UX) 🧪
- Activate `inertia-react-development` + `ui-ux-pro-max`; obey the Stakly visual system (motion for state reveals — wallet success states are called out in CLAUDE.md).
- **`resources/js/pages/wallet/withdraw.tsx`:** after submit, show a "Under review" state; render a **fee preview** (Phase 0: mock estimate from config margin + a placeholder gas; Phase 2: real `/payout/fee`). Breakdown UI:
  ```
  Withdraw / Network fee (est.) / Platform fee / You'll receive
  ```
- **Pending withdrawals list** on `/wallet` or `/wallet/withdrawals` with `WithdrawalStatus` chips (reuse `transaction-type-chip.tsx` pattern).
- **Deposit page breakdown:** add the `$100 → −$0.50 → $99.50` itemization + "processing fee" tooltip.
- **Types:** extend `resources/js/types/wallet.ts` (`Withdrawal`, `WithdrawalStatus`).
- 🧪 Smoke test the wallet pages for JS errors (pest browser/smoke per `pest-testing`).

### Step 0.8 — Seeders
- Seed varied withdrawals (pending/under-review/completed/rejected) + at least one frozen user — **only via `Wallet`/`Withdrawals` services**, never direct balance writes (CLAUDE.md). Re-run `migrate:fresh --seed`.

**Phase 0 done when:** freeze + two-phase withdrawal work end-to-end against seeded data with a mock send, all Pest tests green (`vendor/bin/sail artisan test --compact`), Pint clean.

---

## Phase 1 — Deposit edge (🚫 paused M9; sandbox-first)

Goal: real per-user deposit addresses + credit-on-confirmed-deposit. Build against `sandbox.nowpayments.io` using the `case` emulation — no real/testnet funds needed.

### Step 1.1 — NOWPayments client + config
- `config/services.php` → `nowpayments` block: `base_url` (sandbox vs prod), `api_key`, `ipn_secret`, `custody` toggle. **Secrets in `.env`, never committed.**
- `app/Services/NowPayments/Client.php` — thin wrapper (create deposit account, get payout fee, create payout). Use queued HTTP where possible (perf rule).
- 🧪 Mock HTTP responses with sandbox `case` fixtures.

### Step 1.2 — Per-user deposit accounts
- New column `users.nowpayments_account_id` (varchar nullable unique).
- On registration: call Custody API to create/fetch the user's deposit account → store its **persistent TRC20 address** in `users.tron_address` (replaces `App\Support\MockTronAddress`; keep `MockTronAddress` as the local/testing fallback behind the `custody` toggle).
- ⚠️ **Verify first** (decision doc §2): persistent-per-user-address support + exact endpoints.

### Step 1.3 — Deposit webhook (the security boundary)
- Route `POST /webhooks/nowpayments` (no auth middleware; **IPN HMAC signature verification** instead, as dedicated middleware).
- Handler: verify signature → resolve user by `nowpayments_account_id`/address → `Wallet::deposit($user, $amount, reference: $tx_hash, description: ...)`. Idempotent by `tx_hash` (webhook retries are no-ops).
- Credit the **net** (after the 0.5%) per the pass-through decision.
- 🧪 Tests: valid signature credits once; replayed webhook is a no-op; bad signature → 401; unknown address → logged, no credit.

---

## Phase 2 — Withdrawal edge (🚫 paused M9; sandbox-first)

Goal: swap the Phase 0 mock `send()` for real NOWPayments payouts. **No other code changes** — the service signature and ledger accounting are already built.

### Step 2.1 — Real payout in `Withdrawals::send()`
- Queued job `ProcessWithdrawal` → `Client::createPayout(address, amount − margin, receiverPaysFee: true)`; store `provider_payout_id`; status `sending`.
- Book the margin via `Wallet::fee` on success.
- Retries with backoff; on permanent failure → `markFailed` (reverses the debit).

### Step 2.2 — Fee estimate on the withdraw page
- `GET` proxy to `POST /v1/payout/fee` → real network-fee estimate shown before confirm (replaces the Phase 0 mock estimate).

### Step 2.3 — Mass-payout batching
- Scheduled job: collect `approved` withdrawals past `review_until`, batch into one Mass Payout call (one gas fee for many). The review window doubles as the batch window.

### Step 2.4 — Payout status webhook
- Extend the webhook handler for payout events → mark `completed` (store `tx_hash`, actual `network_fee`) or `failed` (reverse).
- 🧪 Sandbox `case` tests for sent/confirmed/failed payouts.

---

## Phase 3 — Hardening (🚫 paused M9)

- **Reconciliation command** (scheduled): NOWPayments custody balance vs. `SUM(wallet_transactions.amount)`; alert on drift.
- **Rate limits / daily caps** on withdrawals; per-user velocity checks (laundering vector).
- **KYC/AML thresholds** before large withdrawals (provider TBD — flag, don't assume).
- **Reserve monitoring** (only if the "clean $100 / absorb deposit fee" model is ever adopted).
- **Go-live checklist:** testnet/sandbox → mainnet flip; secret/key management review; hot-wallet/custody incident playbook; final confirmation we're leaving dev posture (the one technical clarifying question allowed per CLAUDE.md).

---

## Suggested order & dependencies

```
0.1 config ─▶ 0.2 freeze ─▶ 0.3 schema/enum ─▶ 0.4 service ─▶ 0.5 controller ─▶ 0.7 frontend ─▶ 0.8 seeders
                                              └▶ 0.6 admin UI (parallel)
                                              └▶ tests at every step (🧪)
   ── M9 GO-AHEAD ──
1.1 client ─▶ 1.2 accounts ─▶ 1.3 deposit webhook
2.1 payout ─▶ 2.2 estimate ─▶ 2.3 batching ─▶ 2.4 payout webhook   (depends on Phase 0 service)
3.x hardening
```

**Build now:** Phase 0 only. **Everything Phase 1+** waits for explicit M9 go-ahead and rides the NOWPayments sandbox first. The Phase 0 ledger/service/UI is provider-agnostic — when M9 resumes, the deposit credit and the `Withdrawals::send()` body are the only seams that change.
