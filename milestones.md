# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M8 all phases, M10, M11, M12 all phases, M14 all phases, M16 all phases, M17, M18, M19, M22, M23, M24, M25, M26 all phases, M27 all phases, M29 all phases, M30 all phases, M31 all phases, M32 all phases, M34 all phases, M35 all phases, M36 all phases, M37 all phases). **Parked milestones** (work that isn't being picked up right now) also live in the archive — currently M13. This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Recently shipped** (this week):

- **M14** — Outcome pipeline hardening (all phases shipped 2026-05-22 → 2026-06-06). Slice A (chess card arbitration), Phase 1 (per-match audit trail + `PipelineHealth` widget), Phase 2 (reliability — `ProviderError` hierarchy, classified job retry policy, `Retry-After` / `X-RateLimit-Reset` parsing, per-provider circuit breaker + dashboard banner), Phase 3 (coverage — aborted-as-draw refund, multi-candidate picker, single-candidate time-control enforcement), Phase 4 Slice 4a (flag-gated `OpenDisputeAction` → `ResolveDisputeAction` fast-path). Slice 4b is a calendar checkpoint — flip the flag after ~2 weeks of stable Phase 1 telemetry.
- **M30** — Admin user management (all 6 phases, 2026-06-03 → 2026-06-04). UserResource, ban toggle + four enforcement guards, mandatory 2FA on admin role, on-every-login 2FA challenge via Fortify bridge, user-facing ban feedback (banner + bell + email), impersonation via `stechstudio/filament-impersonate` + Stakly audit/reason/expiry layer.
- **M31** — Admin wallet ledger (both phases, 2026-06-04). Read-only `WalletTransactionResource` with filters + sum summarizer, ViewWalletTransaction with infolist + reference-ID parser + sibling-entity lookup.
- **M32** — Admin listing management (both phases, 2026-06-04). Read-only `ListingResource` with status/platform/creator/stake/region/language filters, ViewListing with infolist (details + related match if Taken + wallet transactions via M31 parser) + force-cancel action routed through `CancelListingAction`. **Admin trio now complete — every state on the platform is investigable + actionable from `/admin` without Tinker.**
- **M26** — Filament-managed CMS pages + global SSR + full-site i18n (all 4 phases, 2026-05-29 → 2026-06-05). Admin-editable About / Privacy / Terms / Support, global Inertia SSR (homepage / listings / profiles / CMS first-byte HTML), full i18n framework (locale-prefix routing, `useT()` / `__()` bridge, `LocaleSwitcher`, Inertia-rendered 403/404/500/503 pages). `lang/en.json` at ~790 keys; `ka.json` / `ru.json` content backlog.
- **M15 Phase 4** — FACEIT outcome pipeline (all 4 slices, 2026-06-09 → 2026-06-11). `FaceitGameClient` + Data API wrapper, `AutoFetchFaceitGameJob` + `FaceitGameApi` adapter, `DispatchAutoFetchAction` wiring + e2e pipeline integration test, webhook receiver pre-answer scaffold (`/webhooks/faceit` + `VerifyFaceitWebhook` middleware + idempotency deferred to job-level). CS2 matches now settle end-to-end through the polling pipeline; webhook receiver collapses lag once a real envelope is captured (20-min FACEIT-portal task, no support reply required).
- **M15 Phase 5** — Dispute fast-path + telemetry (3 items, 2026-06-11). `OpenDisputeAction` capability gate (`Game::hasArbitrationDriver()` replaces `Game::Chess` hardcode) + `GameApi` composition chain (`FaceitGameApi → ChessGameApi → MockGameApi`) so FACEIT disputes actually settle via the fast-path. `PipelineHealth` widget per-provider breakdown so FACEIT activity is visible alongside chess. Per-provider circuit-breaker config — thresholds tunable per provider via `services.{provider}.circuit_breaker.*` with M14 defaults preserved. **M15 functionally complete for gameplay** — only Slice 4 follow-ups (event-id field + IP allowlist) remain (M34 settle gate landed via M34 P4 on 2026-06-13).
- **M35** — Outbound third-party API rate-limit audit (all 3 phases + skipped Phase 4, 2026-06-11). Self-throttle (Laravel `RateLimiter::for(...)` + `RateLimited` job middleware) on `AutoFetchChessComGameJob` (30/min), `AutoFetchLichessGameJob` (60/min), `AutoFetchFaceitGameJob` (30/min); profile clients (`ChessComProfileClient` / `LichessProfileClient` / `FaceitProfileClient`) brought up to game-client parity (429 → `RateLimitedError` + `Retry-After` parsing, breaker integration). All caps env-tunable via `{PROVIDER}_REQUESTS_PER_MINUTE`. Goal: zero production 429-driven settlement freezes.
- **M34 Phase 3.1 + Phase 4** — Team-play UI unification + FACEIT 5v5 verification (2026-06-12 → 2026-06-13). **P3.1** collapsed `/lobbies/{id}` into the canonical `/listings/{id}` across 4 slices (A: listing-card adapter; A.2: rows↔grid view with cookie-backed persistence + ready-check countdown banner; B.1: legacy URL 301-redirect + policy relaxation; B.2: FACEIT-grade center column with Money HERO / Skill matchup / Trust signals / state-dependent Coordination panel + 3-col layout), then 3 polish rounds (CS2 5v5-only enum lock + slot-card respec + floating chat → action row in Money block + chat FAB participant-gating + Matches/Win-rate/Completion-30d stats → traffic-light Ready/Leave tones + recruiting Coordination card drop + homepage "Ending soon" unified with `ListingGridCard`). **P4** generalised the 1v1 FACEIT pipeline to N-vs-N: `snapshotProviderUserIds` helper, strict `isOpposingTeamRosters` (slot 0 + slot 1 fallback per team), `SettleTeamMatchAction` fan-out (5 payouts + 1 fee + slot-0 remainder + in-action conservation assertion), defensive winner-roster check in `SettleFromCardAction`, additive card payload (`winning_team` + `winner_user_ids` alongside legacy 1v1 fields), +9 5v5 tests. Locked CS2 5v5 lobbies now settle end-to-end. **CS2 production launch (M15) now functionally unblocked**; only optional P5 (2v2 Wingman) remains. Total across M34 P0–P4: 1330 → 1450 tests (+120).
- **M34 Phase 3.2** — Real-time lobby via Reverb + chat-subscription fix (2026-06-13). Replaced the 5s `router.reload` polling on the team-play lobby with a proper broadcast: new `private-lobby.{id}` channel (`LobbyChannel`, auth mirrors `ListingPolicy::viewLobby`), new `LobbyUpdated` event (`ShouldBroadcast` + `ShouldDispatchAfterCommit`, minimal payload — broadcast is a trigger, server stays the source of truth for derived payload), dispatched from every roster/state-mutating Lobby action (Join / Leave / ToggleReady / Kick / both timeout actions) only on success sentinels. Frontend `<LobbyRealtimeSync>` calls `useEcho` + `router.reload({ only: ['lobby'] })`, mounted only while auth + lobby live so terminal states tear down the WebSocket cleanly. **+18 Pest tests** pinning channel auth + every dispatch / no-dispatch site (1450 → 1468). **Also fixed:** match-chat channel was 403'ing non-participants viewing the lobby page because `useMatchChat` ran unconditionally in `TeamPlayLobbyView`; extracted into `<LobbyChatPanel>` sub-component mounted only when `lobby.viewer?.is_participant`, so non-participants never subscribe to `private-match.{id}`. **CLAUDE.md + memory** picked up a broader rule from this work: *choose tech on merit, not speed-to-ship* — polling was the wrong call because Reverb infra already existed; bias toward the better tool when the wiring is already there.
- **M34 Phase 5 + chat-leak follow-up** — CS2 create-form team-play extension, 2v2 Wingman enable, private-listing post-create UX, and the chat-content leak fix (2026-06-13). **Chat-leak:** `ListingController::showTeamPlay` now gates the `messages.data` payload on viewer being a live participant (reuses `$userIds` already computed for trust/stats — no extra query). Strangers, guests, and kicked users get `collect()`. +5 Pest cases pinning owner / live-non-owner / authed-stranger / unauthed-visitor / kicked-former-participant. **P5 Slice 1:** create-form props ship `allowed_team_sizes` per game (chess `[1]` / CS2 `[2, 5]` / Dota2 `[1]`) sourced from `Game::allowedTeamSizes()`. **P5 Slice 2:** `pages/listings/create.tsx` gained `team_size` / `creator_side` / `is_public` to `useForm`; new Format / Your side / Visibility sections (Stakly-skinned `ToggleGroup` segmented controls, Format hides when `allowed_team_sizes.length === 1`); submit-button label adapts (`"Open lobby"` for team-play, `"Create listing"` for 1v1). **P5 Slice 3:** `Game::Cs2->allowedTeamSizes()` flipped `[5]` → `[2, 5]` — Wingman is creatable through the UI; seeder grew to 29 main users + a 2v2 Wingman lobby; +2 Pest tests for the 2v2 e2e via real actions through `SettleTeamMatchAction` with conservation invariant. **P5 Slice 4:** team-play creators now redirect to the lobby (`/listings/{id}`) instead of `/listings/mine` since they're auto-soft-joined into slot 0; new owner-only `LobbyInviteBanner` (`border-glow` + Copy button via `useClipboard`) surfaces the invite URL above the 3-col grid when the listing is private and state is recruiting / ready_checking. **+9 Pest tests overall** (1468 → 1477). All CI gates clean (Pint / Prettier / ESLint / TypeScript). **M34 P0–P5 (+ P3.1 + P3.2) shipped end-to-end.**
- **M34 Phase 6** — Team-aware dispute + cancellation backend + UI + admin (all 6 slices, 2026-06-14). Slice A team-aware `GameMatchPolicy::isParticipant` + `MatchChannel::join` reading live `LobbyParticipant` roster, plus same-team-accept block on `canRespondToCancellation`. Slice B `AcceptCancellationAction` refund fan-out across the full team roster (per-user idempotency ref `cancel-refund:{match}:{user}`). Slice C new `App\Services\MatchParticipants` primitive + notification fan-out across `OpenDisputeAction` / `RequestCancellationAction` / `AcceptCancellationAction` (`RejectCancellationAction` unchanged per design). Slice D `GameMatchResource` ships team rosters + `winning_team` (gated via `mergeWhen`, 1v1 shape preserved verbatim) + `listing.team_size`. Slice E team-aware `match/show.tsx` branch (`TeamMatchView` + `TeamRosters` + `TeamSettlementSummary`), team-aware cancellation banners (same-team viewers see passive "your team-mate requested" variant), `RequestCancellationButton` / `OpenDisputeButton` `teamSize` prop for copy swap, lobby `CoordinationPanel` gained "View match page →" CTA post-lock. Slice F `SettleDrawMatchAction` + `AdminSettleToWinnerAction` + `AdminSettleDrawAction` made team-aware (Filament UI shows "Settle to Team A / Team B / Refund all" on team matches; admin picks a representative winner user, action derives the full winning roster from `LobbyParticipant`), plus end-to-end dispute-resolution test asserting ledger-conservation invariant. **+42 Pest tests** (1477 → 1519). CS2 5v5 + 2v2 Wingman matches now fully support the full lifecycle: lock → Pending → cancel/dispute → admin settle → payouts fan out, with all 10 (or 4) refunds / payouts / notifications correct.
- **i18n hotfix — Livewire 3 hashed-prefix bypass** (2026-06-14). `RedirectUnprefixedLocale::shouldSkip()` exempt list had `'livewire'` (exact first-segment match) which didn't catch Livewire 3's cache-busted asset prefix `livewire-{hash}/livewire.js`. Result: Filament admin login page tried to load Livewire JS, got 301'd to `/en/livewire-{hash}/...` → 404 → admin login form non-interactive (password show/hide broken, form submit no-ops). Fix: added `str_starts_with($firstSegment, 'livewire-')` prefix check. +2 Pest tests guarding the bypass (real hash returns 200; bogus hash returns non-301) so a future Livewire version bump doesn't silently re-break admin login. Full suite **1519 → 1521**.
- **M36** — Active-matches quick access (all 3 phases, 2026-06-23). Sidebar **Matches** live count badge + "In Progress / All" toggle on `/matches` (Bybit-style, defaults to In Progress), team-aware `scopeForRosterParticipant` + `active_matches_count` shared prop, live badge refresh off the existing notification broadcasts. Detail in archive.
- **M37** — One active match per game (all 3 phases, 2026-06-23). Closed the chess-concurrency hole: per-game race-safe guard on `TakeListingAction` (taker + owner), busy-creator listings hidden from board/home/visitor-profile, Take button greyed when the viewer's already in that game. Policy: one chess + one CS2 at once is fine, two of the same game isn't. Full suite 1554. Detail in archive.

