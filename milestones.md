# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M11, M8 all phases, M10, M16 all phases, M14 Slice A). This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Active / upcoming:**

- **M12** — Filament admin panel + chat-driven dispute resolution **← next**
- **M13** — Chat anti-abuse + moderation
- **M14** — Automated outcome adapters (volume-triggered optimization; Slice A shipped)
- **M15** — Multi-game expansion (FACEIT, OpenDota, Riot adapters)
- **M9** — Chain Integration [paused — pending crypto-payment-gateway specialist]

> Active milestone keeps a detailed task list. Future milestones expand when started. Any of this can shift — flag the change, update the doc.

---

## Architectural decisions

Decisions made earlier that have shaped a lot of code downstream. Not locked — revisit if the situation changes, just expect a ripple of refactor when you do.

- **Money writes only through `App\Services\Wallet`** (M3.5). `users.usdt_balance` and `wallet_transactions` are written ONLY by Wallet service methods. The invariant `users.usdt_balance == SUM(wallet_transactions.amount)` is asserted in `WalletTest.php`. Direct writes from controllers / seeders / migrations / factories / tinker break this.
- **Money math is BCMath strings, never floats** (M3.5). Internal arithmetic at scale 6 via `bcadd` / `bcsub` / `bccomp`. Floats only appear at the API resource boundary.
- **Append-only ledger** (M3.5). No `updated_at` on `wallet_transactions`. Idempotency via optional `reference_id` — repeat calls return the existing row silently.
- **Actions pattern** (M11). Business logic lives in `app/Actions/<Domain>/<Verb><Noun>Action.php` with a `handle()` method, container-injected. Controllers and commands are thin adapters. `App\Services\Wallet` and `App\Services\GameApi\*` stay as primitives, not Actions. Decompose long `handle()` bodies into private helpers so `handle()` reads like a recipe of high-level steps.
- **`MatchStatus` state machine guard** (M6 Phase 7). `SettleMatchAction` / `SettleDrawMatchAction` no-op on `Settled` (idempotency), proceed on `Pending` / `Disputed`, throw on `ManualReview` or unknown. Future admin tools resolving `ManualReview` must use a different path.
- **`ManualReview` is admin-resolved out-of-band**, not in player chat (M6 Phase 7). Admin reads via Filament dashboard (M12); no admin-in-chat.
- **Snapshot, don't link** when a relationship needs to survive identity changes (M8 Phase 4). Linked-account usernames are denormalized onto the sibling `match_provider_snapshots` table at match creation so a mid-match unlink doesn't break dispute resolution. Sibling table (not flat columns on `game_matches`) so per-provider identifier shape can grow with multi-identifier games (M15) without re-migrating the wide table.
- **One arbitration driver per game family** (M14 Slice A). `ChessGameApi` handles both Lichess and chess.com cards via the card's `provider` discriminator. M15 game adapters (FACEIT, Riot, etc.) each get their own sibling driver. No multi-provider chain wrappers — the card carries its own provider field.
- **`is_platform = true` users are never user-facing** (M3.5 + M5). Filtered from profile show, wallet UI, listing pages.
- **No chain code or smart contracts right now** (project-wide). Custodial via internal Postgres ledger; chain integration is M9, paused for a specialist.
- **Strongest anti-cheat per game** (M8 + M15). Stakly only takes stakes on matches played on the strongest available anti-cheat platform for the relevant game. The verification provider (who tells us the result) and the anti-cheat platform (where the match must be played) are conceptually separate — sometimes the same vendor (FACEIT for CS2, Riot for Valorant), sometimes different (Steam-ranked Dota 2 verified via OpenDota). Per-game adapter pattern via `LinkedAccountProvider` enum + `ProfileClient` interface + `listings.platform` column.
- **User-supplied free-text never lands in system messages** (M10 Phase 3). System messages bypass the M13 chat anti-abuse layer by construction. Any user-supplied text (cancellation reasons, future dispute notes, etc.) surfaces in structured banner UI we control — never spliced into chat lifecycle narration. The banner is the sanitization surface; chat stays for player-to-player communication that DOES go through M13 filters.
- **Outcome is API-truth, not player self-report** (M16). Match results come from the game API (Lichess stream, chess.com archive polling) — not from "I won / lost / drawn" player buttons. Player self-reports were always non-binding (the API was the tiebreaker on disagreement); M16 removes the redundant confirm layer entirely. The dispute surface (`Report a problem`) survives as the manual escalation path for unresolvable cases. "Mutual cancellation" (M10) remains the cooperative early-exit when no game gets played.

