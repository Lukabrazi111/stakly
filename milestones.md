# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M11, M8 all phases, M10, M12 all phases, M16 all phases, M14 Slice A). This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Active / upcoming:**

- **M13** — Chat anti-abuse + moderation [parked — design needs review]
- **M14** — Outcome pipeline hardening (reframed from "automated outcome adapters" — observability + reliability + coverage of the auto-fetch pipeline; Slice A shipped, Phase 1 next)
- **M18** — Profile expansion (Phases 1 + 2 shipped, Phase 3 Slice A + Slice B.1 shipped — completion rate chip on profile; **next: Phase 3 Slice B.2 — "more info" modal, then Slice B.3 — listing-row chip integration**, then M19 absorbs former Slice C + Phase 4)
- **M19** — Profile page redesign + management hub (Bybit-inspired IA + Stakly identity + game-agnostic from day one; folds former M18 Slice C + Phase 4)
- **M20** — Notifications (email infrastructure + per-event preferences UI; M20 owns the surface end-to-end)
- **M21** — Blacklist + safety (block users from listings + chat, with anti-evasion considerations)
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

## M18 — Profile expansion

Profile content expansion (avatar, bio, stats, trust signals). The full **visual + structural redesign** of the profile page is **M19** — M18 finishes the content, M19 makes it sing.

**Shipped:** Phase 1 + Phase 1 polish + Phase 2 (2026-05-25) · Phase 3 Slice A + Slice B.1 (2026-05-26). Detail in `milestones_archived.md`.

### Resume here

**Next: M18 Phase 3 Slice B.2 — "more info" modal.** Slice B.1 (backend + chip on profile) shipped 2026-05-26. The chip currently has a dead click; B.2 wires it to a Bybit-style modal showing the raw breakdown.

After B.2: **Slice B.3** — render the same chip on listing rows (`listing-card.tsx` / `listing-row.tsx`) with a batched seller-aggregation to avoid N+1 in the listings index query.

After B.3: **M19 starts** (profile page redesign + management hub). M18's remaining intent (former Slice C — repeat-pair widget + win-rate gradient bar — and former Phase 4 — privacy + sharing) absorbs into M19's new layout. Building them under the current layout would mean re-positioning when M19 lands.

### Demo seed data ready

`vendor/bin/sail artisan migrate:fresh --seed` populates ready-to-view profiles:
- `testuser` — 12 settled matches, 73% win rate (8W·1D·3L), $2,000 volume, both chess.com + Lichess linked.
- 3 marketplace users with 5–8 matches each (~14% to ~75% win rates).
- ~17 marketplace users stay match-empty so the empty-state UI is also testable.

Wallet ledger invariant holds across seed data — every match settles via the real `SettleMatchAction` / `SettleDrawMatchAction`.

### Active: Phase 3 Slice B (in-flight)

Pivoted from the original dispute/cancellation rate badges design — Bybit P2P model (composite completion rate + 3-free-cancellation buffer + listing-row display + "more info" modal). Pivot context + Slice B.1 implementation detail archived in `milestones_archived.md`. The architectural decision summarising the model lives at the top of this file ("Trust signal = single composite 'completion rate'…").

**Slice B.1 — Backend + chip on profile** ✅ shipped 2026-05-26 — detail in `milestones_archived.md`.

**Slice B.2 — "More info" modal**

- [ ] New `TrustInfoModal` component (`resources/js/components/profile/trust-info-modal.tsx`) — Bybit-style stats grid in a shadcn `Dialog` primitive.
- [ ] Shows: 30-day completion rate, lifetime completion rate, total matches per window, settled count per window, cancellation count (with "3-free per 30 days" note), dispute count (raw), member since, total volume staked.
- [ ] Wire `CompletionRateChip.onClick` to open modal.
- [ ] Tests for modal payload data presence + click-to-open behavior.
- [ ] Pint + suite green.

**Slice B.3 — Listing-row chip integration**