**Active / upcoming:**

- **M38 — Redis for queue, cache & sessions (launch-readiness)** — **P1 + P2 shipped 2026-06-23; P3 (Horizon) next.** Moved the hot infra (queue, cache, sessions) off the Postgres `database` driver onto Redis (distinct logical DBs) — faster settlement pipeline isolated from the money ledger, atomic rate-limiting, with a `redis → array` failover cache store so a Redis blip degrades gracefully instead of 500ing. P3 adds Horizon-grade queue observability; P4 is prod wiring (managed Redis + eviction policy). Reverb scaling stays out (single-node). Detail below.
- **M34 P3.1 follow-ups** — deferred lobby-page polish scoped out of M34 (each needs its own data plumbing; the lobby shipped cleanly without them). Slot into a follow-up phase on user demand or when the data lands for another reason.
    - **Country flags per player** — a small flag next to each roster name. Source: FACEIT profile `country` (ISO-3166 two-letter), pulled during `FaceitProfileClient::fetch()` and persisted on a new `linked_accounts.country` column; render via a flag-emoji helper or SVG pack. Cheap, but needs a migration + a backfill of existing linked accounts.
    - **Per-player recent W/L form** (`W L W W L` chips on each slot card) — last 5 FACEIT matches via `/players/{guid}/history?game=cs2&limit=5`. Expensive at scale (10 players × per-page-load = 10 FACEIT Data API calls); needs a per-player cache (~1h TTL) + an off-band refresher job so the lobby page never blocks on FACEIT. Momentum / tilt signal.

