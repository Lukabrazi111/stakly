# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M8 all phases, M10, M11, M12 all phases, M14 all phases, M16 all phases, M17, M18, M19, M22, M23, M24, M25, M26 all phases, M27 all phases, M29 all phases, M30 all phases, M31 all phases, M32 all phases). **Parked milestones** (work that isn't being picked up right now) also live in the archive — currently M13. This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

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

**In-flight:**

- **M34 P7** — FACEIT-style lobby header bar (team leaders + mode chip + state-aware countdown + Share). Replaces the current `2 v 2 lobby` title block on the team-play lobby view and **folds the ready-check countdown out of `CoordinationPanel` into the header** so the center column doesn't duplicate it. See M34 detail section for the full phase entry.
- **M34 P8** — Team match-page polish (roster card parity with lobby `slot-card.tsx`) + "Team {leader}" labels on match page + lobby-lock notification with sound preference. See M34 detail section.

**Active / upcoming:**

- **M15** — Multi-game expansion. First cut is CS2 via FACEIT; Dota 2 / Riot adapters extend the same pattern once that ships. **All 6 phases shipped** (Phase 0 research → Phase 5 dispute fast-path + telemetry). Two small Slice 4 follow-ups remain when FACEIT data arrives (event-id field name + IP allowlist) — neither is blocking. **CS2 production launch unblocked** as of M34 P4 (2026-06-13).
- **M28** — Designed Fees page. Hand-coded marketing surface — transparent 5–10% commission disclosure, interactive calculator, replaces footer Support link in header nav. Pre-launch trust signal; design-driven (`ui-ux-pro-max` skill).
- **M20** — Email notifications. **Spec materially shrunk**: M27 P5 already shipped the in-app preferences UI + `notification_preferences` table + 9 `PlayerNotification` classes; M30 P4 wired the `mail` channel for ban notifications. What's left = branded HTML email templates, flip `'mail'` into `via()` on the remaining PlayerNotification subclasses, production SMTP config. Realistically 2–3 days.
- **M21** — Blacklist + safety. Block users from listings + chat, with anti-evasion considerations. Has open design questions (block semantics + multi-account evasion) — needs alignment before coding.
- **M33** — Listing time-control contract. Make Stakly's accepted time controls (blitz / rapid / classical) explicit in the listing-creation form, surface `time_control_mismatch` as a player-facing banner on stuck matches, and optionally re-enable Slice 3d strictness behind a per-listing opt-in. Reverted from M14 on 2026-06-06 — friction (legitimate correspondence / bullet games rejected silently) outweighed the small sandbag attack surface at this stage. Revisit when launch scale or a real abuse incident makes it relevant.
- **M34** — Team play + lobbies (was the CS2 production-launch dependency). Soft-join lobby model with hybrid stake-at-Ready commitment, per-player escrow at Ready, 5-min ready-check timeout, lobby-owner kick (5-min cooldown), 24h fill timeout, single-lobby-per-user rule, public marketplace + private invite-token URLs, per-player skill range. CS2 = 5v5 + 2v2 Wingman. Chess (1v1) keeps the existing `TakeListingAction` flow. **Phases 0–6 + P3.1 + P3.2 shipped 2026-06-12 → 2026-06-14.** **P7 (FACEIT-style lobby header bar) added 2026-06-14, in flight** — surfaced during dogfooding; replaces the current title block with a unified header that also folds the ready-check countdown out of the center column.
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

- [ ] `FeesController::show()` returns `Inertia::render('fees/page', ['feeRate' => config('stakly.platform_fee_rate')])`.
- [ ] Route `Route::get('/fees', [FeesController::class, 'show'])->name('fees')`.
- [ ] React `pages/fees/page.tsx` inside `SiteLayout` with hero, calculator, "How fees work" walkthrough, comparison block, FAQ, bottom CTA.
- [ ] Hero — display headline ("5–10%. That's it." or similar), gradient accent on the percentage, lede paragraph explaining the rate honestly (no buried costs).
- [ ] Calculator component — controlled `stake` input (USDT, min $5), instant breakdown: stake / platform fee / your take-home. Visual treatment (gradient on take-home, muted on fee).
- [ ] "When the rate varies" section — what determines 5% vs 10% (TBD: stake size? player rating? listing time-to-fill?). User-supplied content; ship with reasonable placeholders.
- [ ] Honest comparison strip — Stakly vs typical online betting/gaming platforms. Positioning: "we take less, transparently". No misleading claims — list real numbers we can defend.
- [ ] FAQ accordion (shadcn `Accordion` component, already in the project) — 5–6 entries: When is the fee charged? Who pays it? What if the match is cancelled? Is the rate ever waived? Do you ever take more? What about deposit/withdrawal fees?
- [ ] Bottom CTA — single gradient button "Browse listings" or "Create your first listing" (links to `/listings` if guest, `/listings/create` if authed).
- [ ] Header swap: remove `Support` from `SiteHeader` and `MobileMenu` nav arrays, add `Fees` → `/fees`.
- [ ] Tests: route resolves, page renders with the correct fee rate prop, calculator math holds (extract `calculatePayout(stake, feeRate)` to a pure helper and unit-test it).

**Phase 2 — Animations + scroll-triggered polish**

- [ ] Scroll-triggered reveals via `motion` (Framer Motion) — each section fades / slides in as it enters viewport. Stagger within sections (calculator inputs cascade in, FAQ entries cascade).
- [ ] Calculator output transitions — numbers count up via spring animation when the stake changes. Reuse the `useReducedMotion` hook to collapse to instant updates for users who prefer it.
- [ ] Hover / focus states polished — the calculator input has the gradient glow on focus, the CTA pulses subtly on hero entry.
- [ ] Subtle atmospheric background — single soft gradient blob behind the hero (the M26 lesson: subtle blobs work, multi-layered aurora meshes overkill).
- [ ] Verify Lighthouse perf doesn't regress — animation work shouldn't push the page past the budget. Check before / after.

### Not in M28

- Per-game fee variation UI. Today the rate is global (read from config); when M15 introduces per-game adapters the page extends. No premature scaffolding.
- A/B testing infrastructure for headline copy. Premature for a page that isn't even live yet.
- Affiliate / referral fee tracking. Different scope; if revenue-share programs ship, they own their own page.
- Localised currency conversion ("how much is this in EUR?"). USDT is the unit on every Stakly surface; introducing currency conversion UI confuses the platform's denomination.

---

## M34 — Team play + lobbies

Production-launch dependency for CS2 (FACEIT competitive is 5v5, no native ranked 1v1 mode) and every future 5v5 game (Dota 2, Valorant, LoL). Extends the listing → match flow with a **soft-join lobby model** sitting between "listing posted" and "match Pending." Chess (1v1) keeps its existing `TakeListingAction` flow unchanged — lobby applies only when `team_size > 1`.

### The core flow — hybrid stake-at-Ready model

The big design decision is **when** stakes commit relative to **when** players join. The unified hybrid model:

