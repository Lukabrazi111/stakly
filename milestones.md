# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M8 all phases, M10, M11, M12 all phases, M14 all phases, M15 all phases, M16 all phases, M17, M18, M19, M22, M23, M24, M25, M26 all phases, M27 all phases, M29 all phases, M30 all phases, M31 all phases, M32 all phases, M34 all phases, M35 all phases, M36 all phases, M37 all phases, M38 P1–P3 (paused at P4), M39 all phases, M40 all phases). **Parked milestones** (work that isn't being picked up right now) also live in the archive — currently M13. This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Recently shipped** — full per-milestone summaries live in `milestones_archived.md`. Latest: **M38 P1–P3** (Redis for queue/cache/sessions + `redis→array` failover + admin-gated Horizon), **M15** (multi-game / CS2 via FACEIT), **M39** (match-page pipeline fixes — ManualReview chat + single-source deadline), and **M40** (create-listing form UI polish — Bybit-inspired) — through 2026-06-26.

**Active / upcoming:**

- **M38 — Redis for queue, cache & sessions (launch-readiness)** — **P1–P3 shipped + parked (archived 2026-06-24).** Only **P4 (production wiring)** remains, deferred to deploy day on DigitalOcean: provision managed Redis (two instances for the eviction split), set `REDIS_SCHEME=tls`, turn on the Horizon + Reverb daemons (Laravel Forge), smoke-test settlement. The one repo-side prerequisite — TLS-capable Redis config — is already done. Full milestone (incl. the P4 deploy-day checklist) is in the archive.
- **M34 P3.1 follow-ups** — deferred lobby-page polish scoped out of M34 (each needs its own data plumbing; the lobby shipped cleanly without them). Slot into a follow-up phase on user demand or when the data lands for another reason.
    - **Country flags per player** — a small flag next to each roster name. Source: FACEIT profile `country` (ISO-3166 two-letter), pulled during `FaceitProfileClient::fetch()` and persisted on a new `linked_accounts.country` column; render via a flag-emoji helper or SVG pack. Cheap, but needs a migration + a backfill of existing linked accounts.
    - **Per-player recent W/L form** (`W L W W L` chips on each slot card) — last 5 FACEIT matches via `/players/{guid}/history?game=cs2&limit=5`. Expensive at scale (10 players × per-page-load = 10 FACEIT Data API calls); needs a per-player cache (~1h TTL) + an off-band refresher job so the lobby page never blocks on FACEIT. Momentum / tilt signal.

- **M15 — Multi-game expansion** — **all 6 phases shipped (archived 2026-06-24); CS2 via FACEIT live.** Two non-blocking FACEIT follow-ups remain: lock the webhook `event_id` field name + idempotency decision (20-min empirical capture), and the webhook egress IP-allowlist. Future games (Dota 2, Valorant, LoL) extend the same per-game adapter pattern — see archive M15 for the anti-cheat catalog + composition reference.
- **M28** — Designed Fees page. **Not built yet** — the milestone was spec'd in full but no code shipped (no `FeesController` / `/fees` route / page on `main`; header still shows Support). Hand-coded `/fees` marketing surface: transparent 5–10% commission disclosure + interactive calculator, replaces footer Support link in header nav. Pre-launch trust signal; design-driven (`ui-ux-pro-max` skill). Full spec in the section below.
- **M20** — Email notifications. **Spec materially shrunk**: M27 P5 already shipped the in-app preferences UI + `notification_preferences` table + 9 `PlayerNotification` classes; M30 P4 wired the `mail` channel for ban notifications. What's left = branded HTML email templates, flip `'mail'` into `via()` on the remaining PlayerNotification subclasses, production SMTP config. Realistically 2–3 days.
- **M21** — Blacklist + safety. Block users from listings + chat, with anti-evasion considerations. Has open design questions (block semantics + multi-account evasion) — needs alignment before coding.
- **M33** — Listing time-control contract. Make Stakly's accepted time controls (blitz / rapid / classical) explicit in the listing-creation form, surface `time_control_mismatch` as a player-facing banner on stuck matches, and optionally re-enable Slice 3d strictness behind a per-listing opt-in. Reverted from M14 on 2026-06-06 — friction (legitimate correspondence / bullet games rejected silently) outweighed the small sandbag attack surface at this stage. Revisit when launch scale or a real abuse incident makes it relevant.