- **M15** — Multi-game expansion. First cut is CS2 via FACEIT; Dota 2 / Riot adapters extend the same pattern once that ships. **All 6 phases shipped** (Phase 0 research → Phase 5 dispute fast-path + telemetry). Two small Slice 4 follow-ups remain when FACEIT data arrives (event-id field name + IP allowlist) — neither is blocking. **CS2 production launch unblocked** as of M34 P4 (2026-06-13).
- **M28** — Designed Fees page. Hand-coded marketing surface — transparent 5–10% commission disclosure, interactive calculator, replaces footer Support link in header nav. Pre-launch trust signal; design-driven (`ui-ux-pro-max` skill).
- **M20** — Email notifications. **Spec materially shrunk**: M27 P5 already shipped the in-app preferences UI + `notification_preferences` table + 9 `PlayerNotification` classes; M30 P4 wired the `mail` channel for ban notifications. What's left = branded HTML email templates, flip `'mail'` into `via()` on the remaining PlayerNotification subclasses, production SMTP config. Realistically 2–3 days.
- **M21** — Blacklist + safety. Block users from listings + chat, with anti-evasion considerations. Has open design questions (block semantics + multi-account evasion) — needs alignment before coding.
- **M33** — Listing time-control contract. Make Stakly's accepted time controls (blitz / rapid / classical) explicit in the listing-creation form, surface `time_control_mismatch` as a player-facing banner on stuck matches, and optionally re-enable Slice 3d strictness behind a per-listing opt-in. Reverted from M14 on 2026-06-06 — friction (legitimate correspondence / bullet games rejected silently) outweighed the small sandbag attack surface at this stage. Revisit when launch scale or a real abuse incident makes it relevant.
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