1. **Listing creation never escrows.** Alice creates a 5v5 CS2 listing for $100/player; no money moves yet. She's auto-soft-joined to Team A slot 1.
2. **Soft join is free.** Bob clicks "Join Team A" → he claims a slot, sees the lobby + chat. No stake. He can leave anytime, no penalty, no refund needed (there's nothing to refund).
3. **Click Ready → YOUR stake escrows in that moment.** Per-player atomic, not all-10 atomic at match start. So Alice clicks Ready, her $100 escrows. Bob clicks Ready, his escrows. By the time all 10 have Ready'd, all 10 stakes are already locked — no last-second balance race.
4. **Un-Ready (or leave) refunds your stake** until the match flips Pending. Free to change your mind mid-lobby.
5. **All 10 Ready → match flips to `Pending`, lobby locks.** Leaving now is a forfeit (same semantics as today's 1v1 take-then-bail).
6. **One active lobby per user, globally across all games.** Soft-join into any team-play listing's lobby blocks if you're already a participant on any other team-play lobby (CS2, Dota 2, whatever). Closes the multi-lobby slot-blocking abuse and keeps "where am I committed" UX trivially answerable.
7. **Ready-check timeout: 5 minutes from when lobby reaches max soft-joined.** Non-Ready players are auto-vacated, slots reopen, lobby keeps recruiting. Already-Ready players stay Ready (their stake stays escrowed; they're not penalized for being on time). Closes the "hostage taker" vector.
8. **Creator is just another participant — no implicit Ready.** The listing creator picks a slot at creation (same `creator_side` field), shows up in `lobby_participants` like everyone else, and must click Ready themselves. If the ready-check timer vacates the creator, the lobby cancels and any Ready'd participants get refunded. No asymmetric "always Ready" privilege — creator has the same skin in the game.
9. **Lobby owner can kick — 5-minute cooldown on rejoining the same listing.** The listing creator can remove any other participant from any slot — refunds them if they'd Ready'd, vacates their slot. Kicked player can't rejoin THAT specific listing's lobby for 5 minutes (`kicked_at` stays on the `lobby_participants` row; JoinLobbyAction checks `kicked_at + 5 min > now()`). They can join other owners' lobbies immediately. After 5 min they're free to rejoin even this listing. Permanent cross-listing blocking is M21's job, not kick's. Owner can't kick themselves; if they leave voluntarily, the lobby cancels and all stakes refund.
10. **Lobby fill timeout: 24h.** If the lobby never reaches max soft-joined within 24h of creation, the listing cancels and any escrowed stakes (from already-Ready'd joiners) refund. If the 24h hits mid-`ReadyChecking`, the listing still cancels and any in-flight Ready'd stakes refund — the timer is the listing's life, not just the recruiting phase.
11. **Insufficient balance at Ready click is a silent retry-able failure.** "Top up your balance to ready up" message. Doesn't penalize the player, doesn't impact others. Same shape as today's `TakeListingAction` insufficient-balance handling.

### Architectural decisions

- **`listings.team_size` (int, default 1).** Chess listings stay 1; CS2 = 5; 2v2 Wingman = 2; future games per their format. Drives lobby size: total participants = `2 × team_size`.
- **New `lobby_participants` table.** Columns: `id`, `listing_id` (FK, cascade), `user_id` (FK, restrict-delete — they may hold escrow), `side` ('a' | 'b'), `slot_index` (smallint, 0..team_size-1), `is_ready` (bool, default false), `stake_held_at` (nullable timestamp — null = soft-joined, set = stake escrowed), `kicked_at` (nullable timestamp — set on kick so the 5-min same-listing cooldown is enforceable; row stays as audit trail, not deleted), `joined_at`, timestamps.
    - **Partial unique indexes (Postgres-specific) so kicked rows don't poison live constraints:**
        - `UNIQUE (listing_id, side, slot_index) WHERE kicked_at IS NULL` — only one active participant per slot; kicked rows are excluded so a new joiner can claim the freshly-vacated slot.
        - `UNIQUE (listing_id, user_id) WHERE kicked_at IS NULL` — only one active participation per user per listing; kicked rows are excluded so the same user can rejoin (post-cooldown) without colliding with their old kicked row.
    - **Kick mechanics:** `KickParticipantAction` UPDATEs the row to set `kicked_at = now()`, `is_ready = false`. Stake released first if Ready'd. On rejoin (after cooldown), JoinLobbyAction inserts a NEW row — old kicked row is preserved for forensics. Multiple kicked rows per (listing, user) are fine — the partial unique constraint only governs live rows.
- **`listings.lobby_state` (varchar, nullable).** `recruiting` (soft-joining open) → `ready_checking` (5-min timer running) → `locked` (all Ready, match Pending) → `cancelled` / `expired`. Null for `team_size = 1` listings (chess flow doesn't use it). Drives UI affordances + cron sweep targeting.
- **Listings with `team_size > 1` skip the existing `Listing.status = Open → Taken` path through `TakeListingAction`.** Instead, lifecycle is `Open` (recruiting) → match created in `LobbyFilling` at listing creation → match flips to `Pending` + listing flips to `Taken` when all Ready. Existing `TakeListingAction` keeps handling `team_size = 1` listings unchanged.
- **Skill range is per-player (`listings.skill_min` / `skill_max`).** Each joiner's snapshotted FACEIT ELO must fall in range. Per-team averaging is explicitly rejected — lets one whale carry low-skill teammates, breaks the trust pitch.
- **Side assignment.** Creator picks their side at listing creation (`listings.creator_side` = 'a' | 'b', null for team_size=1). Other joiners pick at join time. Public listings expose both sides for joining; private listings expose both sides via the same invite link.
- **Public / private toggle.** `listings.is_public` (bool, default true). Public listings appear in `/listings`. Private listings have a unique invite token (`listings.invite_token` — opaque 32-char URL-safe, nullable + unique) and are reachable only at `/lobbies/{invite_token}`. Token expires when the match goes Pending or the listing cancels.
- **Lobby chat reuses existing `Message` infrastructure.** Same `match_id`-keyed chat we have today, but available from the moment the match (in `LobbyFilling` state) is created — not just after Pending. Players coordinate FACEIT party invites + queue-up in chat before clicking Ready.
- **Match-row created early — new `MatchStatus::LobbyFilling` case.** Today `TakeListingAction` creates the match when the taker takes; for `team_size > 1` listings the match row exists from listing creation onwards in `LobbyFilling` status, flipping to `Pending` when all Ready. Chat works from day 1 because `messages.match_id` stays the single source. Trade-off: every consumer that today assumes `status === Pending` means "match is playing" needs an explicit `LobbyFilling` carve-out. Known touchpoints to audit + gate in P1:
    - `DispatchAutoFetchAction` — skip auto-fetch on `LobbyFilling` (don't poll providers before the match has actually started).
    - `GameMatchPolicy::view` / `openDispute` / `requestCancellation` — `LobbyFilling` participants can view + chat, but can't dispute or request match-cancellation (lobby has its own leave / kick affordances).
    - `GameMatchController::show` (`/matches/{match}`) — redirect `LobbyFilling` matches to `/lobbies/{listing_id}` so users always land on the right UI surface.
    - `MatchesResolveTimeouts` cron — current sweep targets `Pending` only; `LobbyFilling` is owned by separate lobby-fill / ready-check cron commands.
    - `User::usernameChangeBlockers()` "in-flight match" check — `LobbyFilling` counts as in-flight (money may be escrowed) just like `Pending`.
    - `WalletTransactionResource` admin filters / sibling-entity lookups that group by match status.

### Verification flow — extending M15 P4 for 5v5

The existing `FaceitGameClient` already parses full 5-player rosters per faction (built in M15 P4 Slice 1). What needs to extend:

- **`AutoFetchFaceitGameJob::isOpposingRosterPair()`** today checks "creator on faction1 AND taker on faction2 (or flipped)." Generalize to "all `team_size` Team A players on one faction AND all `team_size` Team B players on the other faction." Mismatch → no candidate.
- **Match-finding strategy.** Today: query creator's history, fall back to taker's. For 5v5: query the first soft-joined player on each side's history (first slot ordering). Same opposing-roster verification per candidate.
- **Card payload extension.** Add `winning_team` ('a' | 'b') alongside / replacing `winner_user_id`. The card lists all `team_size` winning user_ids so `SettleFromCardAction` can pay each one.
- **`SettleFromCardAction` settlement math.** Each winner gets `2 × stake − platform_fee_share` where `fee_share = (total_pot × fee_rate) / team_size`. Each loser loses their stake. Platform fee credited once.
- **`MatchProviderSnapshot.slot_index`** — verified NOT yet present (M15 P1 only added `provider_user_id` + `skill_rating_snapshot`). P0 adds it as a nullable smallint. Existing chess 1v1 snapshots stay null; new lobby-locked 5v5 snapshots populate 0..team_size-1 so settlement can map snapshot → user with team identity preserved.

### Phases

**Phase 0 — Schema + lobby model** _(shipped 2026-06-12 — commit `feat(m34-p0): team-play schema + lobby_participants + MatchStatus::LobbyFilling`)_

Read-only-ish foundation. No new user-facing flows; just the tables + models that everything else builds on.

- [x] Migration: `listings.team_size` (int, default 1), `listings.creator_side` (varchar 1 nullable; null for team_size=1), `listings.lobby_state` (varchar 16 nullable; null for team_size=1; values `recruiting | ready_checking | locked | cancelled | expired`), `listings.is_public` (bool, default true), `listings.invite_token` (varchar 32 nullable + unique). Index on `lobby_state` for the upcoming cron sweeps.
- [x] Migration: `lobby_participants` table — `id`, `listing_id` (cascade), `user_id` (restrict-delete), `side`, `slot_index`, `is_ready`, `stake_held_at`, `kicked_at`, `joined_at`, timestamps. Partial unique indexes (`WHERE kicked_at IS NULL`) on `(listing_id, side, slot_index)` and `(listing_id, user_id)` via `DB::statement` since Laravel's schema builder has no first-class API for partial indexes.
- [x] Migration: added `slot_index` (smallint nullable) to `match_provider_snapshots`. Existing 1v1 chess snapshots stay null.
- [x] Added `MatchStatus::LobbyFilling` case (`'lobby_filling'`).
- [x] `Listing` model: `lobbyParticipants()` hasMany, `isTeamPlay()` helper, `lobbyOwner()` accessor, `team_size` + `is_public` casts, fillable updates.
- [x] `LobbyParticipant` model: relations to `Listing` + `User`, casts (`is_ready` bool, `stake_held_at` / `kicked_at` / `joined_at` datetime), `live()` + `withinKickCooldown()` scopes, `SIDE_A` / `SIDE_B` constants.
- [x] `ListingFactory` gains `teamPlay()`, `lobbyReadyChecking()`, `lobbyLocked()`, `private()` states. `LobbyParticipantFactory` with `sideA()` / `sideB()` / `ready()` / `kicked()` states. `ListingSeeder` produces one CS2 5v5 lobby in `recruiting` (4 participants, 2 Ready'd) + one in `ready_checking` (10 participants, 6 Ready'd). `locked` state seed waits for P1's `LobbyLockAction` (needs the match-row transition pipeline).
- [x] Pest tests (`tests/Feature/LobbyParticipantTest.php`): 18 cases / 35 assertions covering the enum case, Listing helpers, factory states, scopes, partial-unique-index behavior (live row blocks duplicate, kicked rows exempt, slot reclaimable post-kick, user rejoin post-kick), and `slot_index` on `MatchProviderSnapshot`. Full suite stays at 1330 pass / 5082 assertions.

**Phase 1 — Backend lobby flow (actions + cron)** _(shipped 2026-06-12 — commit `feat(m34-p1): lobby actions + cron + LobbyFilling consumer audit`)_

The whole soft-join → Ready → escrow → lock pipeline. No frontend yet.

- [x] `CreateTeamPlayListingAction` — listing + paired `LobbyFilling` match + creator's slot-0 row in one transaction. No escrow at creation (locked design — stake commits per-Ready, not per-Create). Sentinels: `Listing` (success) / `'not_linked'` / `'already_in_lobby'`.
- [x] `JoinLobbyAction` — soft-join with `not_team_play` / `not_linked` / `skill_out_of_range` / `already_in_lobby` / `kick_cooldown` / `listing_unavailable` / `no_open_slots` sentinels. Fires `LobbyReadyCheckAction` on success to evaluate the `recruiting → ready_checking` transition.
- [x] `LeaveLobbyAction` — non-creator vacates with refund-if-Ready (sentinel `'left'`); creator-leave cancels the whole lobby + refunds all Ready'd (sentinel `'creator_cancelled'`), match → `Cancelled`. Rejects post-lock leaves.
- [x] `ToggleReadyAction` — per-player `Wallet::hold` on Ready / `Wallet::release` on un-Ready. Fires `LobbyLockAction` inline when the click brings all `2 × team_size` to Ready. Insufficient-balance → `'insufficient_balance'` sentinel (no penalty, retry-able).
- [x] `KickParticipantAction` — owner-only. UPDATEs row to set `kicked_at = now()` (NOT deleted — preserved as cooldown anchor + audit). Refunds Ready target. Calls `LobbyReadyCheckAction` to demote `ready_checking → recruiting` if soft-joined count drops.
- [x] `LobbyReadyCheckAction` — bidirectional state evaluator called from Join/Leave/Kick. Promotes `recruiting → ready_checking` (stamps 5-min deadline) when max soft-joined reached; demotes back if count drops.
- [x] `LobbyReadyCheckTimeoutAction` — when 5-min deadline passes: vacates non-Ready slots, returns to `recruiting`. Special case: if creator is non-Ready, cancels the whole lobby + refunds all Ready'd.
- [x] `LobbyLockAction` — transitions match `LobbyFilling → Pending`, listing `Open → Taken`, lobby_state `→ locked`, populates `match_provider_snapshots` with `slot_index` for every (live participant × linked account). Schema fix applied: `match_provider_snapshots` unique constraint extended from `(match_id, side, provider)` to `(match_id, side, slot_index, provider)` so 5v5 doesn't collide; `slot_index` defaults to 0 for chess back-compat.
- [x] `LobbyFillTimeoutAction` — 24h listing-life timeout (fires regardless of `recruiting` vs `ready_checking`). Refunds Ready'd participants, listing → `Expired`, match → `Cancelled`.
- [x] `lobbies:sweep-ready-check-timeouts` (every minute) + `lobbies:sweep-fill-timeouts` (hourly) artisan commands wired into `routes/console.php` with `withoutOverlapping`. Dedicated `scheduler` container added to `compose.yaml` running `php artisan schedule:work` so all `Schedule::command(...)` entries fire in dev + production (one container handles every current + future scheduled command).
- [x] `Pending`-consumer audit: `GameMatchPolicy::view` + `MatchChannel::join` recognize lobby participants for `LobbyFilling` matches (chat works from day 1); `GameMatchController::show` redirects `LobbyFilling` to `/listings/{listing}` until P3's lobby UI lands; `User::usernameChangeBlockers` counts `LobbyFilling` + active lobby participation as in-flight; Filament `GameMatchesTable` + `GameMatchInfolist` got the case in their exhaustive `match` expressions; TS `MatchStatus` union + `matches-format.ts` + `match/show.tsx` extended to keep `Record<MatchStatus, T>` exhaustive.
- [x] Pest tests (`tests/Feature/Lobby/`): 48 new cases — CreateTeamPlay (5), Membership (18), ToggleReady (7), Lifecycle (6), AuditConsumer (9), Cron (3). Covers each action's happy path + every sentinel + insufficient-balance + creator-leaves-cancel + kick cooldown + ready-check timeout cancel/revert paths + lock-with-snapshots + 24h fill. Full suite: 1378 pass / 5216 assertions (1330 → 1378).

**Phase 2 — Private invite links** _(shipped 2026-06-12 — commit `feat(m34-p2): private invite links + StoreListingRequest team-play fields + marketplace filter`)_

Backend-only slice between P1's actions and P3's frontend.

- [x] `Game::allowedTeamSizes()` — per-game whitelist (`Chess: [1]`, `Cs2: [1, 5]`, `Dota2: [1]`). 2v2 Wingman extends the CS2 entry to `[1, 2, 5]` in P5.
- [x] `StoreListingRequest` accepts `team_size` / `creator_side` / `is_public`. Defaults `team_size = 1` and `is_public = true` when missing (legacy chess payloads stay valid). `creator_side` required only when `team_size > 1`. Per-game `team_size` validation in `withValidator()`.
- [x] `ListingController::store` branches on `team_size > 1` to `CreateTeamPlayListingAction`, else existing `CreateListingAction`. New `'already_in_lobby'` sentinel → info toast + redirect to `/listings/mine`. Private listings get a 32-char `invite_token` (`Str::random(32)`) generated server-side.
- [x] `Listing::scopeOnPublicMarketplace` extended to filter `is_public = true`. Private listings stay reachable via direct `/listings/{id}` URL for visitors who already have the link.
- [x] `LobbyController::showByToken` at `/lobbies/{token}` resolves the invite token and redirects to the canonical `/lobbies/{listing}` URL. 404 on missing / non-Open / locked / cancelled / expired. Route renamed to `lobbies.invite`; token constrained to `[A-Za-z0-9]{32}` so a malformed segment 404s at the routing layer.
- [x] Pest tests (`tests/Feature/Lobby/LobbyInviteLinksTest.php`): 16 cases covering validation (valid payload, missing-creator-side, bad-side, wrong-game team_size, defaults), controller branching (team_size 5 → no escrow, team_size 1 → escrow, invite_token generation), marketplace filter (public visible / private hidden / direct URL still works), and `/lobbies/{token}` resolution (valid → redirect / missing / cancelled / locked / malformed). Full suite: 1378 → 1394 (+16).

**Phase 3 — Frontend lobby UI** _(shipped 2026-06-12 — commit `feat(m34-p3): lobby page + join/leave/ready/kick + chat embed`)_

- [x] `ListingPolicy` extended with `viewLobby` / `joinLobby` / `leaveLobby` / `toggleReady` / `kickFromLobby` (merged from a transient `LobbyPolicy` since both operate on `Listing`). Public listings are anyone-readable; private listings + every mutation are participant-gated.
- [x] `LobbyController` — `show()` Inertia render, `showByToken()` token-resolve redirect (P2), `join() / leave() / toggleReady() / kick()` action endpoints. Each gated by policy + mapping action sentinels to flash toasts. Inertia render also ships the last 200 chat messages so the lobby reuses the existing match chat pipeline.
- [x] `LobbyResource` — full payload: listing fields, roster (2 × team_size grid with filled / null slots), viewer-derived state (is_owner / is_participant / is_ready / balance / can_kick). Owner-only `invite_token` exposure.
- [x] Routes: canonical `GET /lobbies/{listing}` public (constrained `\d+`); `GET /lobbies/{token}` renamed to `lobbies.invite`; mutation endpoints (`POST join` / `POST leave` / `POST ready` / `DELETE participants/{user}`) inside auth+verified. Wayfinder regenerated with `--with-form` so the frontend uses typed `join({listing}).url` etc.
- [x] Frontend: `pages/lobby/show.tsx` (header + status banner + team roster + action bar + chat right rail / mobile bottom sheet, 5 s Inertia polling while in-flight). `types/lobby.ts` typed contract. Sub-components: `lobby-status-banner` (recruiting / ready_checking / locked branches), `ready-check-countdown` (live MM:SS, red+pulse in final 30 s), `team-roster` (Team A | VS | Team B layout), `slot-card` (Filled + Empty variants, identical dimensions so the grid doesn't reflow), `lobby-actions` (Ready toggle + Leave + insufficient-balance hint). Reuses Stakly's palette + glow tokens.
- [x] `ChatPanel` + `ChatMessageBubble` + `MobileChatTrigger` got an optional `participants?: MatchPlayer[]` prop. When set, takes precedence over the 1v1 `creator + taker` for sender-to-bubble mapping. Match page unchanged; lobby passes its full live roster. Additive refactor, no existing call site touched.
- [x] Pest tests (`tests/Feature/Lobby/LobbyPageTest.php`): 16 cases — page renders for public visitors / participants / strangers / non-team-play; redirect for locked/cancelled; invite_token owner-only exposure; all four mutation endpoints with policy gates. Full suite: 1394 → 1410 (+16). TypeScript clean (`tsc --noEmit` exit 0).

Real-time `lobby:{listing_id}` Reverb broadcasts replacing the 5 s polling loop are tracked in the Phase 3.1 future-enhancements block below (intentionally deferred — 5 s polling delivers the experience adequately, broadcasts are a follow-up).

**Phase 3.1 — Unify lobby into the listing page**

Frontend follow-up to P3. P3 shipped the lobby as a separate `/lobbies/{listing}` URL — but the listing and the lobby are the same row, so the URLs should be too. Collapses lobby UI into the canonical `/listings/{id}` page so users browse the marketplace and discover lobbies through the same flow they already know. `/lobbies/{id}` survives as a 301 redirect for any links shared during P3 testing.

**Slice A — Listing-card adapter on the marketplace index (shipped 2026-06-12)**

The card on `/listings` didn't know about team-play before: it showed the 1v1 "Take" CTA and the chess time-control pill even for CS2 5v5 listings. Click-through still routes to the marketplace's existing per-game flow.

- [x] `ListingResource` exposes `team_size`, `lobby_state`, and a fill count (`live_participant_count`). Eager-loaded via `withCount('lobbyParticipants', fn ($q) => $q->live())` on every consuming query (`ListingController::index|mine|show`, `HomeController::welcome`, `UserController::show`) so the index stays one query per page.
- [x] Listing-row component branches on `team_size`: a purple `5v5` / `2v2` `TeamSizeBadge` sits next to `GameChip`; a `LobbyStateBadge` (animated amber "Ready check" pulse) renders when `lobby_state = 'ready_checking'`; a `LobbyFillCounter` ("3 / 10 players") tones from muted → warning → success as the lobby fills. CTA label flips from "Take" to "View lobby" for verified users and for the owner; "Sign in to take" → "Sign in to join" for guests; "Link FACEIT" unchanged. Row overlay link routes to `/lobbies/{listing}` for team-play (Slice B flips back to `/listings/{id}` once the listing show page hosts the lobby UI).
- [x] Owner dashboard (`/listings/mine`) reuses the same chips; `MineListingRow` shows the `5v5` badge + fill counter + state badge for owned team-play listings, and the row overlay link → `/lobbies/{listing}` while `status = open` (locked / cancelled / settled fall back to listing detail).
- [x] Tests (5, all green): resource payload includes the new fields on `/listings`, `/listings/mine`, `/listings/{id}`, and `/users/{username}`; kicked participants don't contribute to `live_participant_count`; chess listings keep sane defaults (`team_size=1`, `lobby_state=null`, `live_participant_count=0`).

**Slice A.2 — View toggle: rows ↔ grid layout for /listings + /listings/mine (shipped 2026-06-12)**

Rows are the right default for stake-comparison workflows (eBay / FACEIT / ESEA stay row-heavy for the same reason), but the team-play row from Slice A packs a lot of info — three chip mini-rows in the match column. Added a user-toggleable grid view as an alternative card layout. Default for every game = rows; user opts in to grid; preference persists per-user via a `listings_view_layout` cookie (chosen over localStorage so the initial server render matches without flash, single source of truth, cross-tab automatic). Grid mode's killer feature for team-play: each card shows a small roster preview (2-3 seated player avatars + count + names), so a browsing user sees "ucrona, kay88 +7 more" without clicking through.

- [x] `ListingResource` exposes `participant_previews` (array of up to 3 live participants `{username, name, avatar_thumb_url}` for team-play; empty for chess) plus `lobby_ready_check_deadline` (ISO-8601) for the countdown banner. Eager-loaded via `with(['lobbyParticipants' => fn ($q) => $q->live()->orderBy('joined_at'), 'lobbyParticipants.user:id,name,username', 'lobbyParticipants.user.media'])` on every consuming query — participant media is pre-loaded so the avatar accessor doesn't N+1 in the grid roster strip. Resource slices to 3 in PHP after the relation loads.
- [x] Filter-bar toggle: rows / grid icon pair (shadcn `ToggleGroup` riding the existing Stakly-skinned `toggle` variants). Persisted to `listings_view_layout` cookie (`Max-Age=1y`, `SameSite=Lax`); `HandleInertiaRequests::share()` reads the cookie and exposes `listingsViewLayout` as a global Inertia prop, whitelisted to `'rows' | 'grid'` so a tampered cookie falls back to `'rows'`. `useListingsView()` hook reads the prop on mount, writes the cookie + local state on toggle.
- [x] `listing-grid-card.tsx` (public) + `mine-listing-grid-card.tsx` (owner-side, with inline cancel). Vertical card layout: optional **`GridReadyCheckBanner`** (amber pulse strip with live MM:SS countdown, full-width at the top of the card whenever `lobby_state = 'ready_checking'`) → header (game + team-size + platform chips · ends-in) → owner avatar zone → match meta (skill + languages on one row, time-controls for chess) → roster preview row (avatar stack + fill counter + names) → stake + CTA footer. `RosterPreview` extracted to `team-play-meta.tsx` so both cards share it.
- [x] Grid container in `pages/listings/index.tsx` + `pages/listings/mine.tsx`: responsive `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3` when grid mode is active; rows layout otherwise. Mobile collapses to a single column → identical density to rows; the toggle remains visible so users can preview grid in a tablet split.
- [x] `ListingRow` + `MineListingRow` (rows layout) unchanged from Slice A — both layouts share the same Slice A primitives (`TeamSizeBadge`, `LobbyFillCounter`, `LobbyStateBadge`).
- [x] Tests (+9 across two files): `participant_previews` for team-play max 3 / ordered by `joined_at` / kicked excluded; empty for chess; correct shape (`username` + `name` + `avatar_thumb_url`); `lobby_ready_check_deadline` ISO-8601 only when `ready_checking`; `listings_view_layout` cookie honored on index + mine; tampered cookie value falls back to `rows`.

**Slice B.1 — Listing show page hosts the lobby UI for team-play (shipped 2026-06-12)**

Routing flip + reuse of the existing P3 lobby components. Lets users discover lobbies through the canonical marketplace URL instead of a sibling `/lobbies/{id}` URL.

- [x] `ListingController::show` branches by `team_size`. For team-play, `showTeamPlay()` eager-loads `lobbyParticipants.user.linkedAccounts` + `gameMatch`, attaches `SellerTrust::forBatch` data to each participant's user, slices the last 200 chat messages, and ships `lobby` (`LobbyResource`) + `messages` (`MessageResource`) alongside `listing`. Chess branch unchanged.
- [x] `pages/listings/show.tsx` discriminates on `lobby` prop. `TeamPlayBranch` mounts `<TeamPlayLobbyView />`; `ChessBranch` keeps the M23 detail UI verbatim. Old `pages/lobby/show.tsx` deleted.
- [x] Extracted `components/lobby/team-play-lobby-view.tsx` from the old lobby page so the polling loop, action handlers, and chat plumbing live in one reusable component.
- [x] `LobbyController::show` + `showByToken` return permanent **301** redirects to `route('listings.show', $listing)`. Route names retained so Wayfinder helpers + any in-flight test paths still resolve.
- [x] `TakeListingAction` early-returns `'not_takeable'` for `team_size > 1` listings before any wallet / match writes. `GameMatchController::take` maps the sentinel to a neutral info toast + redirect to `/listings/{id}`. Prevents crafted POSTs from tripping the UNIQUE-constraint 500.
- [x] `viewLobby` policy relaxed to `return $listing->isTeamPlay()` so the invite-token redirect lands non-participants on the canonical URL so they can join. Trade-off documented in the policy: sequential ID enumeration exposes private lobbies. Tighten later via a cookie-confers-access pattern if abuse appears.
- [x] All `showLobby({listing})` Wayfinder calls in listings row/card components swapped to `showListing({listing})` (canonical destination, no redirect round-trip).
- [x] Tests: `LobbyPageTest` migrated from `GET /lobbies/{listing}` to `GET /listings/{id}` (team-play lobby UI) + a new describe block asserting the 301 redirect for the legacy URL. `LobbyInviteLinksTest` updated to assert 301 to canonical. `GameMatchTakeTest` gained a team-play guard test (no second match row, no taker hold, neutral redirect).

**Slice B.2 — FACEIT-grade center column (shipped 2026-06-12)**

The visual peak — 4-block center column between Team A and Team B, replacing the old 2-col main+chat layout. Reference image: `images-example/lobby.png` (FACEIT 5v5 lobby — sidebar Team A | center analytics column | sidebar Team B). Stakly mirrors the *structure* but the center column carries Stakly-unique info that FACEIT can't (money + trust signals), not per-map win-rate tables (too API-expensive, FACEIT does it better).

- [x] `LobbyResource.aggregates` block computed server-side:
  - **Money math** — `pot = team_size × 2 × stake_amount`, `fee = pot × fee_rate`, `winner_take_per_player = (pot − fee) / team_size`, `loser_loss_per_player = stake_amount`. Floats at the JSON boundary; Wallet stays BCMath-exact for any real money write.
  - **Per-team skill** — `{a, b}` each with `avg / min / max / count` over players with a non-null `platform_account.skill_rating`. `delta = |avg_a − avg_b|` + `delta_tone` tier (`≤50 even` / `≤150 mismatched` / `>150 stacked`). `null` when either side has no rated players.
  - **Per-team trust** — `{a, b}` with `avg_completion_rate` (mean of `seller_trust.rate_30d` across players with a track record), `settled_lifetime_sum`, `player_count`. Trust data reaches the resource via the controller attaching `SellerTrust::forBatch` to each participant's user model.
  - **Resource bug fix found during seeder verification**: `presentRoster()` was calling `->load('user.linkedAccounts')` on the live participants which re-fetched user models and wiped the controller-attached `seller_trust` attribute. Dropped the redundant load since the controller already eager-loads `lobbyParticipants.user.linkedAccounts`.
- [x] `components/lobby/center/` directory — 5 new files:
  - `money-block.tsx` — HERO. Pot in display-font gradient at top, split-tile "If you win" (success-green) vs "If you lose" (destructive) with per-player numbers, fee underneath.
  - `skill-block.tsx` — Avg ELO per team + min/max range pill + count, delta chip in the middle with tone-tier color (even/mismatched/stacked).
  - `trust-block.tsx` — Per-team avg 30-day completion + lifetime settled. Card border tints warning when team avg < 70%.
  - `coordination-panel.tsx` — State-driven: recruiting message / large amber 5-min countdown with destructive pulse in the last 30 s / locked panel with all FACEIT usernames + click-to-copy buttons (uses `useClipboard`) + party-invite link to faceit.com.
  - `center-column.tsx` — Composes the 4 blocks in vertical stack so the parent layout can place between rosters without dictating block order.
- [x] `components/lobby/team-slot-column.tsx` — extracted single-team slot column so the 3-col layout (Team A | Center | Team B) can place each team independently. Replaces the old monolithic `TeamRoster`.
- [x] `team-play-lobby-view.tsx` refactored to the 3-col `lg:grid-cols-[1fr_minmax(360px,400px)_1fr]` layout. Below lg the columns stack (Team A → Center blocks → Team B). Chat is FAB at every viewport (drops the sticky aside since the center column owns the desktop visual peak); `MobileChatTrigger` gained a `containerClassName` prop so the FAB stays visible at every breakpoint for team-play.
- [x] Dropped now-unused `LobbyStatusBanner` + `TeamRoster` components — coordination panel + `TeamSlotColumn` supersede them.
- [x] Tests (`tests/Feature/Lobby/LobbyAggregatesTest.php`, +14): money math at `team_size = 5` and `team_size = 2`; skill aggregates handle null ratings; all three delta tone tiers; trust aggregates report player counts + null completion when no track record; chess listings explicitly do NOT carry an `aggregates` block.

**Seeder pass — every lobby state browseable end-to-end (shipped 2026-06-12)**

So the user can manually walk through every block + state of the new lobby page without hand-wiring participants.

- [x] Marketplace pool bumped 20 → 25 users; every marketplace user now carries all three providers (Lichess + chess.com + FACEIT) + a faker-random FACEIT skill rating in [800, 2200]. testuser also gains FACEIT (`testuser-faceit`, ELO 1500).
- [x] Three CS2 5v5 lobbies seeded — `recruiting` (4/10 filled, 2 Ready), `ready_checking` (10/10 filled, 6 Ready, 5-min countdown active), `locked` (10/10 filled, all Ready'd, match transitioned to Pending). All produced by running the real actions (`CreateTeamPlayListingAction` → `JoinLobbyAction` × N → `ToggleReadyAction` × M) so the wallet ledger + paired GameMatch + provider snapshots wire up correctly. Participants drawn from disjoint slices of the marketplace pool so `JoinLobbyAction`'s already-in-lobby guard doesn't reject anyone.
- [x] `MatchHistorySeeder` switched from `limit(20)` to `orderBy('id')->get()` so every marketplace user gets settled history regardless of pool size. Trust signals on the lobby page now populate with real % + settled counts.

**Phase 3.1 Slice B — Polish round 1 (shipped 2026-06-12)**

First polish pass after the B.2 lands. The user walked through the lobby + listings pages and we batched the visual / UX gaps into a single round. Every item below shipped.

- [x] **CS2 locked to 5v5 only.** `Game::Cs2->allowedTeamSizes()` tightened from `[1, 5]` → `[5]`; `StoreListingRequest` now rejects 1v1 CS2 listings with `'CS2 listings only support team_size 5'`. `ListingSeeder` dropped the chess-style CS2 1v1 batch and seeds 3 extra thin recruiting CS2 lobbies so the marketplace tab has volume after the locked lobby hides. Three `ListingStoreTest` cases updated to use `team_size = 5 + creator_side`. Wingman `[2]` slot reserved for P5.
- [x] **View toggle persistence.** `useListingsView` now syncs local state to the Inertia-shared prop via `useEffect` whenever the prop changes — the old `useState(initial)` was only capturing the cookie on first render, so post-navigation re-mounts could keep stale state.
- [x] **`/listings/mine` is rows-only.** Dropped `<ListingsViewToggle>` + the grid branch + `MineGridSkeleton` from `pages/listings/mine.tsx`. Deleted the now-unused `mine-listing-grid-card.tsx`.
- [x] **Lobby page visual polish.**
  - Glow halo on Ready dropped — replaced with a subtle `border-success/40 bg-success/5` tint that matches the flat-bordered reference.
  - Slot card re-spec'd: smaller avatar (`size-10`) with crown badge on the creator, name + platform handle stacked in the middle, rating chip + Ready/Waiting pill stacked on the right, kick X moved to a small corner button so it stops fighting the rating chip for space.
  - Team header derives `Team {leaderUsername}` from the roster — creator on the creator's side, earliest `joined_at` on the opposing side; falls back to `Team A / Team B` when a side is empty.
  - Empty slot ("Join Team A") forced to `w-full` so the team column stops looking ragged.
  - Per-slot **Overall · Last 20 matches** stats line shipped via a new `App\Services\ParticipantStats` (sister to `SellerTrust`). Batched one-query aggregation across the 10 lobby participants → win rate + total matches + last-20 win rate. `LobbyResource::presentParticipant` exposes a `platform_stats` block; falls back to `'No matches yet'` for unseasoned players. Source is our own `game_matches` table — no FACEIT Data API touched.
- [x] **Chat is now a floating window.** Rebuilt `MobileChatTrigger` — replaced the Radix `Sheet` with a `motion.div` floating card anchored to bottom-right (`transformOrigin: 'bottom right'`), 380 × 520 px on desktop, full-width-minus-margin on mobile (max 80vh). FAB swaps `MessageSquare` → `X` when open + flips its label "Chat" → "Close". Header has its own close X. Existing `stakly:focus-chat` event handler + `ChatPanel bare` body preserved — only the outer shell changed.

**Phase 3.1 Slice B — Polish round 2 (shipped 2026-06-12)**

Second dogfooding pass on the lobby page. User flagged 6 issues after joining a seeded lobby; every item below shipped.

- [x] **Ready / Leave moved into the Money block.** First attempt folded the actions into the viewer's own slot card, but the Leave X collided with the ELO chip in the top-right corner of the card. Second attempt — settled on — placed the actions inside `money-block.tsx` directly under the "Platform fee" line. Frames the commit moment correctly ("$200 pot / +$36 win / −$20 lose / **[Ready up]**") and resolves the X-vs-ELO collision by reverting the slot card to display-only. Action row hidden once `lobby_state === 'locked'`. Per-state Ready label: "Un-Ready" (currently ready, outline variant), "Top up to ready" (balance < stake, disabled), "Ready up" (default, gradient). Leave label flips to "Cancel lobby" for the creator. Deleted `lobby-actions.tsx` (the original detached bar) along the way.
- [x] **Leave stays on the lobby page.** `LobbyController::leave` was returning `to_route('listings.index')` for both `'left'` and `'creator_cancelled'`; collapsed both branches to `back()` so the viewer stays on `/listings/{id}` (the lobby renders its terminal-state pages — cancelled / expired — fine in place). No tests asserted the old redirect destination.
- [x] **Chat FAB hidden for non-participants.** Backend policy already blocked sending for non-participants (404 from `MessageController::store`), but the FAB still rendered with the input field — confusing UX. Gated `MobileChatTrigger` render in `team-play-lobby-view.tsx` on `lobby.viewer?.is_participant`. Browsers viewing a lobby see roster + stats only; chat appears the moment they join. Treats the lobby chat as a private team-coordination room rather than a public comment thread.
- [x] **Slot stats reworked from "Overall / Last 20" → "Matches / Win rate / Completion 30d".** The old "Last 20" sample collapsed to identical-to-overall whenever a player had < 20 settled matches (the seeded case for nearly every test user). Dropped `last_played` + `last_win_rate` from `ParticipantStats` and the TS type. Merged `completion_rate_30d` into `LobbyResource::presentParticipant` via the already-attached `seller_trust.rate_30d`. `slot-card.tsx` `StatsLine` renders three small stat cells (left / center / right alignment) so each metric has its own visual home and identical-value collisions are impossible.
- [x] **Recruiting Coordination panel dropped.** The "Waiting for N more" card was redundant with the team-column fill counters (`4 / 5` on each column header already communicates the same info, louder). `CoordinationPanel` now returns null during recruiting; only renders for `ready_checking` (big countdown — earns the space) and `locked` (FACEIT names + click-to-copy — earns the space). `RecruitingPanel` function + `Users` icon import deleted. Considered hosting "Waiting for N more" inside the Skill matchup block, but rejected — skill matchup is about ELO comparison, not fill state; mixing them muddies both.
- [x] **Ready / Leave recoloured to traffic-light tones.** Final pass: Ready up = solid emerald (`bg-success text-white`, primary CTA tone), Leave = translucent rose outline (`border-destructive/40 bg-destructive/10 text-destructive`, matching the "If you lose" tile directly above). Un-Ready (already-ready toggle) drops to translucent emerald outline so it reads as "already in the success state, click to back out". Insufficient-balance state stays translucent amber. Visual rhyme with the win/lose tiles above the buttons reinforces the financial framing. Replaced the earlier ghost-variant attempt (which produced a smeared red-glow on hover from the ghost's white text-shadow conflicting with red text) — root cause was the ghost variant's `hover:[text-shadow:var(--text-shadow-glow)]` smearing the destructive override.

**Phase 3.1 Slice B — Polish round 3 (shipped 2026-06-12)**

Third dogfooding pass — extending the new lobby visual treatment beyond `/listings` onto the homepage so the marketplace surfaces stay consistent.

- [x] **Homepage "Ending soon" card brought to parity with the `/listings` grid card.** The homepage `FeaturedListings` was using the pre-M34-P3.1 `ListingCard` — no `GameChip` team-size pill, no `ReadyCheckBanner` countdown, no `RosterPreview` row, no region / languages chips, no footer divider. Swapped the import to `ListingGridCard` (the polished grid-view sibling) and deleted `listing-card.tsx` outright — it was a single-consumer leftover and keeping it invited future drift. The homepage now shows full team-play context (`CS2 · 5v5` chip, fill counter, ready-check pulse when active) on the ending-soon cards, matching what the user sees one click later on `/listings`.

**Phase 3.1 — Future enhancements (deferred — not blocking the slice)**

Items the lobby page should eventually have but that are scoped out of P3.1 because each one needs its own data plumbing (extra schema column / extra API call / extra cache) and the lobby ships cleanly without them. Slot into a follow-up phase once we have user demand or the data lands for another reason.

- [ ] **Country flags per player.** Render a small flag next to each player's name on the roster cards. Source: FACEIT's profile payload exposes `country` (ISO-3166 two-letter) — we can pull it during `FaceitProfileClient::fetch()` and persist it on `linked_accounts.country` (new column). Frontend renders via a flag-emoji helper or an SVG flag pack. Cheap to add but requires a migration + a backfill of existing linked accounts.
- [ ] **Per-player recent W/L form** (`W L W W L` style chips next to each player's slot card). Last 5 FACEIT matches for that player, fetched from `/players/{guid}/history?game=cs2&limit=5` and the winner field. Expensive at scale: 10 players × per-page-load = 10 FACEIT Data API calls; needs a per-player cache (1h TTL feels right) and an off-band refresher job so the lobby page itself never blocks on FACEIT. Useful as a momentum/risk signal — players on a 0-5 streak might tilt. Layout: small horizontal pill row of last-5 outcomes on each slot card, green dots for W, red for L.
- [ ] Real-time `lobby:{listing_id}` Reverb broadcasts replacing the 5 s polling loop (already-deferred-from-P3, surfaces here so we don't lose it).

**Phase 4 — FACEIT 5v5 verification extension (shipped 2026-06-13)**

The gating M34 work. Without P4, locked CS2 lobbies fell to ManualReview after the 4h auto-fetch timeout because the existing 1v1 pipeline only checked creator + taker GUIDs on opposing FACEIT factions. Phase 4 generalizes that to N-vs-N team rosters, extends the card schema with a `winning_team` + `winner_user_ids` shape, and fans out the wallet payout across the winning team.

Design decisions surfaced + locked before coding (see chat for plain-language reasoning):
- **API budget:** slot 0 per team + slot 1 fallback (max 4 history queries × 10 candidate fetches = 44 calls worst case; ~7–11 typical). Within `M35` self-throttle of 30/min, with `RateLimited` job middleware self-pacing during peak-hour bursts.
- **Strictness:** all 10 Stakly GUIDs must be on the FACEIT match for it to settle. Strict-no-partial. A missing player → no candidate → cron retries → 4h timeout → ManualReview. Safer to defer a real settlement than accept a wrong one.
- **Card payload:** additive (legacy `winner_user_id` + `winner_username` retained for 1v1 chess back-compat; new `winning_team` ('a' | 'b') + `winner_user_ids` (list<int>) carry team info).
- **Division remainder:** slot-0 winner gets the truncation remainder so the ledger-conservation invariant `sum(payouts) + fee == pot` holds exactly at micro-USDT precision.
- **Defensive winner-roster check:** `SettleFromCardAction` independently verifies every `winner_user_ids` member is a live participant on the `winning_team` side of the listing. Blocks malformed cards (admin tooling bugs, future bugs in card construction) from paying the wrong team.

What landed:
- [x] **`GameMatch::snapshotProviderUserIds(side, provider): list<string>`** — sister to `snapshotProviderUserId`. Returns all GUIDs for a (side, provider) ordered by `slot_index` ascending. Null `provider_user_id` entries (chess providers) filtered out.
- [x] **`AutoFetchFaceitGameJob::isOpposingTeamRosters()`** — strict: every team-1 GUID on one FACEIT faction AND every team-2 GUID on the other. Helper `allGuidsInRoster()` + `resolveWinningTeamIndex()` round out the team-aware primitives. Old 1v1 helpers (`isOpposingRosterPair`, `resolveWinnerSide`) deleted — the new team-aware ones cover both shapes.
- [x] **`AutoFetchFaceitGameJob::findOpposingTeamMatch()`** — replaces `findOpposingRosterMatch`. Interleaved seed strategy (slot 0 A → slot 0 B → slot 1 A → slot 1 B). New constant `MAX_SEED_DEPTH = 2` caps the budget.
- [x] **Card payload extended** — `buildEntry()` ships both legacy 1v1 fields AND new team fields. `winner_user_ids[0]` populates `winner_user_id` for back-compat with chess code paths.
- [x] **`App\Actions\GameMatch\SettleTeamMatchAction`** (new) — sibling to `SettleMatchAction`. Computes `pot = stake × team_size × 2`, `fee = pot × fee_rate`, `winnings = pot - fee`, `perPlayer = bcdiv(winnings, team_size, 6)`, `remainder = winnings - perPlayer × team_size`. Fans out `team_size` `Wallet::payout()` calls with unique reference IDs `"match-payout:{matchId}:player-{userId}"` + one `Wallet::fee()`. Slot 0 gets the remainder. In-action conservation assertion catches any future math regression at settle time.
- [x] **`SettleFromCardAction` extended** — branches on `isTeamPlayCard()` (team_size > 1 AND card carries `winning_team` + `winner_user_ids`). Team path calls `resolveTeamWinnersFromCard()` which runs the defensive roster check: every `winner_user_ids` member must be a live `lobby_participants` row on the `winning_team` side; strict containment (not subset, not superset). Returns null on any mismatch → settle no-ops → admin / dispute path takes over. New `notifyTeamWinLoss()` fans out `MatchSettledNotification` across the full team roster (5 'won' + 5 'lost').
- [x] **Tests** (`tests/Feature/Jobs/AutoFetchFaceit5v5Test.php`, +9 tests):
  - Happy path: Team A wins, 5 payouts of $180 + $100 fee, conservation `sum(payouts) + fee == pot` (1000 USDT).
  - Per-user payout correctness: the 5 credited user IDs match exactly the side-A live participants.
  - Strict roster check (4 of 5 Team A on faction1, slot 4 replaced by smurf): no candidate, match stays Pending, 0 payouts.
  - Same-team queue (all 10 Stakly players + 1 stranger on the same FACEIT match): no candidate.
  - AC-incomplete on 5v5: no settle, no payouts.
  - Idempotency: running the job twice produces exactly 5 payouts (not 10).
  - Team B winning path mirrors Team A.
  - Card payload shape: `winning_team`, `winner_user_ids`, legacy `winner_user_id` = first winner.
  - Defensive check rejects a malformed card whose `winner_user_ids` are Team B players but `winning_team` is 'a' → no payout, match stays Pending.

1v1 FACEIT settlement (chess and the now-unreachable CS2 1v1 path) still works — verified by 93 existing FACEIT tests + 103 settle tests passing through the refactor. Full suite: **1450/1450 Pest tests** pass. All 4 CI gates clean (Pint / Prettier / ESLint / TypeScript).

**Phase 5 — CS2 create-form team-play extension + 2v2 Wingman enable + private-listing post-create UX + chat-leak follow-up** _(shipped 2026-06-13)_

Discovered during the slice scoping that the create form was functionally broken for CS2 — it didn't ship `team_size` / `creator_side` / `is_public` and validation rejected the (missing-team_size) payload. So Wingman wasn't a one-line enum flip; it was coupled to the form work. Absorbed both into a single phase along with the post-create UX polish + the open chat-leak follow-up.

- [x] **Chat-leak follow-up.** `ListingController::showTeamPlay` was always shipping up to 200 `messages.data` to the Inertia payload regardless of viewer participation — non-participants could read chat from the page JSON. Fixed by gating the messages build on viewer being a live participant (re-uses the `$userIds` already computed for trust/stats — no extra query). Strangers, guests, and kicked users get `collect()`. +5 Pest cases in `LobbyPageTest::"GET /listings/{id} chat messages payload (team-play)"`.
- [x] **Slice 1 — Server props.** `ListingController::create`'s `requirementsByGame` now carries `allowed_team_sizes` per game, sourced from `Game::allowedTeamSizes()`. TS `ListingCreateProps` updated. +1 test asserting the prop shape per game.
- [x] **Slice 2 — Frontend form fields.** `team_size` / `creator_side` / `is_public` added to `useForm` shape. Three new `FormSection`s in `pages/listings/create.tsx`: Format (`ToggleGroup` segmented control, hidden when `allowed_team_sizes.length === 1`), Your side (Team A / Team B toggle, only when `team_size > 1`), Visibility (Public / Private, always visible). All controls Stakly-skinned via a shared `SEGMENTED_ITEM_CLASS` constant (`h-12 rounded-xl border border-border/60 data-[state=on]:border-primary data-[state=on]:bg-primary/10 data-[state=on]:text-foreground`). `handleGameChange` resets team-size + creator-side to the new game's defaults; `is_public` is the creator's choice and persists across game switches. Submit-button label adapts (`"Open lobby"` for team-play, `"Create listing"` for 1v1). No new server tests — existing CS2 store tests + `LobbyInviteLinksTest` already cover every payload shape.
- [x] **Slice 3 — Wingman enable + seeder.** `Game::Cs2->allowedTeamSizes()` flipped `[5]` → `[2, 5]`. Frontend Format picker auto-renders both options (zero UI change required). `ListingSeeder` bumped main pool from 25 → 29 users; `seedTeamPlayLobby` parametrized with `int $teamSize = 5` default; one fresh `recruiting`-state 2v2 lobby seeded so the marketplace + lobby surfaces have non-5v5 content. +2 Pest tests in `Wingman2v2Test` driving the full Create → Join × 3 → Ready × 4 → `SettleTeamMatchAction` flow at `team_size = 2`, asserting 2 × $180 payouts + $40 fee + ledger conservation (`sum(payouts) + fee === pot`).
- [x] **Slice 4 — Private-listing post-create UX.** Team-play creators now redirect to `route('listings.show', $listing)` (the lobby page) instead of `/listings/mine`, because they're auto-soft-joined into slot 0 by `CreateTeamPlayListingAction` and the lobby is the right next stop for them. New `components/lobby/lobby-invite-banner.tsx` — owner-only banner mounted above the 3-col grid in `TeamPlayLobbyView`, rendering only when `lobby.invite_token !== null` AND `lobby_state ∈ {recruiting, ready_checking}` (the `LobbyController` invite endpoint 404s once the lobby locks). Banner uses Stakly's `border-glow` decorative border + the existing `useClipboard` hook + `Copy` / `Check` icons matching `CoordinationPanel`'s username-copy pattern. URL built via Wayfinder's typed `lobbies/invite` helper. SSR-safe via `useEffect`-deferred `window.location.origin` read. +1 Pest test asserting the redirect target for team-play store. Two existing CS2 store-tests updated to expect the new redirect destination.

Total across Phase 5: +9 Pest tests (1468 → 1477). All 4 CI gates clean.

**CS2 lobbies now fully creatable through the public UI in both 5v5 and 2v2 modes.** M34 fully shipped.

**Phase 6 — Dispute + cancellation for team matches** _(in flight 2026-06-14)_

After M34 P3.2 / P5 shipped, team-play matches have **no dispute or cancellation affordances once the lobby locks**, AND the existing backend Actions are 1v1-shaped. The frontend `match/show.tsx` page has the buttons but renders broken UI for `team_size > 1`. M34 launched without this so CS2 lobbies could be created and settled end-to-end on the happy path; closing the gap is the last remaining team-play work before CS2 launch.

**Gaps the slice must close:**

- Frontend lobby view (`team-play-lobby-view.tsx`) has zero dispute / cancellation buttons.
- Frontend match page (`pages/match/show.tsx`) renders team matches with 1v1-shaped UI: `pot = stake × 2` (should be `× team_size × 2`), `creator/taker` binary roles, single-winner messaging, no team rosters.
- Backend `GameMatchPolicy::isParticipant()` only checks `taker_user_id || listing.user_id` — team members other than the creator can't dispute or accept-cancel under the current gate.
- Backend `AcceptCancellationAction::refundBothStakes()` releases only 2 stakes — for a 5v5, 8 players' escrow is forfeit and the conservation invariant breaks (`sum(refunds) ≠ sum(holds)`).
- Backend `OpenDisputeAction::notifyOpponent()` and `RequestCancellationAction` / `Accept` / `Reject` notify only one player on each side — team-mates get no signal.
- `GameMatchResource` ships `creator` + `taker` only; team rosters aren't on the wire for the frontend to render.

**Design questions to resolve before coding** _(recommendations inline — confirm or pick differently)_:

1. **Surface for Pending team matches.** Today `/matches/{id}` is the post-lock destination, but the page is 1v1-shaped. Two paths:
    - **A.** Make `/matches/{id}` team-aware — build team rosters / team pot / "your team won" UX on the existing page. Cleaner separation (listing = lobby phase, match = post-lock), bigger frontend lift.
    - **B.** Keep team matches at `/listings/{id}` post-lock — extend `team-play-lobby-view.tsx` with a Pending-state UI. Faster, but conflates lobby + match surfaces; polling / settlement summary / banners would need duplicates.
    - **Recommendation: A.** The match page already owns post-lock semantics (polling, settlement summary, banners, FAQ); the lobby is the wrong place to host them. Cost is real, but architecturally honest.
2. **Who can request cancellation?** Any participant, or captain-only? **Recommendation: any participant** — matches 1v1 semantics, no coordination bottleneck. Other team has to accept anyway.
3. **Who accepts cancellation?** One opposing-team member, or unanimous? **Recommendation: one opposing-team member** — same as 1v1; faster; no AFK deadlock. Risk: a single team-mate could greenlight a cancel that costs their team a winnable match. Worst case is social, not financial (everyone refunded). Flag if you'd prefer unanimous.
4. **Who can open a dispute?** **Recommendation: any participant** — no reason to gate differently from 1v1.
5. **Notification fan-out.**
    - Cancellation requested → every opposing-team participant.
    - Cancellation accepted → every participant (refund signal for all).
    - Cancellation rejected → original requester only (1v1 semantics).
    - Dispute opened → every participant on both teams.
6. **Cooldown.** 30-min rejection cooldown stays per-player (current `cancellation_requested_by` keying just works). If team-relay-request becomes an abuse vector, tighten later.

### Slices

- [x] **Slice A — Policy + channel** _(shipped 2026-06-14)_. `GameMatchPolicy::isParticipant()` branches on `Listing::isTeamPlay()` and reads the live `LobbyParticipant` roster (`kicked_at IS NULL`) for team matches in any status — `LobbyFilling` / `Pending` / `Disputed` / `ManualReview` / `Settled` / `Cancelled` share one check. New `canRespondToCancellation` gate blocks the requester's team-mates from accepting on the team's behalf (preserves mutual-cancellation premise). `MatchChannel::join` mirrors the policy. `GameMatchController` listing column whitelist gained `team_size` so the policy doesn't N+1 / get null. **Source of truth picked: live `LobbyParticipant` over `MatchProviderSnapshot`** — direct `user_id`, already eager-loaded by most match queries; snapshots key on username + per-(side, slot, provider) which is heavier for an existence check.
- [x] **Slice B — `AcceptCancellationAction` refund fan-out** _(shipped 2026-06-14)_. Branches on `isTeamPlay()`. Team path iterates `LobbyParticipant::live()->whereNotNull('stake_held_at')` and `Wallet::release` per user with idempotency ref `cancel-refund:{match_id}:{user_id}`. 1v1 path unchanged (legacy refs `cancel-refund-creator/taker:{match}`). Dropped the draft's in-action conservation assertion — Wallet's row-lock + negative-balance throw already prevents the failure modes a check would catch; tests assert end-to-end balance restoration.
- [x] **Slice A + B tests** _(shipped 2026-06-14)_. `tests/Feature/GameMatch/TeamMatchDisputeCancellationTest.php`, +18 cases / +144 assertions. Policy gates (8 cases incl. same-team-accept block), `MatchChannel` mirror (3 cases), refund fan-out (4 cases incl. kicked-skip + idempotency + per-user ref shape), HTTP wiring (2 cases), 1v1 regression (1 case). Full suite **1477 → 1495** all green.
- [x] **Slice C — Notification fan-out** _(shipped 2026-06-14)_. New `App\Services\MatchParticipants` helper (mirrors `SellerTrust` / `ParticipantStats` primitive-service pattern) with `all()` / `opposing()` / `allExcept()` static methods so the 4 Actions don't drift on participant-resolution logic. `OpenDisputeAction` → fan out to every participant except opener (9 of 10 on 5v5). `RequestCancellationAction` → fan out to opposing team only (5 of 10). `AcceptCancellationAction` → fan out to every participant except accepter (9 of 10 — refund signal needs to reach everyone). `RejectCancellationAction` unchanged (only original requester, per design Q#5). +7 Pest cases (4 team-fan-out + 3 1v1 regressions); full suite **1495 → 1502** all green.
- [x] **Slice D — `GameMatchResource` team rosters** _(shipped 2026-06-14)_. New `team_a` / `team_b` arrays (each entry: `{user_id, username, name, avatar_thumb_url, slot_index}`) + `winning_team: 'a' | 'b' | null` on the resource — gated on `Listing::isTeamPlay()` AND `lobbyParticipants` relation loaded via `mergeWhen`, so list contexts (`/matches`) that don't eager-load aren't forced to ship rosters. `listing.team_size` added to the listing block (drives the frontend branch). `GameMatchController::show` eager-loads `listing.lobbyParticipants.user.media` so the avatar accessor doesn't N+1. **1v1 chess shape preserved verbatim** — `team_a` / `team_b` / `winning_team` are absent (not null) from the JSON payload, `creator` + `taker` still carry the two participants. `winning_team` derived from looking up `winner_user_id` in the already-loaded roster (zero extra query). TS `Match` interface extended with optional `team_a?` / `team_b?` / `winning_team?` + new `TeamMatchPlayer` shape. +6 Pest cases (5v5 roster contents + slot ordering, Wingman 2v2 shape, kicked-filter, winning_team after Settled, 1v1 omits team_*, 1v1 Settled still omits team_*). Full suite **1502 → 1508** all green; TypeScript clean.
- [x] **Slice E — Team-aware `match/show.tsx` + lobby → match navigation** _(shipped 2026-06-14)_. `pages/match/show.tsx` branches on `match.listing.team_size > 1` to a new `TeamMatchView` component (1v1 chess page intact, extracted into `ChessMatchShow`). Three new components: `team-match-view.tsx` (composer, mirrors 1v1 rhythm), `team-rosters.tsx` (2-column Team A / Team B with winner crowns + viewer "you" badge + initials fallback for missing avatars), `team-settlement-summary.tsx` (per-player payout math + "your team won/lost" framing). `cancellation-request-banner.tsx` + `cancellation-summary.tsx` updated with team-aware requester lookup (across `team_a` / `team_b` rosters) + same-team viewers see a passive "your team-mate requested" banner instead of Accept/Decline. `RequestCancellationButton` + `OpenDisputeButton` got optional `teamSize` prop to swap dialog copy ("All stakes / opposing team" instead of "Both stakes / your opponent"). `CoordinationPanel`'s `LockedPanel` gained a "View match page →" CTA so locked-lobby viewers don't stare at stale lobby UI. TypeScript clean; full Pest suite still **1508 / 1508**.
- [x] **Slice F — Admin / draw team-awareness + end-to-end test** _(shipped 2026-06-14)_. Audit found `SettleDrawMatchAction` + `AdminSettleToWinnerAction` + `AdminSettleDrawAction` all 1v1-shaped — without fixing, admin couldn't resolve a disputed CS2 match (would only pay slot-0 winner, leave 8 stakes orphaned), and auto-fetch draws would break team payouts. **All three absorbed into Slice F.** `SettleDrawMatchAction` branches on `isTeamPlay()` and fans refunds across live `LobbyParticipant` rows with per-user ref `match-draw:{match}:{user}`. `AdminSettleToWinnerAction` takes a representative winner user and derives the full winning roster from `LobbyParticipant` (live + on winner's side, ordered by slot_index) → calls `SettleTeamMatchAction`; notifications fan out to all 10. `AdminSettleDrawAction` fans notifications via `MatchParticipants::all()`. Filament `ViewGameMatch` shows "Settle to Team A / Team B / Refund all" buttons for team matches (1v1 keeps "Settle to creator / taker / Refund both"); the team-A button passes a slot-0 side-A user as the representative winner. +11 Pest cases: 5 admin-settle team (Team A win + per-player ref shape + notifications + Team B path + non-participant rejection), 2 admin-draw team (10 refunds + 10 notifications), 3 settle-draw-direct team (refunds + ref shape + idempotency), 1 end-to-end dispute → admin settle → ledger-conservation invariant. Full suite **1508 → 1519** all green.

### Not in P6

- Real-time broadcasts on cancellation / dispute (existing `MessageSent` + `NotificationProvider` reload covers it).
- Captain / leader role beyond what's already in M34.

**Phase 7 — Lobby header bar (FACEIT-style mode / teams / countdown / share)** _(in flight 2026-06-14)_

Replaces the current `2 v 2 lobby` / `5 v 5 lobby` title block on the team-play lobby view (`pages/listings/show.tsx` → `TeamPlayLobbyView`) with a unified FACEIT-style header bar. Surfaced during dogfooding — the existing title is plain text and wastes the screen real-estate above the 3-col grid. Also **folds the ready-check countdown out of `CoordinationPanel` into the header**, so the center column doesn't carry a duplicate countdown when the header is showing one for the same state.

Reference: FACEIT match overview header (team_a name + leader avatar | mode chip + countdown + format label | leader avatar + team_b name + Share + 3-dots). Stakly deviates per design questions answered (see below).

### Design decisions locked

- **No scores.** FACEIT shows live `0 - 0` match score; we have no live-score data from FACEIT, so the zeros would lie. Drop the score columns entirely. Re-add only when (if) real-time FACEIT score streaming lands.
- **Team labels = "Team {leaderUsername}".** Mirrors the existing `slot-card.tsx` pattern from M34 P3.1 polish round 1 (creator on creator's side, earliest `joined_at` on opposing side; falls back to `Team A / Team B` when a side is empty). Avoids inventing a "team name" concept we don't have.
- **State-aware center countdown** — one canonical countdown that morphs with `lobby_state`:
    - `recruiting` → listing's 24h fill timeout (`listing.expires_at`).
    - `ready_checking` → 5-min ready-check deadline (`lobby_ready_check_deadline`). **This replaces the big countdown currently in `CoordinationPanel::ReadyCheckingPanel` — drop that block.**
    - `locked` → 4h auto-fetch deadline (`match.created_at + 4h`); after that, `ResolveMatchTimeoutAction` flips to `ManualReview`.
    - `cancelled` / `expired` → no countdown (terminal — show the status instead).
- **Share button.** Copy lobby URL + toast confirmation on desktop; native Web Share API on mobile (`navigator.share` if available, fall back to copy). For private lobbies, the URL is the canonical `/listings/{id}` (the invite-token URL via `LobbyInviteBanner` stays as the owner-only banner above).
- **No three-dots menu.** Every action that would live there already has a primary surface elsewhere (Share covers copy URL; Cancel/Leave live in Money block; Open dispute / Request cancellation live on `/matches/{id}`; "View match page →" is a CTA in `CoordinationPanel` post-lock; Report user/lobby is parked as M21 work). Revisit when M21 lands an actual abuse-report flow.
- **Placement.** Replaces the existing title block (`<header>2 v 2 lobby</header>` + "Hosted by … · Any skill" sub-line + chips). Sits below `LobbyInviteBanner` (owner-only) and above the 3-col grid. The "Hosted by" + skill meta moves into the header too (right side of center column, small text).
- **Lobby only.** Match page (`/matches/{id}`, `TeamMatchView` from M34 P6 Slice E) keeps its own header. Adding a second match header there would just duplicate the status badge + timer that's already in `TeamMatchView`. Revisit if you want unified visual identity across both surfaces later.

### Slices (tentative)

- [x] **Slice A — Resource audit + `match_deadline_at`** _(shipped 2026-06-14)_. `LobbyResource` gained `match_deadline_at` — ISO of `match.created_at + match_confirmation_timeout_hours` (gated on `MatchStatus::Pending` so the deadline only surfaces while the 4h confirmation window is ticking; null for `LobbyFilling`/recruiting/ready_checking). `ListingController::showTeamPlay` eager-load select widened by `created_at`. TS `Lobby` interface extended. +2 Pest cases (`LobbyPageTest`: null pre-lock, `match.created_at + 4h` once locked).
- [x] **Slice B — `components/lobby/lobby-header.tsx`** _(shipped 2026-06-14)_. Pure presentational component. Left/right: leader avatar + "Team {leaderUsername}" + fill counter. Center: game · NvN · region pill + state-aware countdown (HH:MM:SS for ≥1h, M:SS for <1h; gradient-primary text for the locked match deadline, warning for ready-check, foreground for recruiting; pulses destructive in the final 30s of ready-check) + "X USDT per player" meta. Right: round Share button — uses `navigator.share` if available, falls back to copying the current URL. Terminal states (`cancelled`/`expired`) render a muted status pill instead of a countdown. Shared `pickLeader()` + `leaderLabel()` extracted to `components/lobby/lobby-leader.ts` so the slot-column and header agree on the leader-derivation rule.
- [x] **Slice C — Wire header + drop old title + drop ReadyCheckingPanel** _(shipped 2026-06-14)_. Mounted `<LobbyHeader>` in `TeamPlayLobbyView` below the invite banner and above the 3-col grid. Deleted the old `<header>` block (`2 v 2 lobby` title + "Hosted by · skill" subline + GameChip + invite-only pill) from `pages/listings/show.tsx`'s `TeamPlayBranch`. `CoordinationPanel` shrunk to locked-only (FACEIT usernames + party-invite + "View match page →" CTA); `ReadyCheckingPanel` + `BigCountdown` deleted — the header owns ready-check countdown now.
- [x] **Slice D — Tests + lint + Pint** _(shipped 2026-06-14)_. Slice A resource tests pin the new field across recruiting + locked states. Full suite **1521 → 1523** green (1523 tests / 6041 assertions). Pint clean. TS `tsc --noEmit` clean. Manual dogfood walk-through across the 4 live states is the user's next step.

### Not in P7

- Match-page header redesign (out of scope per design Q7 — `/matches/{id}` keeps its existing header).
- Three-dots menu — parked until M21 abuse-report flow exists.
- Live score data — depends on FACEIT real-time API integration that doesn't exist.
- Team-name customisation (custom team names instead of "Team {leader}") — feature creep; can't see a clear win.

**Phase 8 — Team match-page polish + lobby-lock notification** _(in flight 2026-06-14)_

Surfaced during dogfooding: the match-page `Rosters` block built fast during P6 Slice E reads bland next to the lobby's polished `slot-card.tsx`; team labels still read "Team A" / "Team B" instead of "Team {leader}"; and there's no notification when the lobby locks → match starts. Three slices.

### Slices (tentative)

- [x] **Slice A — Match-page roster polish** _(shipped 2026-06-14)_. `GameMatchResource::buildRoster` extended with `skill_rating` (snapshot from `linkedAccounts` on the listing's platform) + `platform_stats` block (`total_matches` / `win_rate` / `completion_rate_30d`). `GameMatchController::show` eager-loads `linkedAccounts` and batches `SellerTrust::forBatch` + `ParticipantStats::forBatch` over the live roster (mirrors `ListingController::showTeamPlay`). `team-rosters.tsx` rebuilt to mirror lobby `slot-card.tsx`: size-10 avatar + leader crown on slot 0, name + platform handle, rating chip, three-cell stats line, viewer pink tint, winner/loser color treatment. TS `TeamMatchPlayer` extended. +1 Pest assertion on `TeamMatchResourceTest`.
- [x] **Slice B — "Team {leader}" labels on match page** _(shipped 2026-06-14)_. New shape-agnostic `lib/team-leader.ts` exporting `pickTeamLeader()` + `teamLabel()` over `TeamMatchPlayer`. Slot 0 is the leader by backend convention (creator on creator's side, earliest joiner on opposing side). Wired into `team-rosters.tsx` column headers, `team-settlement-summary.tsx` winning-team label, and the match-page hero "You're on :team" subline. Column header style switched from `uppercase tracking-[0.18em]` to `font-display tracking-wide` (mixed case) so usernames stay readable. 1v1 chess unaffected.
- [x] **Slice C — Lobby-lock notification + sound preference** _(shipped 2026-06-14)_. New `TeamMatchStartedNotification` extending `PlayerNotification`. Dispatched from `LobbyLockAction` to every locked-in participant via `MatchParticipants::all($match)` once the lock transaction commits (a rollback does not broadcast). Registered as a configurable event type with sound-default ON (matches `listing_taken`'s rationale — high-attention moment, money already staked). `/settings/notifications` page exposes the new toggle in the "Match activity" group. +2 Pest cases in `ToggleReadyTest` pinning the fan-out + the no-fire on a non-final Ready. Full suite **1531 → 1533** green.

### Not in M34

- Unifying chess (1v1) to the lobby model — separate decision; current `TakeListingAction` flow keeps working for `team_size = 1`. Revisit only if there's a UX reason (e.g. pre-match chat for chess).
- Captain mode / explicit team-leader role beyond the "lobby owner can kick" mechanic.
- Spectator slots (watch-only joins).
- Mid-match player replacement (a player drops, another fills in). FACEIT doesn't natively support this for our verification model.
- Cross-server roster verification (FACEIT party invite tracking). We rely on the verified-match-record approach — if all 10 end up in the same FACEIT match with correct factions, that's our proof.
- Anti-collusion / match-fixing detection beyond the existing skill-range gate. Future M-something.

---

## M35 — Outbound third-party API rate-limit audit

Sweep every outbound HTTP integration in the codebase and confirm each has:

1. **Client-side self-throttle** — Laravel `RateLimiter::for(...)` + `RateLimited` job middleware at a conservative cap (default 30 req/min when the real provider limit isn't documented).
2. **429 response-header handling** — via the existing `App\Services\Provider\RateLimitHeaderParser` (`Retry-After` / `X-RateLimit-Reset`).
3. **Per-provider `App\Services\Provider\ProviderCircuitBreaker`** — so a sudden provider outage doesn't melt the queue.
4. **Explicit timeouts** — every outbound HTTP call should set `->timeout(...)` and `->connectTimeout(...)`. Default Guzzle has no timeout — a hung third-party server holds the worker indefinitely without it.

Goal: zero production 429 incidents and zero settlement freezes from breaker trips.

### Scope

- **In scope**: `ChessComGameClient` + `ChessComProfileClient`, `LichessGameClient` + `LichessProfileClient`, `FaceitGameClient` + `FaceitProfileClient`, `FetchLinkMetadataJob` (chat link previews — different shape, see Phase 0 notes).
- **Out of scope (for now)**: OAuth callback flows (user-driven, low volume); mail providers (still on Mailpit locally — revisit when wiring Postmark / Resend / SES); chain integration (M9 paused).

### Phases

**Phase 0 — Inventory + audit table** _(read-only — shipped 2026-06-11)_

- [x] Enumerated every `Http::` callsite + every Provider client in `app/Services/Provider/` + the link-preview job.
- [x] Per-provider table with current vs target state + gap diff (below).
- [x] Flagged the link-preview fetcher (arbitrary URLs, not a single provider — needs a global throttle if anything, not per-provider).

### Phase 0 — audit findings (2026-06-11)

Read across `app/Services/Provider/` + `app/Jobs/AutoFetch*GameJob.php` + `app/Jobs/FetchLinkMetadataJob.php`. Symbol key: ✅ = present; ❌ = missing; (job) = handled at the dispatched job's layer, not at the client; — = not applicable.

**Game clients (auto-fetch path — money-touching).** All three game clients share the same shape and same gap.

| Client / Job | Self-throttle | Circuit breaker | 429 handling | Retry policy | Timeout |
|---|---|---|---|---|---|
| `ChessComGameClient` + `AutoFetchChessComGameJob` | ❌ | ✅ (job uses `ProviderCircuitBreaker`) | ✅ (`classifyResponseError` → `RateLimitedError` + `RateLimitHeaderParser`) | ✅ (`$tries=7`, `backoff()=[5,15,30]`, no_match chain `[5,15,45]`, `retryUntil()`) | ✅ 10s |
| `LichessGameClient` + `AutoFetchLichessGameJob` | ❌ | ✅ | ✅ | ✅ (`$tries=4`, `backoff()=[5,15,30]`) | ✅ 10s |
| `FaceitGameClient` + `AutoFetchFaceitGameJob` | ❌ | ✅ | ✅ | ✅ (`$tries=7`, same shape as chess.com) | ✅ 10s |

**Profile clients (signup / verification path — also money-relevant, looser handling).** Used by `VerifyLinkedAccountAction` + `LinkFaceitAccountAction` synchronously from controllers — no job-layer wrapping.

| Client | Self-throttle | Circuit breaker | 429 handling | Retry policy | Timeout |
|---|---|---|---|---|---|
| `ChessComProfileClient` | ❌ | ❌ | ❌ (no `classifyResponseError` — only `successful()`-check; 429 lumped into generic `TransientProviderError`) | ❌ (called inline) | ✅ 10s |
| `LichessProfileClient` | ❌ | ❌ | ❌ same | ❌ | ✅ 10s |
| `FaceitProfileClient` | ❌ | ❌ | ❌ same | ❌ | ✅ 10s |

**Other outbound HTTP (different shape).**

| Job | Self-throttle | Circuit breaker | 429 handling | Retry policy | Timeout |
|---|---|---|---|---|---|
| `FetchLinkMetadataJob` (chat link previews) | ❌ | — (calls arbitrary URLs, no per-provider notion) | — | ✅ `$tries=1` (intentional — no point retrying a dead URL) | ✅ 30s |

All gaps above closed by Phases 1–3 below. Profile-client self-throttling was intentionally left out — they're called synchronously from controllers, not via queued jobs, so `RateLimited` middleware doesn't fit; revisit with controller-level `throttle:` if a real abuse vector surfaces.

**Phase 1 — chess.com remediation + shared infra** _(commit: `feat(m35-p1): chess.com self-throttle + profile-client upgrade`)_

- [x] `RateLimiter::for('chess-com-api', ...)` defined in `AppServiceProvider::registerProviderRateLimiters()`. Cap value from `services.chess_com.requests_per_minute` (default 30; `CHESS_COM_REQUESTS_PER_MINUTE` env override).
- [x] `RateLimited::class` job middleware applied to `AutoFetchChessComGameJob` via its `middleware()` method. `$tries` widened from 7 → 15 to absorb plausible burst-moment release counts (each release consumes an attempt without running the handler); `retryUntil()` remains the real safety net.
- [x] `ChessComProfileClient` brought up to `ChessComGameClient` parity (Tier 2 from Phase 0): `classifyResponseError` mirrors the game-client mapping (429 → `RateLimitedError` with `retryAt` from `RateLimitHeaderParser`, 5xx → `TransientProviderError`, 4xx-other → `PermanentProviderError`), 404 still throws `ProfileNotFoundException` (separate hierarchy; counts as breaker success — defined-answer). Constructor now takes `ProviderCircuitBreaker`; every HTTP call records success / failure on the shared breaker keyed on `LinkedAccountProvider::ChessCom`.
- [x] Tests: 10 new `ChessComProfileClientTest` cases (happy path, missing-location 200, 404, 429 + retryAt parsing, 5xx, 4xx-other, breaker trip on 5xx/connection-failure, breaker stays closed on 200/404). 2 new throttle-wiring tests in `AutoFetchChessComGameJobTest` (middleware presence, cap-from-config). Existing `AutoFetchChessComAuditTrailTest` updated for the new `$tries=15` budget.

**Phase 2 — Lichess remediation** _(commit: `feat(m35-p2): Lichess self-throttle + profile-client upgrade`)_

- [x] `RateLimiter::for('lichess-api', ...)` in `AppServiceProvider`. Cap value from `services.lichess.requests_per_minute` (default **60** — Lichess documents 20 req/sec = 1200/min, so 60/min has a 20× safety margin while being well above realistic load).
- [x] `RateLimited::class` middleware applied to `AutoFetchLichessGameJob`. `$tries` widened from 4 → 12 to absorb throttle releases.
- [x] `LichessProfileClient` brought up to `LichessGameClient` parity: `classifyResponseError` mirrors the game-client mapping (429 → `RateLimitedError` with `retryAt`, 5xx → `TransientProviderError`, 4xx-other → `PermanentProviderError`), 404 still throws `ProfileNotFoundException` and counts as breaker success. Constructor takes `ProviderCircuitBreaker`; records success / failure on `LinkedAccountProvider::Lichess`.
- [x] Tests: 10 new `LichessProfileClientTest` cases (happy path, missing-profile 200, 404, 429 + retryAt, 5xx, 4xx-other, breaker trip / no-trip / 404-defined-answer / connection-failure). 2 new throttle-wiring tests in `AutoFetchLichessGameJobTest`. Existing `AutoFetchLichessAuditTrailTest` updated for `$tries=12`.

**Phase 3 — FACEIT remediation** _(commit: `feat(m35-p3): FACEIT self-throttle + profile-client upgrade`)_

- [x] `RateLimiter::for('faceit-api', ...)` in `AppServiceProvider`. Cap value from `services.faceit.requests_per_minute` (default **30** — undocumented provider quota; CLAUDE.md conservative-cap rule applies). Tune via `FACEIT_REQUESTS_PER_MINUTE` once we observe real headroom or 429s.
- [x] `RateLimited::class` middleware applied to `AutoFetchFaceitGameJob`. `$tries` widened from 7 → 15 to absorb throttle releases.
- [x] `FaceitProfileClient` brought up to `FaceitGameClient` parity: `classifyResponseError` mirrors the game-client mapping (429 → `RateLimitedError` with `retryAt`, 5xx → `TransientProviderError`, 4xx-other → `PermanentProviderError`), 404 still throws `ProfileNotFoundException` and counts as breaker success. Constructor takes `ProviderCircuitBreaker`. Graceful-null path (missing API key) preserved — returns null without making a request and without touching the breaker (no API call means no health signal).
- [x] Tests: 12 new `FaceitProfileClientTest` cases (happy path, missing CS2 block, missing API key dev path, 404, 429 + retryAt, 5xx, 4xx-other, breaker trip / no-trip / 404-defined-answer / connection-failure, Authorization Bearer header). 2 new throttle-wiring tests in `AutoFetchFaceitGameJobTest` (FACEIT job test had no pinned `$tries` assertions to update).

**Phase 4 — Telemetry pass _(skipped 2026-06-11)_**

Decided to skip after Phase 0 audit — existing `PipelineHealth` widget coverage is sufficient for the current pipeline; throttle-wait visibility can land later as a small follow-up if we see real production caps being hit.

### Not in M35

- Throttling our own internal APIs (Stakly UI calling Stakly backend). Different concern; covered by the existing `throttle:...` middleware on auth routes.
- Inbound rate-limiting of webhooks. Already covered per-receiver (`throttle:60,1` on `/webhooks/faceit`).
- Token-bucket vs leaky-bucket optimization decisions. Laravel's `RateLimiter` is fixed-window; that's fine for our use case. Revisit only if production traffic exposes a real problem.

---