- **M41 — Verified skill ratings (display)** — replace the free-typed create-listing skill range with each player's **real, API-pulled rating** (FACEIT ELO + derived level; chess.com / Lichess per-time-control ratings), shown on listings + profiles. **Display-only — never gates a match** (taker's choice); the self-typed skill inputs come off the create form. FACEIT first, chess second. **Decided 2026-06-26:** chess listings become **single time-control** (one platform + TC → one unambiguous rating); the asymmetric "punch-up-only" gate is **deferred** (ship display first; revisit only as a non-blocking mismatch *warning*, never a block). P1 in progress; full spec + phases in the section below.

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

## M28 — Designed Fees page

> **Status: not built yet (flagged 2026-06-24).** Every box below was checked at planning time, but no code shipped — there's no `FeesController`, no `/fees` route, no `fees/page.tsx` on `main`, and the header still shows Support (the Support→Fees swap never happened). Reset to unbuilt; this is upcoming work, not done.

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

## M41 — Verified skill ratings (display)

Spun out of a 2026-06-26 discussion; **decisions locked 2026-06-26, P1 in progress.** The create-listing skill range (Elo) is free-typed → unverifiable and a sandbag vector (a 2100 can type "1200–1500" to fish for weaker players). Replace self-reported skill with each player's **real, API-pulled rating**, shown everywhere their listing appears. Extends existing scaffolding: `linked_accounts.skill_rating` (FACEIT ELO already fetched on link — M15), `match_provider_snapshots.skill_rating_snapshot` (exists + populated at match-take).

### Decisions (2026-06-26)

- **Display, never gate.** Show real ratings; anyone verified can take any listing — the taker decides. No creator-set bands, no blocking.
- **Remove the self-typed skill inputs** (`Cs2SkillRangeFilter` + `ChessSkillRangeFilter`) from `listings/create`. Skill stops being something the creator invents.
- **FACEIT first, chess second.** FACEIT = one ELO (+ level **derived from ELO**, not stored). Chess = per-time-control ratings.
- **Chess listings become single time-control (Option A).** One chess listing = one platform + one TC → exactly one rating to show, filter, and snapshot. Wanting blitz *and* rapid = two listings (stake is escrowed per listing anyway). **Reverses M40's multi-TC + "Any time control"** for chess create. (FACEIT/CS2 listings have no time control — unaffected.)
- **Time-control set = Bullet / Blitz / Rapid (decided 2026-06-26).** Anchored on the modes BOTH providers serve live — verified against live docs: bullet/blitz/rapid are 1:1 on Lichess (`perfs.*`) and chess.com (`chess_*`). **Bullet added**, **Classical dropped.** Classical exists only on Lichess (chess.com online has no classical — its "daily" is correspondence), so keeping it created a chess.com dead-end (every chess.com+classical match → unsettleable `ManualReview`, flagged in the P3a review) plus the only platform-gap "Unrated" case. Rapid already covers 10–15 min games; online classical is rare and slow (bad fit for fast staking). Dropping it means **every chess listing on either platform always carries a real verified rating + auto-settles**.
- **Chess ratings stored per-TC per player.** Each player still has separate bullet/blitz/rapid/classical ratings; a new normalized `linked_account_ratings` table holds them (indexable for the filter). FACEIT keeps the scalar `skill_rating` for now (revisit unifying in P6).
- **Provisional verified per provider (2026-06-26).** Lichess returns a literal `prov: true` (present only when provisional). chess.com has **no** provisional flag — derive it from `last.rd > 110` (the same RD cutoff Lichess uses). Either path → store `is_provisional` at capture → "Unrated" badge. Lichess ratings come **free** on the bio-verify call we already make (`/api/user/{username}` includes `perfs`); chess.com needs a **second** call (`/pub/player/{username}/stats`).
- **Listing rating read live (no per-listing snapshot column).** Listings show the creator's *cached* `linked_accounts` rating via join — one source of truth, always current-ish. The match-time `skill_rating_snapshot` stays for audit/history.
- **Refresh:** pull on link, then lazily re-pull if the cached rating is older than ~24h via a throttled queued job (reuses `RateLimiter` + `ProviderCircuitBreaker`; a failed refresh keeps the last-known rating, never nulls it).
- **Unrated / provisional → "Unrated" badge.** Still postable + takeable; the taker decides. No blocking.
- **Marketplace skill filter → real ratings**, game- and TC-scoped (CS2 → FACEIT ELO; chess → the selected platform+TC rating). Drops the old self-set overlap semantics.

### Phases

**P1 — FACEIT rating capture + refresh (backend)** — *done 2026-06-26*