## M20 — Notifications (email + preferences)

Stakly currently sends almost no user-facing notifications (Fortify email-verification + password-reset only). M20 adds match-event emails + a per-user preferences surface — M20 owns the UI surface end-to-end (M19 dropped its placeholder tab on 2026-05-27).

### Phases

**Phase 1 — Notification infrastructure**

- [x] Queue + driver setup (Postgres queue already exists via Sail; mail via Mailpit in dev, real SMTP later).
- [x] Base `Mail` classes with Stakly branding (logo, dark-mode-friendly template, footer with unsubscribe / preferences link).
- [x] Test infrastructure for email assertions (`Mail::fake()` patterns).

**Phase 2 — Triggers**

- [x] Match taken (creator notified when someone takes their listing).
- [x] Match settled (both players notified, with payout / loss outcome).
- [x] Dispute opened (other player notified).
- [x] Cancellation requested (other player notified).
- [x] Cancellation accepted / rejected (requester notified).
- [x] ManualReview flagged (both players notified — match is in admin queue).
- [x] Deposit confirmed (when M9 lands — chain integration writes a real ledger entry).

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

- [x] `blocks` table: `id`, `blocker_user_id` (restrict-delete), `blocked_user_id` (set-null), `blocked_provider` + `blocked_username` (identity snapshot for unlink-survival), `reason` nullable, `created_at`. UNIQUE(blocker, blocked).
- [x] `Block` model with relations + a `blocksUserOrIdentity()` query helper.

**Phase 2 — Take-listing + chat-send guards**

- [x] `TakeListingAction` checks: does the listing creator block this taker (by user_id OR by current linked-account username)? Abort with a 403 + neutral message ("This listing is no longer available") — don't leak the block.
- [x] `SendMessageAction` checks: is the recipient blocking this sender? Soft error.
- [x] Tests for both guards (block-by-id and block-by-username paths).

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

**Per-game listings filter UI** — `/listings` has a per-game tab strip (`ListingsGameTabs`) above the filter bar. **CS2 + Dota 2 ship as M15 placeholders** (`App\Enums\Game::Cs2`, `Game::Dota2`; `LinkedAccountProvider::Faceit`, `Steam` with stub `displayName()` + `usernamePattern()`; catalog status flipped to Active; `ListingFactory::forGame()` + `ListingSeeder` distribute dev-seed listings across all 3 Active games). Browse + per-game filter UX is testable end-to-end today; **Create flow is still chess-only** — no FACEIT/Steam ProfileClient or bio-code verification yet. Chess-specific filter widgets (the `Blitz / Rapid / Classical` time-control toggle group) are gated by `gameSupports(filters.game, 'time_control')` and live inline in `listing-filters-bar.tsx` / `listing-filters.tsx`. **When the real adapters land, extract chess-specific filter UI into a sibling component (`ChessFormatFilter`, `ChessSkillRangeFilter`) and create matching per-game siblings (`Cs2FormatFilter`, `DotaSkillRangeFilter`, etc.).** The form just branches: `{filters.game === 'chess' && <ChessFormatFilter />}{filters.game === 'cs2' && <Cs2FormatFilter />}`. No premature generic-filter-interface abstraction — the shape of "what's variable between games" emerges from the second game, not the first. The `time_control` URL filter resets on game-switch (`switchGame()` in `pages/listings/index.tsx`); when the second adapter lands, expand the reset set to drop all game-specific filters during the switch. Skill range is held over because cross-game skill-metric semantics (Elo vs MMR vs Faceit ELO) are theoretical until real M15 wiring — design that filter UX alongside the actual game. Frontend type discipline already in place: `ChessProvider` (narrow, `chess_com | lichess`) for verified linked-account contexts vs `ListingPlatform` (wide, `ChessProvider | faceit | steam`) for the listing's actual platform field — when M15 wires real FACEIT/Steam linking, the narrow contexts widen too.

**CS2 production launch is gated on M34 (Team play + lobbies)** — FACEIT competitive CS2 is 5v5 with no native 1v1 ranked mode. M15 P4 polling pipeline is 5v5-aware (`FaceitMatchResult` parses full rosters) and validates via fixture-roster tests independent of M34. But the create-form is still 1v1-shaped, so CS2 listings can't be taken-and-settled end-to-end in production until M34 ships the lobby + multi-player stake collection.

### Phases

**All phases shipped 2026-06-07 → 2026-06-11.** Headlines:

