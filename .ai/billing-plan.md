# Stakly — Billing & Custody Decision Doc (M9 reference)

**Status:** Decision record / design. **M9 chain integration remains PAUSED** — no chain SDKs, webhook endpoints, or key-storage code to be written without explicit go-ahead. Only *Phase 0* (pure-ledger) below is safe to build now.
**Date:** 2026-05-25
**Scope:** How Stakly holds user balances, moves USDT in/out, and prices deposits/withdrawals/matches.

---

## 1. Core model: custodial, not P2P-payments

Stakly is a **P2P marketplace** (players matched against players) but a **custodial platform** for money. These are different axes and they coexist — Binance P2P, LocalBitcoins, OKX P2P are all "P2P" yet custodial under the hood.

**Money never moves user-to-user.** It flows:

```
user's external wallet (Bybit/Kraken) ──deposit──▶ STAKLY custody ──withdraw──▶ user's external wallet
                                                  (internal ledger holds it)
```

**Why custodial is required (not a preference):**
- A stake is a *conditional* payment — locked before the match, released after based on an outcome that doesn't exist yet. "Users just send to each other" fails: the loser has no incentive to pay.
- Stakly must be able to **escrow** both stakes during a match and **freeze** a balance for anti-cheat review. You can only escrow/freeze funds **you hold**.
- A true non-custodial (smart-contract / web3) escrow would make escrow, freezes, and refunds *harder*, cost gas per match, and add audit/security burden. **Out of scope** — not to be built without explicit go-ahead (see CLAUDE.md).

**The mental model:** the blockchain only knows anonymous addresses; **Stakly's database is the identity + balance + state layer**. Map address ↔ user at the edges; everything in between (balances, escrow, payouts, freezes, refunds) is internal rows keyed by `user_id`, fully under Stakly's control.

---

## 2. Provider decision: NOWPayments (Custody mode)

Chosen over the alternatives for a solo-dev, pre-launch posture.

| Approach | Verdict |
|---|---|
| **Payment gateway — NOWPayments Custody** | ✅ **Chosen.** Cheapest base fee, custody/sub-account/mass-payout feature set built for balance-holding platforms, sandbox, no private key to protect. |
| Managed primitives (Tatum/Fireblocks/BitGo) | Fallback if gateway can't issue persistent per-user addresses. Reopens key-custody question; higher integration cost. |
| DIY HD wallet + TronGrid | Max control, max burden (master seed, hot wallet, energy/sweep machinery). Realistically needs the M9 specialist + meaningful volume to justify. |

**Why NOWPayments specifically:**
- **Custody API** explicitly *"creates deposit accounts for users, generates deposits to top up the balance, and withdraws funds from it"* — i.e. per-user deposit accounts, top-ups, payouts. This is Stakly's model almost verbatim.
- **Sandbox** at `sandbox.nowpayments.io` — test the full flow with **no real funds and no testnet coins** via a `case` parameter that emulates payment states and fires the matching webhooks. Build deposit-credit + withdrawal paths end-to-end before any mainnet go-live.
- Net pass-through fee structure removes the need for Stakly to track gas.

### Pricing (2026)

| Item | NOWPayments | (CryptoCloud, for reference) |
|---|---|---|
| Deposit (incoming) fee | **0.5%** | 1.9% std / 0.4% negotiated |
| Withdrawal/payout fee | **0%** + network gas | 0% + network gas |
| Per-user wallet creation | **$0** (no per-wallet/account fee) | — |
| Setup / monthly | $0 | $0 |
| Mass Payouts | **No per-payout service fee**; batch many withdrawals into one tx = one gas fee | — |

### To verify with NOWPayments before integration (not blockers)
- Custody API endpoints for issuing a **persistent per-user deposit address** (vs. fresh address per top-up). Either works; persistent is nicer for the deposit page QR.
- **IPN / webhook signature scheme** — the security boundary on the deposit-credit path (verify callbacks are genuinely from NOWPayments, not spoofed).
- Custody requires **pre-funding a balance** from which payouts are disbursed.

---

## 3. The rail: Tron / TRC20

- **Tron** is the network (like Ethereum/BNB Chain). **USDT (TRC20)** is the dollar-token on that network; addresses start with `T`. TRC20 won the USDT-transfer market on cost (~$1–3, ~3s settlement), which is why Bybit/Kraken/most users default to it.
- **TRC20 has no memo field** — so the **per-user deposit address IS the identity**. Whatever lands at a user's address is theirs, automatically. (This is why the manual "submit a tx hash to claim a deposit" idea is wrong: tx hashes are public and claimable by anyone, and exchange withdrawals come from shared omnibus addresses so sender ≠ identity.)
- **BEP20 (BNB Chain)** is the obvious next rail to add; the internal ledger is rail-agnostic so adding it changes nothing internal.
- **NOWPayments is the issuer/key-holder; Tron is the network.** Analogy: NOWPayments = Gmail (creates & runs the mailbox, holds the keys); Tron = the email system (routes mail to it). Custodial by construction — neither user nor Stakly holds the key.