- [ ] Add `completion_rate_30d` + `settled_lifetime` to the seller block on `ListingResource`.
- [ ] Batch-load seller trust counts in `ListingController::index` / `show` / `mine` — single subquery or grouped aggregation joined back per `user_id`. Verify no N+1 with `database-query` MCP.
- [ ] Render `CompletionRateChip` in `listing-card.tsx` + `listing-row.tsx` next to seller name.
- [ ] Update skeleton variant (`listing-row-skeleton.tsx`) to include a chip-shaped placeholder.
- [ ] Tests: resource payload includes the trust data; query-count assertion across multiple listings (no N+1).
- [ ] Pint + suite green.

The thumbs-up / thumbs-down rating slot (Bybit's "👍 98 / 👎 0" in their "more info" modal) is intentionally out of scope here — see "Not in M18" for the player-review deferral. When a structured review system eventually lands, that signal slots into the modal alongside completion rate.

### Former Slice C + Phase 4 → moved to M19

The repeat-pair widget, win-rate gradient bar, and share button / OG meta tags originally scoped under M18 are absorbed into M19's redesign. They mount cleanly into the new layout (Trust strip + owner-only management section); building them under the current layout would mean re-positioning when M19 lands. See **M19 Phase 3** (repeat-pair + win-rate bar) and **M19 Phase 5** (share + OG meta). Privacy toggles were trimmed from M19 Phase 5 on 2026-05-27 — see M19 "Not in M19" for the reasoning.

### Not in M18

- **Player-to-player reviews / ratings after matches.** Deferred pending a coercion-resistant design. The straightforward "rate every match 1–5 stars" pattern invites coercion ("give me 5 stars or I'll dispute") on a P2P money platform. Trigger to revisit: a design that mitigates that pressure (anonymized aggregation, scoped to large-volume users, etc.). When reviews are eventually added, the Bybit-style thumbs-up/down summary (`👍 98 / 👎 0`) slots into the Slice B "more info" modal alongside completion rate.
- **Achievement badges / gamification.** Tempting but feels off-brand for a money platform. Revisit if usage data shows users want it.
- **Activity feed / follow graph.** Stakly isn't a social network; defer indefinitely.
- **Account deletion / data export.** Real concern but belongs in a separate compliance-focused milestone — user-owned area per the no-legal-concerns rule, so wait for direction.
- **Skill progression chart (rating over time).** Cool but expensive — would need to snapshot ratings into Stakly DB rather than fetch live. Defer to a future "stats deepening" slice.

---

## M19 — Profile page redesign + management hub

Today's `/users/{username}` is a vertical stack of cards on a `bg-card/60` translucent surface — functional but unstructured. M18 added avatars, bio, stats hero, link-out, and the completion rate chip; the page is now content-rich but the information architecture hasn't kept up. The pieces feel like a list, not a story.

M19 restructures the profile around three threads — **identity, trust, activity** — using a Bybit-inspired information architecture (header → trust overview → tabbed activity → owner-only management) adapted to Stakly's dark theme and gaming context. Same URL, single page, dual mode: visitors see the read-only public sections; owner sees those *plus* a clearly demarcated owner-only management block. Mode-switching keyed off `auth.user.id === profile.id`.

### Design intent

Bybit's IA is solid; their *visual treatment* is generic transactional. Stakly diverges on:

1. **Hero with personality, not a CRM header.** Large avatar with soft gradient glow ring, display-font name, bio inline if set, member-since pill, completion rate chip in the hero row as the headline trust signal. Verification chips with platform-tinted borders (chess.com brown, Lichess gray, future FACEIT orange, Riot red, Steam blue).
2. **Trust-first information weight.** Completion rate is the headline signal — visually larger / earlier than secondary stats (total matches, total volume). Not buried as one of five equal-weight cards.
3. **Match history stays visible by default.** Bybit tabs everything. For chess + future CS2 / Dota 2 / Valorant staking, recent match history is the highest-signal content — the trust story IS the matches. Keep inline as the default tab; alongside it (Open Listings, Reviews placeholder) tab as siblings.
4. **Owner-only section visually demarcated.** When viewer is the owner, a clearly separated "Your account" block appears below the public content with subtle `bg-secondary` shading. Visitors don't see it exists. No mode-switching mystery.
5. **Empty-state delight, not blank cards.** A user with 0 matches sees inviting CTAs ("Create your first listing →" / "Browse the marketplace →"), not "0 orders, 0%". Same data, warmer voice.
6. **Mobile-first.** Stats stack cleanly 2-up or 1-up on mobile. Hero adapts (avatar smaller, inline with name). Tabs scroll horizontally only when overflowing. Bybit's design assumes desktop.
7. **Card surface contrast — committed.** Profile cards switch from `bg-card/60` translucent to full-opacity `bg-card` with consistent borders so they read as raised surfaces on `bg-background`. System-wide token sweep happens separately (see Phase 1).

### Multi-game from day one

Stakly's path includes CS2 (FACEIT), Dota 2 (Steam / OpenDota), Valorant (Riot), LoL (Riot) — see M15. M19 designs every game-aware component to scale to multiple games per user from day one, even though chess is the only functional game today:

- **Linked Accounts section** scales to N providers per user. Chess player has chess.com + Lichess; an M15 multi-gamer adds FACEIT + Riot too. UI shows each linked account as a tinted chip with provider icon + linked-since.
- **Match history rows** render per-game shape via a `MatchRow` component dispatching by `match.listing.game`. Chess shows time-control + result; CS2 will show map + score; Dota will show hero + duration; etc. M19 ships only chess shapes (the only thing functional) but builds the component contract so M15 game adapters drop their renderers in without re-layout work.
- **Stats aggregations** stay game-agnostic at the headline level (completion rate doesn't care which game). Future per-game splits ("Chess: 12 matches 100% · CS2: 5 matches 80%") can be added later as a Slice without backend rework.
- **`Game` enum + `LinkedAccountProvider` enum** drive display (icons, colors, labels) so adding a game in M15 is a matter of enum case + asset path, not a profile redesign.

### Reference (chat-time)

User-shared Bybit screenshots saved to `images-examples/bybit-profile-redesign/`. Reference these for IA cues — **NOT visual fidelity**. Stakly diverges on style per "Design intent" above. Filenames roughly: `bybit-listing-row.png` (marketplace chip), `bybit-user-center.png` (full Data Overview page), `bybit-trust-modal.png` (more-info modal), `bybit-data-overview.png` (Data Overview detail), `bybit-user-center-full.png` (with sidebar).

### Phases

**Phase 1 — Visual restructure foundation** ✅

- [x] Profile-page cards switch to full `bg-card` (no transparency) + consistent border treatment so they visibly float above `bg-background`.
- [x] Page-level layout grid + spacing token (`gap-6` between sections).
- [x] Audited shadcn primitive overrides — Card default already opaque, no changes needed.
- [x] **Out of M19 Phase 1**: system-wide card token sweep stays out.

**Phase 2 — Hero redesign** ✅ (with deviations)

- [x] `ProfileHeader` revamp: larger avatar (`size-20 md:size-24` + `shadow-glow-sm` on hover), display-font name, member-since pill, bio prominent.
- [x] Verification chips with platform-tinted borders (chess.com brown, Lichess gray, FACEIT/Riot/Steam tones prepared in comments for M15).
- [x] Linked accounts as horizontally-scrolling chip strip on mobile.
- [x] Edit-profile button stays top-right (owner-only).
- ~~Completion rate chip moved into the hero row~~ → **removed** per user feedback (Data overview tile below already shows the same info; chip in hero felt redundant). `CompletionRateChip` component deleted.
- ~~Active / Inactive mode pill (owner-only) in hero~~ → **removed** per user feedback (mode toggle stays on `/listings/mine` via existing `ActiveModeToggle`; surfacing it in hero added clutter). `ActiveModePill` component deleted.

**Phase 3 — Trust strip + Data Overview** ✅

- [x] Trust strip below hero as dedicated row, not mixed into stats grid. New `TrustStrip` component.
- [x] Stats grid 2-up visitor / 3-up owner (kept existing structure).
- [x] **Repeat-pair widget** — "You've played N settled matches against this player" callout, gated to authenticated viewer on someone else's profile with 2+ shared matches. Backend: single COUNT query in `UserController::show` (JOIN to listings, both pair directions, settled-only).
- [x] **Win-rate gradient bar** — thin `bg-gradient-primary` width-proportional bar beneath W/D/L in the WinRateTile. Own-profile only (existing gate on `win_rate` payload).
- [x] Game-agnostic note: headline stats stay unified across games.

**Phase 4 — Tabbed activity section** ✅ (+ migrated `/listings/mine` to same primitive)

- [x] Tabbed section: Match History (default) | Open Listings | Reviews (placeholder).
- [x] **shadcn Tabs primitive installed** (`radix-ui` umbrella, no extra dep needed) + Stakly-skinned at the source: brand pink underline (`after:bg-primary`), `ring-2 ring-primary/25` focus ring, dropped dead `dark:` variants, two variants (`default` pill + `line` underline). Lives in `components/ui/tabs.tsx`.
- [x] **Open Listings tab** — existing `ListingsSection` reused (dropped its own h2 since the tab label replaces it).
- [x] **Reviews tab** — placeholder card with the coercion-resistant-design caveat.
- [x] Tab state syncs to `?tab=` via pushState + popstate listener (auth-modal-provider pattern). Pure client-side switching — data is already loaded, no re-fetch needed.
- [x] **MineTabs migrated to the same shadcn Tabs primitive** for cross-page consistency (was hand-rolled `<button role="tab">` before). Kept `router.get()` re-fetch (different data per tab) + added optimistic local state + `preserveState: true` so the underline transition stays smooth on click.
- ~~Mobile horizontal scroll on tabs~~ → **removed** (3 tabs fit any viewport; the overflow-x-auto caused a vertical-scroll artifact exposing the underline overshoot).
- ~~Per-game `MatchRow` dispatcher~~ → **deferred to M15** (chess is the only renderer needed today; the per-game-shape work belongs with the multi-game adapter milestone).

### Phase 4 extras (not originally in spec)

Shipped while Phase 4 was in flight, all related to discoverability + layout consistency:

- **"My profile" link enabled** in both `ProfileMenu` (avatar dropdown) and `MobileMenu` (drawer) — was disabled with a "Soon" badge. Both now link to `/users/{auth.user.username}`.
- **Profile link added to `PlayerSidebar`** as the top item (icon: lucide `User`). Active state matches when `url === /users/{auth.user.username}`.
- **Layout switch on profile**: own-profile view renders inside `PlayerHubLayout` (sidebar visible), visitor view stays in `SiteLayout` (no sidebar). Picked by `auth.user.id === profile.id`. Bybit-style integration.
- **Width normalization**: profile + `/wallet/history` migrated from `max-w-4xl` → `max-w-5xl` (matches `/listings/mine`, `/matches`, `/wallet`). Padding standardized to `px-4 py-10 md:px-6 md:py-14` across the player hub. `/wallet/deposit` + `/wallet/withdraw` kept at `max-w-lg` (narrow forms, intentional).
- **Seeder rework** (`MatchHistorySeeder`): every marketplace user now gets 4–6 matches via a skill-tier cycle (`index mod 4` → strong / balanced / balanced / casual) instead of just 4 named users. Visiting any random profile shows realistic stats (29–73% win rates) instead of accidental 100% from tiny opponent-only samples.

### Resume here

**Phase 5 trimmed scope (decided 2026-05-27):** ship only the share profile button + OG meta tags. Privacy toggles and Notifications/Blacklist placeholder tabs dropped — see Phase 5 below + "Not in M19" for the reasoning. After Phase 5: Phase 6 polish, then archive M19 + merge `feat/redesign` → `main`.

**Phase 5 — Share profile + OG meta**

Owner-only block below the public tabs, visible only when `auth.user.id === profile.id`. Visually demarcated with subtle `bg-secondary` shading + "Your account" heading. Single block — not sub-tabbed (the originally-planned Privacy / Notifications / Blacklist tabs are dropped, see below).

- [x] **Share profile button** — `ShareProfileButton` component (popover with QR + copy URL) inside an `OwnerAccountSection` shell. Uses existing `qrcode.react` dep + Sonner toast pattern. Visible only when `auth.user.id === user.id`.
- [x] **Open Graph meta tags** on `/users/{username}` — title / description / image / url / type + Twitter summary card variants. Rendered via Inertia `<Head>`; SSR (via `@inertiajs/vite`) puts them in the initial HTML for crawlers. `og:image` points at `apple-touch-icon.png` as a placeholder; swap to a 1200×630 branded card at `public/og-default.png` when one lands.
- [x] Tests: `UserShowTest` covers the `og` payload shape (title + type + absolute url + presence of description/image) and verifies image + url are absolute (relative paths break crawlers). Owner-section visibility is a pure FE conditional on `auth.user.id === user.id`; manual verification covers it.
- [x] Pint + suite green (765 tests / 3224 assertions).

**Trimmed from original spec (decided 2026-05-27):**

- Privacy toggles (hide completion rate, hide stake amounts) — anti-marketplace-trust on a money platform. Ship if/when real users ask AND the request is genuine privacy (not "I want to hide that I cancel a lot"). No schema, no backend, no UI today. See "Not in M19".
- Notifications + Blacklist placeholder tabs — placeholder UI advertising vaporware adds noise + a maintenance cost for zero value today. When M20 and M21 land they bring their own owner-side surface.

**Phase 6 — Polish: empty-state, mobile, animations, a11y**

- [x] **Empty-state copy** across the page (Slice A of Phase 6, 2026-05-27): `MatchHistorySection` + `ListingsSection` + `StatsCard` thread an `isOwnProfile` flag down from `users/show.tsx`. Owner empty states get inviting CTAs ("Browse the marketplace →" linking to `/listings`, "Create your first listing →" linking to `/listings/create`); visitor empty states stay descriptive. Stats card empty state collapses two repeating "No matches yet" dashed tiles into one card with a single CTA — small deviation from spec ("CTA inline" in each tile), but the previous two-tile shape repeated the same empty copy twice; one card reads cleaner.
- [ ] **Mobile-first audit** — hero collapses gracefully (avatar smaller, inline with name), stats stack 1-up, tabs work with thumb-scroll, repeat-pair widget hides on small screens if space is tight.
- [ ] **Interaction polish** — hover states on every interactive surface (chip, tab, button) using the existing `hover:shadow-glow-sm` pattern. Tab switch animation via `motion` (subtle slide / fade — don't fight Radix's defaults).
- [ ] **Accessibility audit** — keyboard nav across tabs, `aria-label`s on chips, focus rings consistent with the rest of the app, color contrast for the new card surface against text tokens (WCAG AA minimum on `text-foreground` against `bg-card`).
- [ ] Use `ui-ux-pro-max` skill for the polish + accessibility checklist.

### Not in M19

- **Notifications feature itself** — real email + per-event triggers land in M20 with their own owner-side UI; M19 no longer ships a placeholder tab (decided 2026-05-27, see Phase 5).
- **Blacklist feature itself** — real block-list infrastructure lands in M21 with its own UI; M19 no longer ships a placeholder tab.
- **Privacy toggles (hide completion rate, hide stake amounts)** — deferred 2026-05-27. Hiding trust signals fights the marketplace-trust pitch on a money platform. Ship only if a real user asks AND the request is genuine privacy (not concealing a poor cancel/dispute record). No schema, backend, or UI in M19.
- **Reviews feature** — deferred pending a coercion-resistant design. M19 ships only the public-section placeholder tab.
- **Per-game stat splits** ("chess: 12 matches 100% · CS2: 5 matches 80%") — composite rate stays unified for v1; splits can ship as a Slice later if usage data calls for it.
- **Profile editing forms (avatar / bio / linked accounts / security / password)** — those stay at `/settings/*`. M19's owner-only block is about share + OG meta, not duplicating the settings pages.
- **System-wide card surface token sweep** (every page's cards switching to full `bg-card`). Audit + apply as a separate polish slice after M19 validates the look.
- **Custom OG image per user** (dynamic server-rendered image with avatar + stats) — Phase 5 ships a static branded template; per-user dynamic OG is future polish.

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