- **Phase 0** — FACEIT API research (Socialite go, server-side API key required for Data API, PKCE-mandatory OAuth, per-player AC gate). Findings preserved in the "Phase 0 — research findings" subsection below.
- **Phase 1** — Schema extension (`linked_accounts.provider_user_id` + `skill_rating`; `match_provider_snapshots.provider_user_id` + `skill_rating_snapshot`).
- **Phase 2** — FACEIT OAuth link flow (`FaceitLinkController` + `socialiteproviders/faceit` + local `FaceitProvider` PKCE patch).
- **Phase 3** — Per-game create form + listing creation gating (`isVerifiedOn()` helper, per-game validation, dropdown game picker, `GameChip` across listing surfaces, inline link-account gate, default-game logic, `ChessFormatFilter` / `Cs2SkillRangeFilter` siblings).
- **Phase 4** — FACEIT outcome pipeline (`FaceitGameClient` + DTO; `AutoFetchFaceitGameJob` + `FaceitGameApi` adapter; `DispatchAutoFetchAction` wiring + end-to-end pipeline test; `POST /webhooks/faceit` receiver pre-answer scaffold).
- **Phase 5** — Dispute fast-path + telemetry (`Game::hasArbitrationDriver()` capability gate; `GameApi` composition chain `FaceitGameApi → ChessGameApi → MockGameApi`; `PipelineHealth` per-provider breakdown; per-provider `ProviderCircuitBreaker` config). End-to-end dev test already covered by `tests/Feature/Jobs/AutoFetchFaceitPipelineTest.php` from Slice 3.

### Still-active follow-ups

**Slice 4 follow-ups — 20-minute empirical capture, no support reply required.** Set up a FACEIT dev app, point a test webhook at ngrok / webhook.site, fire one event from the portal, capture the real envelope, respond 500 once to observe retry behavior. From that capture:

- Lock the event-id field name in `FaceitWebhookController::extractPlayerGuids()` if it differs from our defensive scan; decide whether a `faceit_webhook_events` idempotency table is worth adding.
- Confirm webhook retry policy + align 5xx response semantics; decide if a dead-letter table is needed.

**Deferred polish (post-launch):**

- Webhook egress IPs → IP-allowlist middleware. Either ask FACEIT support OR observe egress in production logs. Small follow-up commit: populate `services.faceit.webhook_egress_ips` + add allowlist check to `VerifyFaceitWebhook`. Support questions doc ([`docs/faceit-support-questions.md`](docs/faceit-support-questions.md)) is the parallel path.

### Phase 0 — research findings (2026-06-07)

Done via Context7 (Laravel Socialite) + FACEIT developer docs + community sources. Headlines + only the bits that change Phase 1+ scope or need human follow-up.

**Go on Socialite, not manual OAuth2.** `socialiteproviders/faceit` (Packagist v4.2.0, last tagged Sep 2022 but functional, ~2k installs) implements FACEIT's authorization-code flow correctly. Setup: `composer require socialiteproviders/faceit`, register an `Event::listen` for `SocialiteWasCalled` in `AppServiceProvider::boot()`, add a `'faceit'` block to `config/services.php`. The controller layer mirrors the existing chess link flow. Fork into a Stakly-controlled repo if upstream stalls further.

**OAuth endpoints + token model**:

- Authorize: `https://accounts.faceit.com/` · Token: `https://api.faceit.com/auth/v1/oauth/token` · Userinfo: `https://api.faceit.com/auth/v1/resources/userinfo`.
- Scopes: `openid profile email membership`. No per-resource scope for Data API access — and OAuth user tokens cannot read the Data API regardless of scope (403). The server-side API key from App Studio → API KEYS is required for any Data API read.
- Access token: 24h. Refresh token: doesn't expire but **rotates** — re-store every refresh.
- PKCE: **REQUIRED in practice**. FACEIT's App Studio (2026) only issues confidential PKCE clients — token exchange needs BOTH `code_verifier` in the body AND `Authorization: Basic <client_id:client_secret>` header (stacked defenses). The OpenID config doesn't advertise `code_challenge_methods_supported` but the App Studio behaviour shows PKCE is the only path. Stakly's local `App\Services\Provider\FaceitProvider` extends the upstream package and re-adds `code_verifier` to the token body (upstream drops it).
- Userinfo payload: `guid` (stable player UUID — becomes `linked_accounts.provider_user_id`), `nickname` (becomes `linked_accounts.username`), `email` (nullable), `picture` (nullable).

**Data API endpoints we'll use** (host: `open.faceit.com`):

- `GET /data/v4/matches/{match_id}` — single match.
- `GET /data/v4/players/{player_id}/matches?game=cs2&type=past&limit=...` — list past matches.
- `GET /data/v4/players/{player_id}` — player profile including `games.cs2.faceit_elo` (raw int) + `games.cs2.skill_level` (1–10 ladder) + `games.cs2.game_player_id` + `games.cs2.region`.
- Auth: `Authorization: Bearer {server_side_api_key}` only. OAuth user tokens return 403 against the Data API regardless of scope.
- **Winner in one call**: `results.winner ∈ {"faction1","faction2"}` + `teams.{faction1,faction2}.roster[]` with `player_id`, `nickname`, and `anticheat_required` per player.
- Per-player ELO at match time is NOT exposed (only current ELO via `/players/{player_id}`); for sandbag detection we have to snapshot at match creation, not derive from history.

**Webhooks**:

- Event for settlement: `match_status_finished`. Payload includes the full match object (id, region, game, teams, results.winner, results.score) — no follow-up call needed just to identify the winner.
- Security: **no HMAC**. FACEIT supports only a static shared secret in a custom header or query string. Treat webhook as a notification, not proof — every settle-trigger re-fetches via `GET /matches/{match_id}` before releasing escrow.
- Registration: developer-portal UI only. Each subscription = (event, URL, auth header/query).
- Delivery: at-least-once with retries; envelope carries `event_id` for idempotency.