### Tron gas facts (drive the fee model)
- Gas is a **fixed cost per transfer, not a % of amount.** $9 or $9,000 costs the same.
- **~$1–2** to an address that already holds USDT (exchanges, active wallets — the common case).
- **~$3–4** to a fresh/empty address (the "fresh-wallet surcharge"), + ~$0.30 one-time activation for brand-new addresses.
- **~$3–4 is the practical max** for a simple USDT transfer (no open-ended computation like Ethereum).
- Dollar cost moves with TRX price → a *flat* fee is exposed to TRX appreciation.
- Who pays: **deposits → the sender (user/exchange) pays, free to Stakly. Withdrawals → Stakly pays gas (recovered from the user). Internal escrow/payouts → never touch the chain, zero gas.**

---

## 4. Fee model (decided)

### Deposit
- **0.5% NOWPayments fee, passed through** to the user. Credit the **net**.
- UI shows the breakdown so it's never a surprise:
  ```
  Deposit:        $100.00
  Processing fee:  −$0.50  (0.5%)
  Credited:        $99.50
  ```
- Label it a generic **"processing / network fee"** — no need to name NOWPayments. Optional tooltip: *"Charged by our payment processor, not Stakly."*
- Stakly fronts nothing; holds exactly what it shows (no reserve gap).
- *(Alternative considered — show clean $100 by absorbing the 0.5%. Rejected for launch: requires a %-based withdrawal fee ≥ 0.5% to recover at scale + an external reserve to manage. Revisit later if "$100 = $100" becomes a competitive UX point.)*

### Match rake
- **10% of the pot → Stakly.** This is the real revenue. Collected at match settlement.
- Present to users as **"winner keeps 90% of the pot"** (positive framing).
- Internal awareness: 10% of pot ≈ **20% of the winner's actual winnings** (winner only *won* the opponent's stake). Quote the pot-based number to users — it's the honest, friendlier one — and stay consistent.
- 10% is competitive/low for 1v1 skill-wager platforms (peers take 10–20%). Levers for later: tiered/capped rake to retain high-stake players. Flat 10% is a fine launch default.

### Withdrawal — **dynamic gas + small flat margin**
```
Withdrawal fee = actual network gas (dynamic, auto)  +  flat platform margin (~$0.50, optional)
```
- **Gas → dynamic, via NOWPayments "Withdrawal Fee Paid By: Receiver"** (Settings → Payments → Payment Details). NOWPayments measures the real fee and deducts it from the payout automatically. Never lose, never re-tune for TRX price.
- **Margin → flat ~$0.50** added by Stakly in `WithdrawRequest` logic (gas-recovery overflow, not real revenue; optional).
- **Show the estimate before confirm** via `POST /v1/payout/fee`:
  ```
  Withdraw:        $18.00
  Network fee:     −$1.80   (estimated)
  Platform fee:    −$0.50
  You'll receive:  $15.70
  ```
- **DO NOT double-charge gas.** Pick one lane:
  - **Lane A (chosen): Receiver-pays** → NOWPayments auto-deducts exact gas; Stakly adds only the flat margin. Bulletproof.
  - **Lane B (fallback): Sender-pays** → Stakly pays gas from custody and charges a flat fee (e.g. **$2.70**) to reimburse. Predictable UI number; accept rare fresh-address overage (covered by rake) + manual re-tune if TRX rises.
- **Mass Payouts**: queue withdrawals through the two-phase review window (below) and **batch into one on-chain tx = one gas fee** for several users. Fits the perf rule (queue external calls) and cuts total gas.

### Minimum stake / withdrawal
- Raise the **minimum stake to ~$20–50** and keep a **minimum withdrawal (~$10–20)**. At $5–10 stakes the fixed ~$1–2 gas is 10–45% of the prize and feels punishing even though the rake is only 10%. Bigger stakes make gas a rounding error and the 10% rake sit comfortably.

### Net positions (sanity)
- **Deposit-and-withdraw, never play:** Stakly nets **$0** (user paid the 0.5% deposit fee and the withdrawal gas). Break-even on idle money is fine.
- **Withdrawal:** Stakly nets **$0** on gas (pass-through) + small flat margin. **Never in a loss position.**
- Reminder: deposit-and-run is also a **money-laundering / fund-rotation vector** (in CLAUDE.md open questions). Fees add friction now; later add **KYC thresholds** + possibly a minimum-activity rule before large withdrawals. Don't design assuming every depositor is a genuine player.

### Worked example (two players, $10 stake each, 10% rake)
| Step | Amount |
|---|---|
| Pot | $20 |
| Rake (10%) → Stakly | −$2 |
| Winner credited | $18 |
| Winner withdraws $18, fee ~$2.50 (gas+margin) | −$2.50 |
| Winner receives | **~$15.50** |
| Winner net profit (vs $10 in) | **~$5.50** |

