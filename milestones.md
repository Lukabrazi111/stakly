# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M8 all phases, M10, M11, M12 all phases, M14 Slice A, M16 all phases, M17, M18, M19, M22, M23). This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Active / upcoming:**

- **M13** — Chat anti-abuse + moderation [parked — design needs review]
- **M14** — Outcome pipeline hardening (reframed from "automated outcome adapters" — observability + reliability + coverage of the auto-fetch pipeline; Slice A shipped, Phase 1 next)
- **M20** — Notifications (email infrastructure + per-event preferences UI; M20 owns the surface end-to-end)
- **M21** — Blacklist + safety (block users from listings + chat, with anti-evasion considerations)
- **M15** — Multi-game expansion (FACEIT, OpenDota, Riot adapters)

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
- **Trust signal = single composite "completion rate", not per-failure-mode rates** (M18 Phase 3 Slice B). One metric — "of your engaged matches, how many reached Settled?" — replaces separate dispute + cancellation rate badges. Positive framing (higher = better), forgiveness buffer for cooperative cancellation (3 free per rolling 30 days), no arbitrary threshold colors, no initiator-vs-defender ambiguity (a match that's disputed-then-settled is still a completion for both parties). Rolling 30-day headline + lifetime breakdown in the "more info" modal. Shown on both profile pages and listing rows so the signal travels with the user wherever their reputation might matter.

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

## M14 — Outcome pipeline hardening

Reframed from the original "automated outcome adapters" framing. That milestone made sense in a world where every match needed manual confirmation and the work was "build dispute auto-resolution." M16 changed the world — the auto-fetch pipeline now settles the bulk of matches without anyone touching them. The leverage isn't expanding dispute resolution; it's making the pipeline itself observable and resilient before launch. Stakly is custodial money code with an automated settlement engine — flying blind on its health is the biggest pre-launch risk.

The FACEIT / OpenDota / Riot adapter work and the "no-admin-fallback policy" piece move to M15, where the per-game adapter shape already lives.

**Slice A — Chess card arbitration** ✅ shipped 2026-05-22 (see `milestones_archived.md` for the implementation detail). `ChessGameApi` reads the most-recent auto-fetched card off chat — provider-agnostic, handles both Lichess and chess.com via the card's `provider` field — and returns the named winner with `Confirmed` confidence. Falls through to `MockGameApi` for race / no-card / unmappable. Originally pulled forward because the mock was paying the wrong player whenever a Lichess card disagreed; under the reframe, this is the foundation the rest of M14 builds on (the adapter has to be correct before the pipeline around it can be hardened).

### Phases

**Phase 1 — Per-match audit trail + admin visibility**

Today nobody can answer "why is this match in ManualReview?" without grepping logs. Every dispute investigation starts blind. Phase 1 captures the auto-fetch pipeline's reasoning as queryable data inside the app.

- New `match_auto_fetch_attempts` table (append-only, indexed on `match_id`). Columns: `match_id`, `provider` (lichess / chess_com), `outcome` (matched / no_match / ambiguous / error / skipped), `winner_username` nullable, `candidates_count`, `error_message` nullable, `latency_ms`, `created_at`.
- `DispatchAutoFetchAction` + both `AutoFetch*GameJob`s write one row per attempt — including the "we didn't even try" skips (snapshot missing, status not Pending, etc.) since those are equally important signals.
- `Log::info` / `Log::warning` mirrors of the same data so any future production log forwarding sees the same events.
- New Filament Infolist section on `GameMatchResource` View page: per-match audit timeline. Admin opens a disputed match → sees the full history of what the system tried, when, and what it found.
- New `PipelineHealth` Filament dashboard widget alongside `OpsOverview`: auto-fetch success rate (7d / 30d), avg time-to-settle, top "no_match" reasons.

**Phase 2 — Reliability**

The jobs currently catch provider errors and log a warning. Fine at one-match scale, dangerous at volume.

- Real retry policy on `AutoFetch*GameJob`s: exponential backoff for transient errors (5xx, timeouts, network), no retry for permanent errors (4xx other than rate-limit).
- Rate-limit awareness: read `Retry-After` / `X-RateLimit-Reset` headers from both Lichess and chess.com, back off accordingly.
- Circuit breaker per provider — if Lichess errors > N% for the last M minutes, pause auto-fetch for Lichess matches and surface in the admin widget. Resumes automatically when the error rate drops below threshold.
- Structured `ProviderError` exception hierarchy distinguishing transient vs permanent vs ambiguous.

**Phase 3 — Coverage**

The edge cases the pipeline currently silently skips. Each is a class of "match got stuck in Pending" that needs an explicit policy.

- Aborted games (currently filtered out by `AutoFetchLichessGameJob::filterCompleted`): decide policy — auto-refund both as a draw-like outcome, or stay in Pending until a real game lands? Probably depends on how often players abort intentionally vs accidentally.
- Multiple candidate games between the same pair (currently silently skipped — "wrong game is worse than no game"): smarter disambiguation. Closest to match creation time? Matches the listing's `time_control`? Lowest-rated game (typical "first game" heuristic)?
- Time-control mismatch: listing says blitz, players played bullet. Should the API result count? Today it does. Probably shouldn't.

**Phase 4 — Dispute fast-path**

The original M14 intent, slimmed down to chess only.

- When `OpenDisputeAction` fires on a Pending chess match, query the API immediately via `ResolveDisputeAction` instead of waiting for the next 5-min auto-fetch cron tick.
- `ChessGameApi` already supports this; wiring is `OpenDisputeAction` → `ResolveDisputeAction` (gated by a config flag, default off until Phase 1 metrics show it'd be safe).
- FACEIT / OpenDota / Riot piece stays in M15.

### Not in M14

- New game adapters (FACEIT, OpenDota, Riot, etc.) — those live in M15.
- Cross-provider Lichess↔chess.com disambiguation — a player would have to be linked on both AND play the same opponent on both within the same match window, which is implausible.
- Streaming WebSocket consumer redesign — Phase 4 of M16 shipped the Lichess admin OAuth stream; if it proves insufficient at volume, revisit then.

---

## M20 — Notifications (email + preferences)

Stakly currently sends almost no user-facing notifications (Fortify email-verification + password-reset only). M20 adds match-event emails + a per-user preferences surface — M20 owns the UI surface end-to-end (M19 dropped its placeholder tab on 2026-05-27).

### Phases

**Phase 1 — Notification infrastructure**

- [ ] Queue + driver setup (Postgres queue already exists via Sail; mail via Mailpit in dev, real SMTP later).
- [ ] Base `Mail` classes with Stakly branding (logo, dark-mode-friendly template, footer with unsubscribe / preferences link).
- [ ] Test infrastructure for email assertions (`Mail::fake()` patterns).

**Phase 2 — Triggers**

- [ ] Match taken (creator notified when someone takes their listing).
- [ ] Match settled (both players notified, with payout / loss outcome).
- [ ] Dispute opened (other player notified).
- [ ] Cancellation requested (other player notified).
- [ ] Cancellation accepted / rejected (requester notified).
- [ ] ManualReview flagged (both players notified — match is in admin queue).
- [ ] Deposit confirmed (when M9 lands — chain integration writes a real ledger entry).

**Phase 3 — Preferences UI**

- [ ] `notification_preferences` table (or JSON column on users) — per-event opt-in/out.
- [ ] Default: all event types ON.
- [ ] UI lives on a new `/settings/notifications` page (M19 dropped the placeholder tab on 2026-05-27; M20 owns the surface end-to-end). Toggle per event with sensible groupings.
- [ ] Always-on events: account-security (verification, password reset, login from new device). User cannot turn these off.

### Not in M20

- Push / SMS / in-app notifications — email is the v1 channel. Other channels can land as separate slices when scale demands.
- Per-user delivery cadence (daily digest, etc.) — start with per-event real-time; revisit if users push for digest mode.

---

## M21 — Blacklist + safety

Block specific users from interacting with you. Real safety feature with abuse-vector considerations.

### Design questions to resolve before building

1. **What does blocking actually do?** Lean: **all of the following** — a blocked user is fully invisible to you in both directions.
   - Block from taking your listings (they can't take + your listings hide from their marketplace view).
   - Block from sending you chat messages on matches you're already in.
   - Hide blocked users' listings from your marketplace view.
2. **Abuse vector — multi-account evasion.** A blocked user creates a new account, links the same chess.com handle, takes your listing anyway. Mitigations:
   - `linked_accounts` already enforces UNIQUE(provider, username) — same handle can't be re-linked elsewhere.
   - Block by `user_id` AND by snapshot of `linked_accounts.username` so blocking follows verified identity, not just the row.
   - Trade-off: if a player legitimately unlinks and someone else later claims the old handle, the new player inherits the blacklist. Edge case worth flagging in the block-flow UX.
3. **Abuse vector — vindictive block.** Bob loses to Alice, blocks Alice to dodge future matches. Hurts Bob (smaller opponent pool) more than Alice. Self-correcting; not really an abuse to mitigate.

### Phases

**Phase 1 — Schema + block list model**

- [ ] `blocks` table: `id`, `blocker_user_id` (restrict-delete), `blocked_user_id` (set-null), `blocked_provider` + `blocked_username` (identity snapshot for unlink-survival), `reason` nullable, `created_at`. UNIQUE(blocker, blocked).
- [ ] `Block` model with relations + a `blocksUserOrIdentity()` query helper.

**Phase 2 — Take-listing + chat-send guards**

- [ ] `TakeListingAction` checks: does the listing creator block this taker (by user_id OR by current linked-account username)? Abort with a 403 + neutral message ("This listing is no longer available") — don't leak the block.
- [ ] `SendMessageAction` checks: is the recipient blocking this sender? Soft error.
- [ ] Tests for both guards (block-by-id and block-by-username paths).

**Phase 3 — Marketplace + profile visibility**

- [ ] Marketplace index hides listings whose creator is on the viewer's blocklist (filtered out of the query).
- [ ] Blocked user's profile renders an explicit "You've blocked this user" banner instead of their content (with an unblock button).
- [ ] Blocker's profile renders normally to the blocked user (no visibility leak about being blocked — they just can't take listings / send messages).

**Phase 4 — Blacklist UI**

- [ ] List of blocked users lives on a new `/settings/blacklist` page (M19 dropped the placeholder tab on 2026-05-27; M21 owns the surface end-to-end).
- [ ] Block-action UI on the OTHER user's public profile (small menu when viewing as visitor): "Block this user". Optional reason field.
- [ ] Unblock from the list.
- [ ] Tests: block flow, unblock flow, marketplace filtering.

### Not in M21

- Reporting / admin escalation from blocks — abuse reporting lives with M13 (chat anti-abuse, parked).
- IP-based blocking — easy to evade, low value. User-identity blocking is sufficient.
- "Stakly Trust Score" / reputation tracking based on block counts — feels gameable; defer.

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