**Adjustment to "Current direction"**:

The pre-research call was "accept any FACEIT match (Competitive + Hub)." The actual implementation gate should be **`anticheat_required === true` for every player on both rosters**, not a check on `competition_type` (matchmaking vs hub). FACEIT AC is mandatory on `competition_type === 'matchmaking'` but Hubs opt in via their "Security Requirements" toggle — so the per-player roster boolean is the only reliable signal. Intent (both queue types acceptable) is preserved; the gate is per-player, not per-queue. Phase 4's anti-cheat filter lives in `FaceitGameClient` / `FaceitGameApi` and rejects matches where any roster entry has `anticheat_required === false`. All other directional picks hold up under the research.

**Needs human follow-up before Phase 4** — questions to ask FACEIT support / via the dev portal:

1. **Rate limits**: per-minute / per-hour quota on a production server-side API key, and which headers (`Retry-After` / `X-RateLimit-Reset`) the 429 response carries.
2. **Webhook retry policy**: at-least-once is confirmed, but the budget (max retries, backoff) is undocumented.
3. **Webhook egress IPs**: if available, allowlisting these strengthens webhook security beyond the static shared secret.

(PKCE support is no longer pending — confirmed required during Phase 2 implementation.)

**Other phase-affecting notes**:

- **Phase 2**: FACEIT allows only **one redirect URI per OAuth app** — dev + production need separate FACEIT app registrations. Plan for two `client_id` / `client_secret` pairs (per-environment).
- **Phase 4**: no Composer SDK for the FACEIT Data API exists — `FaceitGameClient` is a hand-rolled Http wrapper, consistent with how `LichessGameClient` is structured today.
- **Phase 4 webhook handling**: when `match_status_finished` arrives, the handler MUST re-fetch via the Data API before triggering settlement. Defense-in-depth against webhook spoofing.

**Phase 2 implementation corrections (2026-06-08)** — surfaced during real-world wiring:

