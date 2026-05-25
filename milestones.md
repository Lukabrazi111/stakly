# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M11, M8 all phases, M10, M12 all phases, M16 all phases, M14 Slice A). This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Active / upcoming:**

- **M17** — Admin operational tooling (Phase 1 widgets + Phase 2 in-panel notifications shipped; Phase 3 email deferred until needed)
- **M13** — Chat anti-abuse + moderation [parked — design needs review]
- **M14** — Outcome pipeline hardening (reframed from "automated outcome adapters" — observability + reliability + coverage of the auto-fetch pipeline; Slice A shipped, Phase 1 next)
- **M18** — Profile expansion + redesign (trust surface, Stakly-fit visuals, editable identity)
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

## M18 — Profile expansion + redesign

Today's `/users/{username}` page is functional but minimal — name, username, open listings, settled match history. Stakly is P2P with real money on the line, and a sparse profile doesn't help Alice decide whether Bob is safe to stake against. M18 turns the profile from a directory entry into a trust surface, restyles it to fit the Stakly visual system, and fills out the editing gaps so users have something worth showing.

The three threads — trust signals, visual redesign, editing surface — are interleaved, not sequential: every phase touches the layer that makes sense for it.

### Phases

**Phase 1 — Editable identity + avatar**

The smallest unit of "I'm a real person, not a bot." Today users have a name and a username derived at registration; nothing else surfaces.

- **Avatar upload** via Spatie Media Library (`profile-avatar` collection on `User`, web-safe MIME types, ~2 MB cap, automatic 512×512 + 128×128 thumbnail conversion mirroring the chat attachment setup).
- **Client-side crop modal** via `react-image-crop` (new npm dep, ~10 KB MIT-licensed, actively maintained — the standard React choice for circular avatar cropping). User picks a file → positions inside a circular preview → posts the cropped result. Confirmed at the moment of `npm install` per the project's library-discussion rule.
- **`/settings/profile`** extended to manage avatar + bio. Display name reuses the existing `users.name` column (already editable via the Fortify-backed profile update flow); the form surfaces it cleanly alongside the new fields. No separate `display_name` column.
- **Bio** is plain text with line breaks, escaped on render. Character cap ~500. Heavier anti-abuse (URL stripping, link sanitization) lives with M13 — Phase 1 just escapes and length-limits at the server boundary.
- **Default avatar** stays the existing initials-on-gradient — keeps the look consistent for users who don't upload.
- **`UserProfileResource`** exposes `avatar_url` and `avatar_thumb_url`. Avatars are public-by-nature so they live on the public disk (`storage/app/public/`) — separate from chat attachments which need authenticated streaming.
- **Public profile** renders the avatar in a circular frame with a magenta glow on hover. Sibling surfaces that currently show user initials (chat bubbles, `profile-listing-row`, `profile-match-row`, etc.) start using the real avatar when one is set, falling back to the initials component when not.

**Phase 2 — Profile redesign + stats hero**

The visual restyle. Mirror the design tokens already in use on the match page and home hero.

- **Hero section**: large circular avatar, display name in `font-display`, `@username` underneath, verification badges (Lichess / chess.com) next to the name with platform-tinted borders, `member since` pill, Active / Inactive mode pill.
- **Stats row (public view — what other users see)** directly below the hero — two pill-style cards (`bg-card/60 rounded-2xl border-border/60`), mirroring the `SettlementSummary` stat row layout:
  - **Total matches** (count of all settled matches). Activity signal — hard to exploit because it doesn't reveal skill.
  - **Total volume staked** — sum of this user's own stake across all their matches (not pot total). Reads as "Bob has committed $X to matches."
- **Stats row (own-profile view — additional cards visible only to the profile owner)**:
  - **Win rate** computed as `wins / (wins + losses)` — draws excluded from the denominator. W–D–L breakdown shown inline beneath the percentage (e.g. "65% · 12W–3L–2D").