At $10 stakes ~45% of *winnings* goes to rake+gas — **the gas, not the rake, is the culprit.** At $100 stakes the winner keeps ~78% of winnings and gas is negligible. → raise minimum stakes.

---

## 5. Anti-cheat freeze + two-phase withdrawal (Phase 0 — pure ledger, build now)

The freeze-for-cheating capability lives **entirely in the internal ledger, above the chain** — it works identically regardless of provider, so it's safe to build while M9 is paused. It's also the dispute-window gate CLAUDE.md requires before any payout code ships.

- **Account-level freeze:** `users.frozen_at` (nullable timestamp). `Wallet::withdraw` / `Wallet::hold` refuse when set. List frozen accounts via `WHERE frozen_at IS NOT NULL`. After review: clear the flag (release) or claw back via a reversing ledger entry / forfeit (policy TBD).
- **Two-phase withdrawal:** a `withdrawals` table with states `pending → review_until → approved/sending → completed/rejected`.
  - Request **debits balance immediately** (so it can't be double-spent during review) — nothing on-chain yet.
  - After the review window + anti-cheat checks: **approve** → fire the on-chain payout; **reject** → reverse the hold, credit balance back.
  - The review-window queue doubles as the **mass-payout batching window**.
- **Tests (Pest):** freeze blocks withdraw/hold; rejected withdrawal restores balance; the `users.usdt_balance == SUM(wallet_transactions.amount)` invariant holds across the new flows; idempotency preserved.

---

## 6. Phased implementation plan

**Phase 0 — Ledger additions (safe now, no chain risk):**
- `users.frozen_at` + guard in `Wallet`.
- `withdrawals` table + two-phase state machine.
- `WithdrawRequest`: add the flat platform margin; wire `MIN_WITHDRAWAL` / minimum stake config.
- Pest coverage for all of the above.

**Phase 1 — Deposit edge (M9, on go-ahead; sandbox first):**
- On registration, request a **per-user deposit account/address** from NOWPayments Custody → store in `users.tron_address` (replaces `App\Support\MockTronAddress`).
- **Signed webhook endpoint**: "deposit confirmed at address X" → look up user by address → `Wallet::deposit(user, amount, reference: tx_hash)`. The existing `reference_id` makes it **idempotent** (webhook retries are no-ops).

**Phase 2 — Withdrawal edge (M9, on go-ahead; sandbox first):**
- Replace `WalletController::withdrawStore` short-circuit with: create a `pending` withdrawal (Phase 0).
- Queued worker, after review window: call NOWPayments **payout API** (Receiver-pays), store returned tx hash, mark `completed`; retries on failure. Keeps the chain call off the request cycle (perf rule).

**Phase 3 — Hardening:**
- Scheduled reconciliation: NOWPayments custody balance vs. internal ledger; alert on drift.
- Withdrawal rate limits / per-day caps; admin freeze/review UI.
- **All on sandbox / testnet posture until explicit go-live.**

---

## 7. What stays unchanged (provider-agnostic)

The pieces below are deliberately decoupled from the provider and do **not** change when chain integration lands:
- **Internal ledger** — `wallet_transactions` (append-only, idempotent via `reference_id`) + `App\Services\Wallet`. Source of truth for `users.usdt_balance`. Invariant asserted in `WalletTest.php`. Whatever provider is used calls `Wallet::deposit` on confirmed deposits and `Wallet::withdraw` from the queued worker.
- **Money rules** — all writes through `Wallet`; BCMath strings at scale 6; floats only at the API-resource boundary; platform rake via `Wallet::fee(...)` to the `is_platform` user.
- **Wallet UI** (M7) — overview / deposit / withdraw / history. Real per-user addresses replace the mocks; the withdraw POST swaps the short-circuit for the worker dispatch.

---

## 8. Open questions (unchanged from CLAUDE.md, surfaced here)
- **Custody/key-storage specifics** — owned by the M9 specialist if DIY ever revisited; N/A while on NOWPayments custody.
- **KYC/AML** — thresholds/provider TBD; relevant to the deposit-and-run / laundering vector.
- **Incident response** — hot-wallet/custody-compromise procedure (mostly NOWPayments' responsibility in custody mode, but Stakly needs a playbook).
- **Anti-collusion / sandbagging** — broader than billing; tracked in milestones M6.

---

### TL;DR
Custodial via internal ledger; **NOWPayments Custody** on **Tron/TRC20**, sandbox first. **Deposit:** 0.5% passed through, show net $99.50. **Match:** 10% rake ("winner keeps 90%"). **Withdraw:** dynamic gas (Receiver-pays) + ~$0.50 flat margin, estimate shown before confirm, batched via Mass Payouts. **Min stake raised** so gas isn't a big % of small prizes. **Stakly never sits in a loss position.** Build **Phase 0** (freeze + two-phase withdrawal, pure ledger) now; Phases 1–3 are paused M9 work pending go-ahead.