---

## M12 — Filament admin panel + chat-driven dispute resolution

Pulled forward from "pre-launch gate" because chat-first dispute resolution requires admin tooling. Without M12, M8's chat sits alongside the existing `MockGameApi` dispute path — useful but not the primary mechanism. M12 makes chat the source of truth for disputes.

### Phases

**Phase 1 — Install Filament + admin auth** (~2 days)

- [ ] `composer require filament/filament`. Filament admin lives at `/admin/*` (Livewire + Alpine + Filament's Tailwind config, separate from the Inertia + React user app — doesn't share Stakly's pink/purple design).
- [ ] Admin user role via Spatie permissions (Spatie already installed).
- [ ] First admin user seeded via dedicated seeder.

**Phase 2 — Match resolution panel** (~2-3 days)

- [ ] Filament resource for `GameMatch` with filters by status (Disputed / ManualReview).
- [ ] Resolution view: shows full chat history inline (text + screenshots + link cards including any API-verified evidence cards from M8 Phase 4), match metadata, both players' linked-account info.
- [ ] Three action buttons: "Settle to {creator}", "Settle to {taker}", "Draw — refund both." Each calls the existing `SettleMatchAction` / `SettleDrawMatchAction` (idempotent, status-guarded — Phase 7 of M6 made this safe).
- [ ] Audit log: every admin resolution writes a row to a new `match_admin_resolutions` table (admin user + action + reason text + timestamp).

**Phase 3 — Switch dispute resolver** (~1-2 days)

- [ ] `OpenDisputeAction` no longer dispatches `MockGameApi` resolution. Sets match to `Disputed` and waits for admin.
- [ ] `ResolveMatchTimeoutAction`: cases that would have gone to `MockGameApi` now go to `Disputed` and surface in admin queue.
- [ ] `MockGameApi` retained for the existing test suite (tests still call it via service binding); production binding switches to a null-driver that no-ops or to the real Lichess adapter once M14 lands.
- [ ] Migration of the conceptual model: `Disputed` becomes "waiting for admin or API," `ManualReview` becomes the truly-irrecoverable terminal state (locked, money frozen pending refund-or-payout decision).

### Not in M12

- Real-time admin notifications (email / push when new dispute opens) — Filament's default polling is fine to start.
- Bulk resolution actions — one match at a time.
- Auto-resolution from M8 Phase 4 verified cards (admin still clicks to confirm). M14 adds the auto-path.

---

## M13 — Chat anti-abuse + moderation

Chat is the highest-abuse-surface feature on the platform. M13 builds the policing layer. Lands after M12 so admin tools exist to review flags + bans.

### Phases

**Phase 1 — Off-platform deal detection** (~2-3 days)

- [ ] Regex flags in `SendMessageAction`: TRC20 wallet addresses (`T[1-9A-HJ-NP-Za-km-z]{33}`), ERC20 addresses (`0x[a-fA-F0-9]{40}`), BTC addresses, common payment-method names ("revolut", "paypal", "venmo", "cashapp"), messenger handles ("telegram @", "discord:", "wickr"), trade-coordination keywords ("send me", "outside stakly", "off platform").
- [ ] Flagged messages still post (we don't want to tip the abuser), but write to a `flagged_messages` table with the trigger pattern.
- [ ] Filament dashboard widget: recent flags, click-through to chat context.

**Phase 2 — Rate limits + report-user button** (~1-2 days)

- [ ] Per-user chat rate limit (10 messages / 10s, already in M8 Phase 2 — Phase 2 here adds the soft-warn UI: "You're sending messages quickly — pause a moment").
- [ ] Per-match-day cap (200 messages/day/user/match) — prevents flooding.
- [ ] Report-user button on each message: opens a Filament-routed report record with the message ID, reporter, reason.

**Phase 3 — Blocked words + admin moderation tools** (~2 days)

- [ ] Configurable blocked words list (slurs, harassment terms). Filtered server-side in `SendMessageAction` — message is replaced with a placeholder + flagged for admin.
- [ ] Admin moderation panel: list flagged + reported users, ban/mute tools, history of actions per user.
- [ ] Mute = can't send messages for N hours (configurable). Ban = account suspended (manual unban only).

---

## M14 — Automated outcome adapters

When dispute volume justifies automation, swap from "every dispute → admin reviews" to "supported-game disputes → API auto-resolves, falls through to admin only on Unknown."

Builds on the per-game verification clients from M8 (chess.com / Lichess) and M15 (FACEIT / OpenDota / Riot): each adapter is the same HTTP client the chat link-card enrichment uses, just invoked from `ResolveDisputeAction` instead of only from `SendMessageAction` link-paste detection. Result confidence maps to `MatchOutcome` (Won/Lost/Drawn) and `GameApiConfidence` (Confirmed → auto-settle, Drawn → auto-refund, Unknown → fall to admin).

Trigger for full M14: M12 admin path is in use and dispute volume justifies the engineering. Pull forward sooner if a class of disputes shows it'd be obviously easier to auto-resolve.

**Slice A — Chess card arbitration** ✅ shipped 2026-05-22 (see `milestones_archived.md` for the implementation detail). `ChessGameApi` reads the most-recent auto-fetched card off chat — provider-agnostic, handles both Lichess and chess.com via the card's `provider` field — and returns the named winner with `Confirmed` confidence. Falls through to `MockGameApi` for race / no-card / unmappable. Pulled forward because the mock was paying the wrong player whenever a Lichess card disagreed. Full M14 (FACEIT, OpenDota, Riot, plus the no-admin-fallback policy) still lives behind the original trigger.

---

## M15 — Multi-game expansion

The multi-game realization of M8's per-provider adapter pattern. Where M8 builds chess (chess.com + Lichess), M15 brings every other game Stakly eventually supports — each game arriving as its own adapter following the same shape. Phases intentionally left open; scope and ordering to be decided with the lead dev before any work starts.

The trust pitch this milestone earns: **Stakly only takes stakes on matches played on the strongest available anti-cheat platform for that game.** Per-game catalog:

| Game     | Played on (anti-cheat)                            | Verification API                                      |
| -------- | ------------------------------------------------- | ----------------------------------------------------- |
| Chess    | chess.com or Lichess (native fair-play detection) | chess.com / Lichess (shipped in M8)                   |
| CS2      | FACEIT (FACEIT AC required)                       | FACEIT Data API                                       |
| Dota 2   | FACEIT Hub or Steam ranked                        | FACEIT (if played there) or OpenDota (Steam matches)  |
| Valorant | Ranked Valorant (Vanguard required)               | Riot Games API                                        |
| LoL      | Ranked LoL (Vanguard rolling out)                 | Riot Games API                                        |

Games without a usable anti-cheat platform AND a verification API (Fortnite, Apex, COD, FIFA, fighting games, mobile games) are out of scope until either changes — not because they're impossible, but because the trust pitch doesn't hold for them.

**Architectural composition** — mostly the existing shapes, with one extension called out below:

- `LinkedAccountProvider` enum gains `Faceit`, `Riot`, possibly `Steam` (for the OpenDota / Steam-ranked Dota 2 path).
- New `ProfileClient` implementations: `FaceitProfileClient`, `RiotProfileClient` (likely split per region), `SteamProfileClient`. Bio-code paste flow per provider where the platform exposes an editable profile field; OAuth where available (FACEIT and Riot both expose it — cleaner UX, requires app approval).
- `Game` enum gains `Cs2`, `Dota2`, `Valorant`, `Lol`.
- `listings.platform` (M8 Phase 5) expands its allowed values to include the new platforms.
- New game-result clients (`FaceitGameClient`, `OpenDotaGameClient`, `RiotGameClient`) mirror M8's `LichessGameClient` / `ChessComGameClient` — first wired into chat link-card enrichment, later into the auto-resolver via M14.
- New arbitration drivers per game family (sibling to `ChessGameApi`): `FaceitGameApi`, `RiotGameApi`, etc. Each reads its own provider's cards from chat.
- Webhooks where the provider supports them (FACEIT match-completed, Riot match-end) reduce polling cost when M14 lands.

**`match_provider_snapshots` table — extending to non-chess identifiers**:

The sibling snapshot table that landed in M8 Phase 4 is already in place. Today each row is `(match_id, side, provider, username)` — sufficient for chess.com + Lichess. When CS2 / Dota 2 / Valorant land, each will need additional identifier columns: Steam ID (uint64 — likely `string(20)`), Faceit player ID (uuid), Riot ID region (varchar 4), maybe MMR at snapshot for sandbag-detection surfaces.

Two ways to extend:

1. **Add nullable columns per identifier** as each game adapter ships (`steam_id`, `faceit_id`, `riot_region`, `mmr_at_snapshot`, etc.). Type-safe, query-friendly, but the table accumulates per-game-specific columns. Probably fine — Stakly's planned game catalog tops out at ~6-7 providers, so the column count stays manageable.
2. **Add a `provider_data` JSONB column** alongside `username` to carry the variable-shape per-provider extras. Schema stays narrow; per-provider parsing happens in the adapter. Cost is no DB-level uniqueness on those extras.

Decide per-adapter when the first non-chess one ships. The current `username` column is sized 64 to cover Riot IDs (`name#tag`, 16+5 chars) and Steam vanity URLs (up to 32) without a length migration.

**Cross-cutting smurf / sandbag defense** — FACEIT-class anti-cheat solves "no aimbots in CS2" but does not solve "experienced player hides behind a fresh account." That second threat is mitigated by Stakly's verified-rating system: bio-code linked accounts carry rating history; listings can require a minimum rating (`listings.skill_min`, already in the schema). Both layers need to be in place for the trust pitch to actually hold.

### Not in M15

- Auto-resolution from those adapters — that's M14, gated on volume.
- Filament admin moderation surfaces for the new game types — covered by M12 once it lands.
- Marketing / homepage copy for the anti-cheat trust pitch — separate from engineering scope; revisit alongside the existing marquee-copy cleanup.
- Aggregator-as-a-service (PandaScore / Bayes / Abios) — considered and parked. Reconsider only if the per-game maintenance burden gets painful and revenue can absorb the monthly cost.

---

## M9 — Chain Integration

**Paused — pending crypto-payment-gateway specialist.**

Real on-chain TRC20 USDT deposits and withdrawals. Provider, custody model, key management, gas strategy, and architecture all TBD — to be designed with a specialist developer joining the project later. Resume when that person is on board, or sooner if the user decides to own this themselves.

The platform layers below are deliberately provider-agnostic and won't change when chain integration lands:

- **Internal ledger** (`wallet_transactions`, M3.5) — append-only, idempotent via `reference_id`, source of truth for `users.usdt_balance`. Whatever provider is picked, it will call `Wallet::deposit` on confirmed deposits and `Wallet::withdraw` from a queued withdrawal worker.
- **Wallet UI** (M7) — overview, deposit page (shows `MockTronAddress`), withdraw form (validates fully, short-circuits on submit). Real per-user addresses replace the mocks; the withdraw POST handler swaps the short-circuit for a real worker dispatch.
- **`App\Support\MockTronAddress`** — continues to generate placeholder addresses until integration lands.

### Why per-user deposit addresses, not a shared hot wallet

The first attribution question — "if every user deposits to one shared address, how do we tell who deposited?" — only has good answers on chains with a native memo / destination-tag field (XRP, XLM, BNB Beacon Chain, Cosmos). **TRC20 USDT has no memo field**: the smart contract's `transfer(to, amount)` carries no user metadata, and most wallets don't surface a memo input for TRC20 anyway. Attempting to attribute deposits via amount-and-timing heuristics breaks on the edge cases that matter most (two simultaneous deposits, identical amounts, retries) — not safe for a money platform.

The standard answer is **per-user deposit addresses via an HD (Hierarchical Deterministic) wallet** (BIP32 / BIP44). One master seed, derive a child address per user at path `m/44'/195'/0'/0/<user_index>` (195 = Tron's BIP44 coin type), publish that derived address as the user's deposit address, watch the chain for incoming USDT to it, credit on confirmation via `Wallet::deposit($user, $amount, reference: $tx_hash)`. Address = identity by construction, no memo needed. The master seed is the protected secret; every address recovers deterministically from it.

This drops cleanly into the existing schema. `users.tron_address` is already the per-user column; `App\Support\MockTronAddress` is the placeholder address generator that gets replaced with real HD derivation; `Wallet::deposit(..., reference: $tx_hash)` is already idempotent on the tx hash. Nothing in the ledger or wallet UI has to change — only the address-generation source and the watcher process.

The TRC20-specific catch is that sweeping USDT off a per-user address later requires TRX on that address to pay for the transaction. Standard fixes: pre-fund each address with a small TRX amount on creation, or stake TRX from a master account and delegate "Energy" to deposit addresses via Tron's resource-rental mechanism. This is the gas strategy the specialist owns.

### Provider spectrum (buy vs build)

Three real architectural choices for the chain-facing layer, ordered from most control to least:

- **DIY HD wallet + TronGrid for chain reads.** Stakly owns the master seed, derives addresses in its own code, uses TronGrid (Tron's official node API, free at moderate volume) to watch addresses + fetch transactions, sweeps with its own worker. Maximum control, maximum security responsibility. What centralized exchanges build internally. Moralis fills the same chain-data role as TronGrid if multi-chain ever becomes relevant later.
- **Managed wallet primitives (Tatum, BitGo, Fireblocks, Coinbase Developer Platform).** They expose HD-wallet primitives via API; Stakly still controls the master through them. Tatum is approachable for a smaller platform; Fireblocks is enterprise pricing. Middle ground on control vs operational load.
- **Payment gateway (CryptoCloud, NOWPayments, CoinPayments).** They handle wallets, addresses, sweeps, and gas; Stakly calls their API to "create a deposit address for user X" and they notify on incoming funds. Fastest to launch. Trade: they hold real money briefly during the deposit-to-settlement window, per-transaction fees recur, and vendor trust becomes part of the security model.

The choice interacts directly with the key-storage question: DIY means *Stakly* answers "where does the master seed live" (KMS / HSM / vault / encrypted env). Managed providers either hold the seed (vendor-custody) or use key-share schemes (MPC). Payment gateways take that question off the table entirely.

Note: TronGrid, Moralis, and CryptoCloud are not the same kind of thing despite sometimes appearing in the same sentence. TronGrid + Moralis are **chain-data readers** (you'd combine them with your own HD-wallet code). CryptoCloud is a **payment gateway** (it replaces the HD-wallet code with their API). Picking one doesn't preclude the other — they sit at different layers.

### Live questions for the specialist

**Provider tier** (DIY / managed primitives / payment gateway) → **custody model** (BYO-key vs vendor-MPC vs full vendor custody) → **key storage** (KMS / HSM / vault / vendor-held) → **TRC20 gas strategy** (pre-funded TRX per address vs delegated Energy via TRX-staked master vs vendor-handled) → **testnet shakedown plan** → **mainnet flip checklist**.

---

## Open questions before real money flows

Engineering can keep moving on everything else; these are the topics worth surfacing before Stakly accepts a real deposit. Legal/jurisdiction is user-owned and out of engineering scope, but flagged here for completeness.

1. **Custody model** — TBD with the chain specialist (M9). Internal ledger is provider-agnostic so this decision doesn't gate ledger work.
2. **Jurisdiction** — where Stakly is registered + license path. User-owned.
3. **Chain + provider** — TRC20 (Tron USDT) is the planned starting chain; provider TBD with specialist.
4. **Key storage** — depends on custody model.
5. **Incident response plan** — hot-wallet compromise procedure, user notification template, insurance.
6. **Terms of Service + dispute resolution policy.** User-owned.
7. **KYC/AML** — required? threshold? provider? User-owned.

> Stakly is **crypto-end-to-end** (USDT in, USDT out, USDT-denominated platform revenue). No fiat-banking touchpoint for users.

### Small follow-ups noticed in passing

Tiny cleanups noticed during earlier work. Worth doing before non-developers touch the platform — not blocking any current slice.

- **Platform user credentials.** Seeder currently creates `platform@stakly.internal` via the default `UserFactory` (`Hash::make('password')` + `email_verified_at = now()`). Tighten: override seeder to use `Hash::make(bin2hex(random_bytes(32)))` and set `email_verified_at = null`. Add `Fortify::authenticateUsing(...)` hook in `FortifyServiceProvider` that rejects `is_platform = true` users — defense in depth.
- **Marquee copy.** `resources/js/layouts/site-layout.tsx` `defaultMarqueeItems` currently has `STAKLY30 30% off` promo and other aspirational claims. Replace with honest copy before any user-facing surface.