- **Why win rate is owner-only**: showing it publicly creates a farming vector — strong players hunt low-win-rate opponents, concentrating losses on the weakest players (who already aren't winning). Skill matching is already handled at the listing layer (`skill_min` / `skill_max`), and Phase 3 surfaces the chess.com / Lichess rating from the linked account as the *public* skill signal. Stakly's own win rate adds zero trust value publicly and creates net-negative marketplace dynamics, so it stays private. Future Phase 4 opt-in can let users who explicitly want to brag flip their win rate visible.
- All stats are **all-time** by default. A "last 90 days" toggle can be added later if usage data suggests recent activity reads more meaningfully than full history.
- Dispute / cancellation stats live in Phase 3's trust signals row, NOT in this hero — those carry threshold logic and a different visual treatment.
- **Bio block** below the stats — soft `bg-muted/40` card with the user's free-text bio if set, omitted if not.
- Active listings and recent settled matches keep their existing data but get re-styled to match the new card shape.
- Stakly-skin every new shadcn primitive at `components/ui/*` per the project rule.

**Phase 3 — Trust signals**

The "should I stake against this user?" surface. Layered ON the Phase 2 hero — Phase 2 shows the neutral activity counts, Phase 3 adds the threshold-coloured behavior signals and the public skill signal.

- **Verified chess platform handle(s)** shown with the platform's logo + link out (so Alice can click through to verify Bob's chess.com / Lichess profile and check his actual rating / activity).
- **Live rating** from chess.com / Lichess displayed next to the linked handle (cached via the existing `ChessComProfileClient` / `LichessProfileClient` — short TTL ~1h, queued refresh). **This is the public skill signal** — Stakly's own win rate stays private per Phase 2.
- **Dispute rate badge** — color-coded: green ≤ 2%, amber 2–10%, red > 10%. Computed from `game_matches` where this user is a participant and status was Disputed or ManualReview at any point. Thresholds are calibrated to "no real data yet"; revisit once Stakly has post-launch volume to compare against.
- **Cancellation rate badge** — same shape, same threshold logic. Counts user-initiated cancellations (accepted by opponent), not auto-expiries.
- **"You've played N matches against this user" widget** — shown only when an authenticated viewer is looking at someone else's profile AND the pair has played 2 or more shared matches. Match 1 isn't a notable signal (every match is a first match for someone); 2+ marks a repeat interaction worth surfacing. Hidden entirely when the viewer is on their own profile or has no shared history with the target.
- **Win rate gradient bar (own-profile only)** — thin `bg-gradient-primary` width-proportional bar visualising the user's own win rate, rendered ONLY when the viewer IS the profile owner. Personal performance tracking without leaking the stat publicly.
- All Phase 3 stats are **all-time** by default — matches Phase 2's window.

**Phase 4 — Privacy + sharing**

Letting users opt out of trust transparency carries its own tradeoff: Stakly's marketplace works *because* dispute / cancellation behavior is public. So privacy toggles are narrow and biased toward keeping behavior signals visible.

- **Hide stake amounts on public match history**: per-user toggle. Match outcome (settled / cancelled / disputed) stays visible, just the dollar figure is redacted. Default: visible.
- **Hide dispute + cancellation rates**: per-user toggle, narrowly scoped to *only* the threshold-coloured trust badges from Phase 3. Total matches, total volume staked, member-since, and verified-account ratings stay visible regardless. Renders as "this user has chosen not to display their reputation rates" in place of the two badges — privacy is itself a (weaker) signal Alice can factor in. Default: visible.
- **No public-win-rate toggle is needed in v1** — win rate is private by default per Phase 2 (farming-risk mitigation). If post-launch users push for the ability to brag publicly, an opt-in "Show my Stakly performance publicly" toggle becomes the natural extension, default off.
- **Profile share button**: copy URL, QR code via existing `qrcode.react`.
- **Open Graph meta tags** on `/users/{username}` so links shared into Discord / Telegram / Twitter render a card with the avatar, name, and "Stakly P2P chess staking" tagline. Static branded template first; dynamic per-user OG image (rendered server-side from the profile data) is a future polish.

### Not in M18

- **Player-to-player reviews / ratings after matches.** Inviting users to rate each other on a P2P money platform invites coercion ("give me 5 stars or I'll dispute"). If a reputation layer becomes needed later, base it on objective data (dispute rate, payout reliability) rather than subjective reviews.
- **Achievement badges / gamification.** Tempting but feels off-brand for a money platform. Revisit if usage data shows users want it.
- **Activity feed / follow graph.** Stakly isn't a social network; defer indefinitely.
- **Account deletion / data export.** Real concern but belongs in a separate compliance-focused milestone — user-owned area per the no-legal-concerns rule, so wait for direction.
- **Skill progression chart (rating over time).** Cool but expensive — would need to snapshot ratings into Stakly DB rather than fetch live. Defer to a future "stats deepening" slice.

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
