# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M11, M8 all phases, M10, M12 all phases, M16 all phases, M14 Slice A). This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Active / upcoming:**

- **M17** — Admin operational tooling (Phase 1 widgets + Phase 2 in-panel notifications shipped; Phase 3 email deferred until needed)
- **M13** — Chat anti-abuse + moderation [parked — design needs review]
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

## M17 — Admin operational tooling

Stakly's admin panel (M12) ships with a default Filament Dashboard showing a placeholder `AccountWidget` + `FilamentInfoWidget` marketing card — useful for the panel install demo, useless for actually running ops. M17 replaces that with a real ops surface: at-a-glance health stats on the dashboard, and real-time bell-icon notifications when something needs admin attention.

Pulled forward ahead of M13 (chat anti-abuse) because M12 just shipped — admin currently has no signal that a dispute opened until they manually refresh `/admin/disputes`. Every minute a dispute sits unnoticed is a minute of player money locked in escrow with no progress.

### Phases

**Phase 1 — Dashboard widgets** ✅ shipped 2026-05-24

- [x] Replaced `AccountWidget` + `FilamentInfoWidget` placeholders with four ops widgets registered in `AdminPanelProvider::panel()`.
- [x] **Open disputes** (`App\Filament\Widgets\OpenDisputes`): counts matches with Disputed + ManualReview. Description shows oldest dispute age via `Carbon::diffForHumans`. Color tier: gray (queue clear), success (<1h), warning (1-6h), danger (6h+). Click-through to `/admin/disputes`.
- [x] **Matches today** (`MatchesToday`): 24h count + 7-day sparkline via `Stat::chart()`. Description compares today vs yesterday with up/down trend icon.
- [x] **Platform earnings (this month)** (`PlatformEarnings`): sums `WalletTransactionType::Fee` rows since `startOfMonth()`. Month-over-month delta in description. BCMath arithmetic (scale 2) for fee math.
- [x] **Active users (7d)** (`ActiveUsers`): distinct count via SQL `UNION` across listings/matches/messages from the last 7 days. Joins `users` and filters `is_platform = false`. Compares vs prior 7-day window.
- [x] Polling: `protected ?string $pollingInterval = '30s'` on all four widgets.
- [x] 10 feature tests in `tests/Feature/Admin/DashboardWidgetsTest.php` (Livewire-driven). Includes `staleListingAndMatch()` helper for ActiveUsers scenarios that need controlled-date fixtures (since factory chains auto-create users that would pollute the active count).

**Phase 2 — In-panel real-time notifications** ✅ shipped 2026-05-24