- [x] FACEIT ELO pulled + stored on link (`LinkFaceitAccountAction` → `linked_accounts.skill_rating`); link now also stamps `skill_rating_synced_at` (only on a successful API answer). Regression test added.
- [x] Refresh path: `RefreshLinkedAccountRatingAction` (dispatch gate) + `RefreshFaceitRatingJob` — throttled on a dedicated `faceit-rating-api` limiter (20/min, separate from settlement's `faceit-api`), deduped via `ShouldBeUnique`, circuit-breaker aware. Lazy refresh when the cached rating is stale (>24h, `faceit.rating_ttl_hours`). A failed / 404 / rate-limited / keyless fetch **preserves the last-known value — never nulls it**.
- [x] FACEIT level derived from ELO via `App\Support\FaceitLevel` (no stored column).
- [x] Listing-create trigger wired into `CreateTeamPlayListingAction` (CS2 is team-only, so 1v1 `CreateListingAction` never sees a FACEIT listing). Listings read the creator's cached rating live — no per-listing snapshot column.

**P2 — FACEIT display + remove the CS2 skill input + refresh-on-view** — *done 2026-06-26*

- [x] `ListingResource.creator.faceit_rating` (elo + derived level + `is_unrated`) — CS2 only, null for chess. Rendered via a new `FaceitRatingBadge` (compact + detail variants, brand-banded grey→pink→purple — never FACEIT's amber/red, which collide with Stakly's dispute/loss palette next to a stake) on grid cards, listing rows, profile rows, and the create live-preview. "Unrated" pill when null.
- [x] **Refresh-on-view** (decided this session): viewing the board / a listing / the lobby / my-listings / a public profile queues a stale-gated FACEIT refresh for the displayed CS2 creators (deduped + 24h gate + 20/min throttle — safe on a read path). Shared `App\Actions\LinkedAccount\RefreshDisplayedRatingsAction` (`forListings` / `forAccounts`), called from `ListingController` (index/show/showTeamPlay/mine) + `UserController::show`; never inside the resource.
- [x] Removed the self-typed CS2 skill input from the create form (deleted the orphaned `Cs2SkillRangeFilter`); CS2 shows **no** "Match preferences" section at all — the verified rating surfaces in the Listing preview instead. Chess keeps its time-control + skill-range inputs until P4. `skill_min/max` columns retire in P6.
- [x] Note: CS2 is team-only, so a CS2 listing never reaches the 1v1 detail page — the badge's marketplace home is the cards + create preview; lobby rosters already show per-player ratings (left as-is). The badge's `detail` variant is built + ready for chess listing detail in P4.

**P3 — Chess: single-TC listings + per-TC rating capture** — *split into two verified slices (committed separately)*

Time-control set: **Bullet / Blitz / Rapid** (`App\Enums\TimeControl`).

*P3a — Single time-control (self-contained refactor)* — *done 2026-06-26*

- [x] Storage: `listings.time_control` jsonb-array → single nullable string column; `Listing` cast `AsEnumCollection` → `TimeControl::class`; non-chess = `null`. Edited the existing create-listings migration + `migrate:fresh`.
- [x] `TimeControl` = Bullet / Blitz / Rapid (added Bullet, dropped Classical). Validation: `StoreListingRequest` → single required-for-chess enum, **`prohibited` for non-chess** (a crafted CS2/Dota request can't smuggle a stray TC). `IndexListingsRequest` filter stays multi-select (`whereIn` replaces `whereJsonContains`, with an empty-set guard).
- [x] Swept all single-value display touchpoints (resources, Filament, cards/rows/preview, match + profile views, `listings-format.ts` → singular `timeControlLabel()`, `chess-format-filter.tsx` multi→single ToggleGroup, `create.tsx`). Dropped the FE "Any time control" collapse for per-listing display.
- [x] Outcome pipeline: `AutoFetch{ChessCom,Lichess}GameJob::pickSettleableCandidate` collection → single `time_control?->value` (null-guarded). Single-TC *improves* disambiguation; bullet now matchable, dropping classical removes the chess.com unsettleable dead-end. `CreateListingAction` also forces null for non-chess (defense in depth).
- [x] Factories/seeders single-value; updated affected feature tests. **Adversarial review (3-lens)** clean on the core refactor; the two real findings (non-chess `prohibited`, empty-`whereIn` guard) applied above. Gates: full backend **1607+** green, tsc / eslint / prettier / host build green.

*P3b — Chess per-TC rating capture (backend; display is P4)* — *done 2026-06-26*

- [x] New normalized `linked_account_ratings` table (`linked_account_id` FK cascade, `time_control`, `rating`, `rd` nullable, `is_provisional`, `synced_at`; unique `(linked_account_id, time_control)`) + `LinkedAccountRating` model + cast + `LinkedAccount::ratings()` HasMany. FACEIT keeps scalar `skill_rating`. A missing row = "Unrated"; refresh UPSERTS, never deletes (never-null).
- [x] Rating fetch: `LichessProfileClient::fetchRatings()` parses `perfs.{bullet,blitz,rapid}` off the **existing** `/api/user` call (`prov` → provisional, `games===0` skipped); `ChessComProfileClient::fetchRatings()` adds the **second** `/pub/player/{username}/stats` call (`chess_{bullet,blitz,rapid}.last.{rating,rd}`; `daily` ignored; `rd > services.chess_com.provisional_rd_threshold` (110) → provisional). New `ChessRatings` + `ChessTimeControlRating` DTOs; both clients refactored to a shared request helper so the bio-verify path is untouched.
- [x] Capture at link: `VerifyLinkedAccountAction` dispatches `RefreshChessRatingJob` after `markVerified()` (new account is stale → fires). Routed through the job (not inline) so fetch+upsert lives in one place and a rating hiccup never fails the link.
- [x] Refresh stack mirrors FACEIT: `RefreshChessRatingJob` (provider-aware client + middleware, `ShouldBeUnique`, breaker-aware, **never nulls**, bumps account-level `skill_rating_synced_at` only on an API answer) on **separate** `chess-com-rating-api` / `lichess-rating-api` budgets (30/min); `RefreshLinkedAccountRatingAction` extended to chess (no api-key prereq; per-provider TTL); `RefreshDisplayedRatingsAction` refreshes the **listing's-platform** account only (chess board no longer touches a creator's FACEIT rating).
- [x] `TakeListingAction::snapshotProviderAccounts`: chess links snapshot the listing's single-TC rating from `linked_account_ratings` (audit/history only; display reads live).
- [x] `LinkedAccountRatingFactory` + `withLichess`/`withChessCom` `syncedAt` staleness param. Pest: client fetch/parse, capture-at-link, refresh job (never-null / 404 / breaker / 429 / 403 / throttle), gate (chess providers), refresh-on-view (chess + platform isolation), snapshot. Full backend **1636** green. **Dev-marketplace rating seeding moves to P4** (visible-data wiring belongs with display).

**P4 — Chess display + remove the chess skill input**

- [ ] Show the rating for the listing's platform + TC on cards, preview, profile, detail; "Unrated" when missing/provisional. Wire the `FaceitRatingBadge` `detail` variant (built in P2) into chess listing detail, or a chess-specific badge.
- [ ] Drop `ChessSkillRangeFilter` from create.
- [ ] **Dev-marketplace seed** (moved from P3b): give seeded chess listing creators `linked_account_ratings` rows (via `LinkedAccountRatingFactory` + `withLichess`/`withChessCom`) so the board/profile/detail show real numbers. Belongs here because it's only visible once display lands.

**P5 — Marketplace skill filter rework**

- [ ] Re-point the `/listings` skill filter at real ratings — a range filter on the creator's rating, game/TC-scoped (CS2 → FACEIT ELO; chess → selected platform+TC). Drop the overlap semantics (or drop the filter if it's not pulling its weight).