- **Server-side API key arrived in Phase 2**, not Phase 4. The Data API rejects OAuth user tokens with 403, so `FaceitProfileClient` reads `config('services.faceit.api_key')` for its bearer auth. When the env var is unset the link still creates a LinkedAccount row with `skill_rating = null` (graceful dev path) — Phase 4's `FaceitGameClient` reuses the same config key.
- **FACEIT loosely validates redirect_uri (host match) but redirects to the saved URI** on the OAuth2 client, not the requested one. Mismatches silently land users on the wrong page with `?code=&state=` query params attached — they never reach our callback handler. Saved URI must exactly equal the callback path.
- **HTTPS required for redirect URIs, no `http://localhost` exception**. Dev needs an HTTPS tunnel (ngrok / Cloudflare Tunnel / Caddy + mkcert). `bootstrap/app.php` gained `$middleware->trustProxies(at: '*')` so Laravel detects the tunnelled HTTPS scheme correctly.
- **`RedirectUnprefixedLocale` middleware + `TestCase` exempt-list** both gained `'auth'` so `/auth/{provider}/callback` paths stay locale-agnostic (FACEIT's single-redirect-URI constraint forces this). Future OAuth providers (Riot, Discord, etc.) under `/auth/*` inherit the exemption.
- **Toast flashes must use `Inertia::flash('toast', ...)`** — Stakly's frontend reads toasts from Inertia's flash channel only, not Laravel's session flash. `redirect()->with('toast', ...)` silently drops on the frontend.

### Not in M15

- Auto-resolution from those adapters — that's M14, gated on volume.
- Filament admin moderation surfaces for the new game types — covered by M12 (shipped). New game types automatically appear in the existing `GameMatchResource` queue.
- Marketing / homepage copy for the anti-cheat trust pitch — separate from engineering scope; revisit alongside the existing marquee-copy cleanup.
- Aggregator-as-a-service (PandaScore / Bayes / Abios) — considered and parked. Reconsider only if the per-game maintenance burden gets painful and revenue can absorb the monthly cost.

---

## M28 — Designed Fees page

A standalone, hand-coded React page at `/fees` that explains Stakly's 5–10% commission visually and transparently, with an interactive calculator and brand-grade design. This is the **highest-leverage marketing surface** on the site — money platforms must answer "what's your cut?" before users will sign up, and a designed page (vs a CMS prose page) lets us turn that disclosure into a trust signal instead of a wall of legal text.

Not CMS-managed on purpose. The Filament CMS template (`cms/page.tsx`) is intentionally simple — correct for About / Privacy / Terms, wrong for a marketing landing that wants animation, an interactive calculator, scroll-triggered reveals, and a comparison block. Editing copy on this page means a code push; the trade-off is worth it for the design ceiling.

### Design decisions taken into this milestone

- **`/fees` not `/pricing`.** "Pricing" implies subscription tiers we don't have. "Fees" is honest for a commission-based platform — what we take when you win.
- **Hand-coded React, NOT a CMS row.** Loses Filament editability; gains animation, interactivity, custom layout, scroll triggers. The few times a year fee copy changes is worth a code push.
- **Header nav swap.** Remove `Support` from the header (it stays in the footer); add `Fees` in its place. Header reserved for high-priority decision surfaces (Listings, How it Works, **Fees**, Create listing CTA). Footer for utility / legal / info.
- **Static fee rate read from `config('stakly.platform_fee_rate')`.** Single source of truth — the same value the wallet ledger uses. No duplicate constants in the React page. If fees become per-game in M15-era, the page extends to pull the rate per selected game.
- **Calculator is client-side only.** No backend round-trip — the math is `stake * (1 - feeRate)` for take-home, `stake * feeRate` for the platform cut. Instant feedback.
- **Activate the `ui-ux-pro-max` skill** before designing the page so the visual treatment lands inside the Stakly palette and skips the rainbow-template anti-pattern from the M26 branded About attempt.

### Phases

**Phase 1 — Page scaffold + content + interactive calculator**

- [x] `FeesController::show()` returns `Inertia::render('fees/page', ['feeRate' => config('stakly.platform_fee_rate')])`.
- [x] Route `Route::get('/fees', [FeesController::class, 'show'])->name('fees')`.
- [x] React `pages/fees/page.tsx` inside `SiteLayout` with hero, calculator, "How fees work" walkthrough, comparison block, FAQ, bottom CTA.
- [x] Hero — display headline ("5–10%. That's it." or similar), gradient accent on the percentage, lede paragraph explaining the rate honestly (no buried costs).
- [x] Calculator component — controlled `stake` input (USDT, min $5), instant breakdown: stake / platform fee / your take-home. Visual treatment (gradient on take-home, muted on fee).
- [x] "When the rate varies" section — what determines 5% vs 10% (TBD: stake size? player rating? listing time-to-fill?). User-supplied content; ship with reasonable placeholders.
- [x] Honest comparison strip — Stakly vs typical online betting/gaming platforms. Positioning: "we take less, transparently". No misleading claims — list real numbers we can defend.
- [x] FAQ accordion (shadcn `Accordion` component, already in the project) — 5–6 entries: When is the fee charged? Who pays it? What if the match is cancelled? Is the rate ever waived? Do you ever take more? What about deposit/withdrawal fees?
- [x] Bottom CTA — single gradient button "Browse listings" or "Create your first listing" (links to `/listings` if guest, `/listings/create` if authed).
- [x] Header swap: remove `Support` from `SiteHeader` and `MobileMenu` nav arrays, add `Fees` → `/fees`.
- [x] Tests: route resolves, page renders with the correct fee rate prop, calculator math holds (extract `calculatePayout(stake, feeRate)` to a pure helper and unit-test it).

**Phase 2 — Animations + scroll-triggered polish**

- [x] Scroll-triggered reveals via `motion` (Framer Motion) — each section fades / slides in as it enters viewport. Stagger within sections (calculator inputs cascade in, FAQ entries cascade).
- [x] Calculator output transitions — numbers count up via spring animation when the stake changes. Reuse the `useReducedMotion` hook to collapse to instant updates for users who prefer it.
- [x] Hover / focus states polished — the calculator input has the gradient glow on focus, the CTA pulses subtly on hero entry.
- [x] Subtle atmospheric background — single soft gradient blob behind the hero (the M26 lesson: subtle blobs work, multi-layered aurora meshes overkill).
- [x] Verify Lighthouse perf doesn't regress — animation work shouldn't push the page past the budget. Check before / after.

### Not in M28

- Per-game fee variation UI. Today the rate is global (read from config); when M15 introduces per-game adapters the page extends. No premature scaffolding.
- A/B testing infrastructure for headline copy. Premature for a page that isn't even live yet.
- Affiliate / referral fee tracking. Different scope; if revenue-share programs ship, they own their own page.
- Localised currency conversion ("how much is this in EUR?"). USDT is the unit on every Stakly surface; introducing currency conversion UI confuses the platform's denomination.

---

## M38 — Redis for queue, cache & sessions (launch-readiness)

Redis is provisioned in the stack (`compose.yaml`, `REDIS_HOST=redis`, `phpredis` in Sail) but **unused** — `QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` all point at the Postgres `database` driver. This milestone flips the hot, latency- and correctness-sensitive infra onto Redis so the settlement pipeline is fast and isolated from the money ledger, the rate-limiter/breaker run on atomic ops, and we get queue observability before launch.

**Why each one moves:**

- **Queue** — runs the settlement auto-fetch jobs (12–15 retries + backoff) + all notifications. `database` queue *polls* Postgres (pickup latency) and contends with the ledger for connections/locks; Redis uses blocking pops (instant pickup) and isolates job churn from money writes. Also unlocks **Horizon**.
- **Cache** — backs `ProviderCircuitBreaker` (read on *every* outbound provider call — `app/Services/Provider/ProviderCircuitBreaker.php`) + the `RateLimiter::for` limiters + the catalog / CMS read caches. The limiter gets atomic increment for free on Redis (Laravel's `Limit`).
- **Sessions** — a Postgres write per authenticated request today; Redis offloads it.

### Decisions / things this touches

- **Separate Redis logical DBs per concern (queue / cache / sessions).** A naive single-DB flip means `php artisan cache:clear` (or a cache `FLUSHDB`) could wipe queued settlement jobs or log everyone out. Laravel's default `cache` connection uses `REDIS_CACHE_DB`; queue uses `default`. Pin queue, cache, session to distinct DB indexes (or instances).
- **Eviction policy — the launch gotcha.** The cache DB can be `allkeys-lru` (evictable). The **queue + session DBs must be `noeviction`** — evicting a queued settlement job under memory pressure = lost money work; evicting sessions = mass logout. Set per-DB, or use separate instances.
- **Circuit-breaker fail-mode when Redis is unreachable — DECIDED: fail-open + log/alert** (2026-06-23). On a Redis blip the breaker reads as not-tripped and provider calls go through, with a logged alert — a transient hiccup shouldn't freeze settlement, and the providers' own 429s + our retries still protect us. **Implemented (P2) via Laravel 13's native `failover` cache store (`redis → array`), not hand-rolled try/catch** — the `array` tail is infallible so no cache call can throw; fail-open + catalog-recompute + limiter-allow all fall out for free, and the `CacheFailedOver` event is the alert hook (`LogCacheFailover`). This is why no breaker/controller/limiter code changed. **Caveat — sessions + queue are not under the failover net** (the cache store covers cache only): a Redis outage logs everyone out for its duration (ephemeral, no money-loss, self-heals) and pauses the queue (workers retry). Queue-failover was rejected (splits jobs across redis+postgres, needs a second drainer).
- **Breaker atomicity (optional hardening).** The breaker does `Cache::get` array → append → `Cache::put` — read-modify-write that races on *any* backend (a lost increment just trips the breaker slightly later; not money-loss). Redis *enables* a clean fix (atomic list/sorted-set or `Cache::lock`); do it here or note as follow-up. The rate limiter is already atomic on Redis.
- **Keep CI hermetic.** Confirm `phpunit.xml` pins cache=array, queue=sync/database, session=array so the suite never needs a live Redis and `RefreshDatabase` stays on Postgres. The `.env` flip must not leak into tests.

### Phases

> **P1 + P2 ship as one slice.** The driver flip and the graceful-degradation wrapping go together — flipping to Redis without the resilience net would briefly make the homepage + settlement depend on Redis with no fallback. P3 (Horizon) + P4 (prod wiring) follow.

**Phase 1 — Driver flip + connection hygiene** — ✅ shipped 2026-06-23

- [x] `QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` → `redis` (`.env` + `.env.example`); `CACHE_STORE=failover` (see P2). Distinct logical DBs: `0` default/locks/Horizon · `1` cache · `2` queue (new `queue` connection) · `3` session (new `session` connection), wired via `config/database.php` + `REDIS_QUEUE_CONNECTION=queue` / `SESSION_CONNECTION=session`. `CACHE_PREFIX` default (`stakly-cache-`) left as-is.
- [x] Test env stays array/sync/array (`phpunit.xml` already pinned — the `.env` flip can't leak in); full suite **1554 → 1557** green post-flip.
- [x] Dev smoke: cache write → DB 1, queued job → DB 2, real web request → session on DB 3. All confirmed via the named Redis connections.

**Phase 2 — Settlement-path resilience** — ✅ shipped 2026-06-23

- [x] **Done at the infra layer, not by wrapping app code.** `CACHE_STORE=failover` (`redis → array`): Laravel 13's `FailoverStore` catches `Throwable` on a Redis error and falls through to the infallible in-memory `array` tail, so the breaker reads fail-open (empty → not tripped), the 4 catalog/CMS `Cache::remember` sites recompute from Postgres, and the rate limiter stops blocking — **all without touching `ProviderCircuitBreaker`, the controllers, or the limiter.** Writes return on the first working store (normal op = redis only). The fall-through fires `CacheFailedOver` once per outage → `App\Listeners\LogCacheFailover` logs `critical` (the "log/alert").
- [ ] (Optional, deferred) make the breaker's window-counting atomic. Failover already covers the resilience requirement; the read-modify-write race is non-money-loss (a lost increment just trips slightly later), so this is a clean-up, not a blocker.
- [x] Tests for the degraded path: `tests/Feature/Infrastructure/CacheFailoverResilienceTest.php` (+ `tests/Support/ThrowingCacheStore.php`) — a `throwing → array` failover proves cached reads survive an outage + `CacheFailedOver` fires, the breaker reads fail-open, and the homepage returns 200 with the cache backend down.

**Phase 3 — Horizon (queue observability)** — confirmed in (2026-06-23)

- [ ] `laravel/horizon`; admin-gated dashboard (`/admin`-gated, same admin role as Filament); prod worker/supervisor config. Surfaces retrying/failed settlement + notification jobs, throughput, wait times — eyes on the money pipeline before launch.

**Phase 4 — Production wiring**

- [ ] Managed Redis on the deploy target (Laravel Cloud provides one) + `ext-redis` (or `predis`); per-env auth/TLS; per-DB eviction policy (cache evictable, queue + session `noeviction`).

### Not in M38

- **Reverb horizontal scaling via Redis pub/sub** — single-node today; the trigger is running >1 Reverb process (`REVERB_SCALING_ENABLED` + a Redis connection). Revisit then.
- Moving ledger / business data off Postgres — never; Postgres stays the source of truth.
- Cache tags — no current usage; Redis supports them if a need arises.

---