- [x] `notifications:table` migration patched to `jsonb` for the `data` column (Postgres requires JSONB for Filament's `data->>'format'` bell-icon query).
- [x] `->databaseNotifications()` + `->databaseNotificationsPolling('30s')` enabled in `AdminPanelProvider`. Bell icon + dropdown render in panel header.
- [x] `App\Actions\Admin\NotifyAdminsAction` — broadcasts a Filament `Notification` via `sendToDatabase($admins, isEventDispatched: true)` to every user with the `admin` Spatie role (excluding `is_platform = true`). Uses `whereHas('roles', ...)` instead of Spatie's `role()` scope so it no-ops gracefully when the admin role hasn't been seeded yet (vs throwing `RoleDoesNotExist`).
- [x] Notification carries title + body + color + `heroicon-o-exclamation-triangle` icon + an "Open match" action button linking to the dispute view.
- [x] Triggers wired:
    - [x] `OpenDisputeAction` → "Dispute opened — match #N" with creator vs taker + stake. Color `warning`. Fires AFTER the DB transaction commits (so notification doesn't fire on rolled-back disputes; broadcast events are also more reliable after-commit).
    - [x] `ResolveMatchTimeoutAction` → "Match auto-flagged — #N" with timeout context. Color `danger`. Same after-commit pattern.
- [x] Race-loss paths covered: repeat `openDispute` on already-Disputed match doesn't fire a duplicate (action returns false); `ResolveMatchTimeoutAction` returning `skipped` doesn't fire.
- [x] 7 feature tests in `tests/Feature/Admin/AdminNotificationsTest.php` covering role scoping, graceful no-op on missing role, notification persistence shape, lifecycle hook triggers (both success + race-loss paths).

**Polish iteration** ✅ shipped 2026-05-24

After live-testing Phase 1: the 4 per-stat widgets each rendered as their own full-width row (Filament's `StatsOverviewWidget` defaults `columnSpan = 'full'`), producing a tall vertical stack instead of a scorecard. Two follow-ups landed:

- [x] **Consolidated four widgets into one `OpsOverview`.** Filament's native StatsOverviewWidget renders multiple stats as a responsive grid; splitting them across separate widgets forced the vertical layout. Deleted `OpenDisputes`, `MatchesToday`, `PlatformEarnings`, `ActiveUsers` widget files. Per-stat queries moved to private methods on `OpsOverview` (one method per stat — `getStats()` reads as a recipe).
- [x] **2x2 grid via `getColumns() => 2` override.** For 4 stats, 2x2 reads cleaner than 4-in-a-row (same pattern as Stripe / Linear / Vercel scorecards). Collapses to single column on mobile via Filament's responsive default.
- [x] **Urgency-first stat ordering.** Top-left → top-right → bottom-left → bottom-right: Open disputes (action item) · Matches today (volume) · Earnings this month (revenue trend) · Active users (engagement trend). Top row = "right now" snapshot, bottom row = "trends".
- [x] **Pretty URL via `$slug = 'disputes'` on `GameMatchResource`.** Filament defaults the URL to `/admin/game-matches` from the model name; the slug override produces `/admin/disputes` to match the sidebar label.
- [x] **Switched URL builders from hardcoded paths to `GameMatchResource::getUrl()`.** `OpsOverview` widget + `NotifyAdminsAction` callers (via `OpenDisputeAction` + `ResolveMatchTimeoutAction`) now resolve dispute URLs through Filament's resource URL helper. Survives any future slug rename.

**Phase 3 (deferred) — Email backup**

In-panel notifications only reach admin when they have the panel open. Email is the natural complement for "I'm not in the panel right now" coverage, but it adds SMTP / deliverability / spam-filter complexity. Build it as a separate slice once real ops shows in-panel alone is insufficient.

- [ ] When triggered, mail dispatcher sends to every admin's email (alongside the in-panel notification, not instead of it).
- [ ] Configurable per-admin opt-out (some admins might want in-panel only, some want both).
- [ ] Trigger: dispute-opened initially. Timeout-triggered separately if needed.
- [ ] Optional: Slack webhook variant for teams that prefer Slack over email.

### Not in M17

- Slack/Discord webhooks (could be added with Phase 3 email if a team adopts Stakly).
- Custom per-admin notification preferences (more than one admin → revisit then).
- Aging-dispute reminders (cron-driven "this dispute is still open after 4h" pings) — adds a scheduled task surface; defer until proven needed.
- Notifications for other events (large stake match, user signup spike, etc.) — start with the two highest-value triggers, add others if ops asks for them.

---

## M13 — Chat anti-abuse + moderation

Chat is the highest-abuse-surface feature on the platform. M13 builds the policing layer. M12 shipped so admin tools now exist for reviewing flags + banning abusers.

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
- Filament admin moderation surfaces for the new game types — covered by M12 (shipped). New game types automatically appear in the existing `GameMatchResource` queue.
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

## Admin panel enhancements (backlog)

Ideas captured during M12 build + polish that aren't worth doing now but should land later as Stakly's ops surface grows. Not a milestone — pull individual items into a slice whenever they become valuable. Listed in roughly "most likely to need first" order.

**Dispute review workflow**

- **Request-evidence action.** A 4th resolve button that doesn't settle — posts a system message in chat ("Stakly support needs the chess.com game URL — please post within 48h or this will be settled as draw") with a configurable deadline. Lets admin gather more info without choosing a side. Useful when chat evidence is thin but the dispute isn't yet "irrecoverable." Phase 4 of M12 in spirit.
- **Inline admin notes** on a match. Private notes admins write to each other ("waiting on legal", "this user has 3 prior reports") — not visible to players, separate from the resolution audit log. Just a `match_admin_notes` table + a notes panel in the View page.
- **Admin-writes-in-chat** (full support panel). Admin posts as "Stakly Support" with a verified badge; players reply in normal chat. Decided in M12 Phase 2 design discussion to defer until real disputes show we need it — most disputes resolve fine on chat evidence already posted. Revisit if "I'd settle this but I need one more piece" becomes a recurring admin frustration.
- **Partial refund tool.** Currently the "draw" action refunds both stakes fully. Nuanced cases (one player clearly forfeited but other played in bad faith) might warrant 70/30 splits. Wallet primitives already support arbitrary amounts; just needs an action UI + audit shape.

**Admin productivity**

- **Audit log as its own Filament resource.** Move `match_admin_resolutions` from the inline HTML render on the View page to a proper `MatchAdminResolutionResource` at `/admin/resolutions`. Filterable by admin, action, date range. Useful for self-audit ("what did I resolve this week?") and team-audit ("who's settling to creator most often?").
- **Aging-dispute reminders** (cron-driven). Every N hours, fire a notification for any dispute still open beyond a threshold (e.g. 4h). Separate from the open-event notification — catches the "I missed it the first time" case. Needs a scheduled task + dedup to avoid spamming the same dispute every cron tick.

> Dashboard widgets + real-time admin notifications promoted to **M17** (above).

**Player context in dispute review**

- **User history sidebar** in Creator / Taker cards: "X disputes opened, Y won, Z lost", "wallet balance", "matches played in last 30d", "open reports against this user" (depends on M13). Gives admin "is this a habitual disputer?" context without leaving the page.
- **Quick links to game APIs.** Buttons in the chat history that take admin straight to chess.com / Lichess game search for the snapshotted usernames. Saves the copy-paste step when admin wants to manually verify a claim.
- **Provider snapshot view.** Show the snapshotted username from `match_provider_snapshots` (what they were linked as at match creation), not just current linked accounts. Matters when a player unlinked and relinked a different account post-match.
- **Listing context popover.** Quick view of the original listing (description, time control, language, region) for context — currently the admin has to leave the page to see the listing.

**Filtering + search**

- **Better search.** Currently the matches list is sortable but not searchable. Add search by player username, player email, or stake range. Existing filter only covers status.
- **Audit log CSV export.** Download `match_admin_resolutions` filtered by date range — useful for any future accounting or operational review.

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