**P6 — Data cleanup**

- [ ] Retire `listings.skill_min` / `skill_max`; update seeders + factories; finalize the "Unrated" empty states.
- [ ] Consider unifying FACEIT onto `linked_account_ratings` and deprecating the scalar `skill_rating` (update the `TakeListingAction` snapshot read accordingly).

### Deferred — asymmetric "punch-up-only" matching

> **Decided 2026-06-26: deferred, not built.** Ship the verified-rating display first (that's the real anti-sandbag win). A hard gate mostly constrains *honest* strong players (a real sandbagger already shows a low verified number), costs liquidity (on a money platform everyone wants to punch down), and has an unrated loophole (any gate must treat unrated as ungated). If post-launch data shows real sharking, revisit as a **non-blocking mismatch *warning*** at take time ("You're 2000, they're 1200 — big gap"), **not** a block — informs without killing liquidity and sidesteps the unrated hole.

**Original idea (kept for reference):** a player may only take a listing whose creator is equal-or-higher rated — punch up, never down. Example: user1 = 2000, user2 = 1200; user2 *can* take user1's listing (challenge up ✅), user1 *cannot* take user2's (no hunting down ❌).

### Not in M41

- Enforcing a creator-set opponent band (removed — skill is the verified number, not a range).
- Cross-game / cross-platform rating comparison (each match is one game on one platform).
- A composite "Stakly rating" of our own — we display the providers' ratings, not our own.
- A hard punch-up/punch-down gate (deferred above; a non-blocking warning is the next step if needed).
