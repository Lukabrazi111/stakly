# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M8 all phases, M10, M11, M12 all phases, M14 all phases, M15 all phases, M16 all phases, M17, M18, M19, M22, M23, M24, M25, M26 all phases, M27 all phases, M29 all phases, M30 all phases, M31 all phases, M32 all phases, M34 all phases, M35 all phases, M36 all phases, M37 all phases, M38 P1–P3 (paused at P4), M39 all phases, M40 all phases, M41 all phases, M42 all phases, M43 all phases, M44). **Parked milestones** (work that isn't being picked up right now) also live in the archive — currently M13. This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Recently shipped** — full per-milestone summaries live in `milestones_archived.md`. Latest: **M44** (recruiting-lobby findability — a lobby you're live in now surfaces in Matches with fill progress + Return → so joiners don't get lost), **M43** (listings + lobby design polish via `/design-review` — chip merge/recolor + `--platform-*` tokens, listing-detail spec-sheet, lobby join CTA + guest sign-in + team accents), **M42** (Filament admin audit — multi-game/team-play crash + blindspot fixes, dispute-view Team A/B roster + team money, Games-Active gate, widget perf), and **M41** (verified skill ratings — FACEIT level dial + chess per-TC ratings + marketplace filter) — through 2026-07-05.

**Active / upcoming:**

- **M38 — Redis for queue, cache & sessions (launch-readiness)** — **P1–P3 shipped + parked (archived 2026-06-24).** Only **P4 (production wiring)** remains, deferred to deploy day on DigitalOcean: provision managed Redis (two instances for the eviction split), set `REDIS_SCHEME=tls`, turn on the Horizon + Reverb daemons (Laravel Forge), smoke-test settlement. The one repo-side prerequisite — TLS-capable Redis config — is already done. Full milestone (incl. the P4 deploy-day checklist) is in the archive.
- **M34 — kicked-player notification (real-time toast + rejoin copy).** *Shipped 2026-07-05 — user verified the live WebSocket toast works under `composer run dev`. Backend Pest-green (LobbyBroadcast 22 + lobby suite 177), FE tsc/build clean.* Today the "removed" toast flashes to the OWNER (the actor); the kicked player's browser only gets a generic `LobbyUpdated` reload trigger — no "you were kicked" signal, and on a PRIVATE lobby that reload 404s them (kick bars access). **Goal:** the kicked player learns they were removed + when they can rejoin. **Scope:** (a) carry `kicked_user_id` on the `LobbyUpdated` broadcast (`private-lobby.{id}`, one race-free event they already subscribe to); (b) `LobbyRealtimeSync` — when `kicked_user_id === viewer`, toast "The host removed you from the lobby. You can rejoin in 5 minutes." then **public → stay as spectator (reload), private → redirect to marketplace** (avoids the 404); (c) tighten the `kick_cooldown` rejoin toast ("right now" → the actual window). 5-min cooldown + any escrow refund are unchanged (`KickParticipantAction`). Tests: `LobbyBroadcastTest` payload assertion + two-tab browser check.
- **M34 — match roster-card polish (via `/design-review`).** *Built + verified in-browser 2026-07-05 (match #221); frontend-only; pending user commit.* The `matches/{id}` Rosters cards (`team-rosters.tsx`) were ragged (uneven heights), had a bare unlabeled grey rating chip, no team-color identity, said "You", non-clickable names, and a whisper-thin "No matches yet". Frontend-only pass (no backend): team-color avatar rings (A pink / B purple, mirrors the lobby), a labeled "Rating" block, name → profile link (plain-underline hover, real name not "You"), and the SAME 3-stat grid on **every** card (new players read `0 / — / —`) so all cards stay the same height. FACEIT dial + W/L-strip parity deferred (needs a match-payload change — `TeamMatchPlayer` only ships `skill_rating` + basic `platform_stats`, not the dial object / `recent_form`).
- **M34 — match/lobby consolidation + FACEIT handle on match roster.** *(B) SHIPPED, (A) BUILT THEN REVERTED — 2026-07-06.* **(A) locked-lobby→match redirect — REVERTED same day on user UX feedback.** It was built (redirect anyone who could `view` the match off `/listings/{id}`→`/matches/{id}` once `lobby_state === 'locked'`) and green, but in dogfooding it felt wrong: the player whose Ready click LOCKS the lobby was instantly yanked from the lobby to the match page (the `back()` after `toggleReady` reloads `/listings/{id}`, which then redirected). User wanted the pre-consolidation behavior — locking keeps you on the lobby's locked state (coord-pulse / "match starting"), match page reached via the `TeamMatchStartedNotification` / direct nav. Reverted: removed the redirect block in `ListingController::showTeamPlay`, restored `LobbyPageTest`'s locked-deadline case to the creator, deleted `LockedLobbyRedirectTest`. So the two post-lock surfaces (locked lobby view + match page) coexist again by design. **If revisited:** the redirect idea isn't wrong, the *trigger* was — don't redirect the actor who just locked; a fresh direct GET to a locked `/listings/{id}` could still redirect. Left alone for now. **(B) platform handle — SHIPPED + KEPT.** `GameMatchResource::buildRoster` adds `platform_username` from `providerSnapshots` matched by `(side, slot_index, provider === listing.platform)`; `team-rosters.tsx` renders it as a clickable external-profile link via `config/platforms.ts` `PLATFORM_PROFILE_URL`; `TeamMatchResourceTest` +1 + shape assertion. Browser-verified (10/10 FACEIT handles on `/matches/3`), tsc + build clean, Lobby suite 159 + resource tests green post-revert. /cso boundary check on the consolidation surface came back clean (chat participant-gated both sides, resource is PII-free whitelist). Original two-part plan below (kept for context; (A) is the reverted part):
    - **(A) Locked lobby → match redirect (chosen: option B).** Post-lock, `/listings/{id}` (lobby view) and `/matches/{id}` are two views of the SAME event — same shared chat (the locked-lobby chat already reads `listing->gameMatch` messages), same roster, and the match page already carries roster + chat + play-on-FACEIT + settlement/dispute. So once a team lobby locks, `/listings/{id}` should **redirect to `/matches/{id}`** (match = single canonical post-lock page), killing the redundancy. **Money/match-flow routing change → high-stakes: tests required.** Open questions to resolve FIRST: (1) **match view policy for non-participants on a PUBLIC listing** — if `GameMatchPolicy::view` is participant-only, redirecting a public-lobby spectator would 404 them; decide spectator-view vs a fallback (don't redirect / show read-only). Private-listing non-participants already 404 (M34 P5), fine. (2) which states redirect — `locked` for sure; decide whether settled/cancelled team listings also send to the match. (3) the lobby view's `locked` branch in `team-play-lobby-view.tsx` (`LIVE_STATES` incl. `locked`, the coord-pulse/countdown) becomes unreachable via `/listings` — remove or keep defensive. Pest: redirect for participant/locked, no-redirect for recruiting/ready_checking, the public-spectator decision, private 404 unchanged.
    - **(B) FACEIT handle on the match roster (scouting).** The roster card shows the Stakly `@handle` (`@testuser`), which doesn't help you find an opponent in-game. Add each player's **platform handle** (FACEIT / chess.com / Lichess) — source from the match's already-loaded `providerSnapshots` → add to `GameMatchResource` `team_a`/`team_b` → `TeamMatchPlayer` type → render on `team-rosters.tsx` (keep the linked Stakly name as primary, add the platform handle as a secondary line; consider linking it to their external profile via `config/platforms.ts` `PLATFORM_PROFILE_URL`). Small backend add (read-only display data); resource-shape test. **W/L strip stays DROPPED** (user: not needed on the match page).
- **M34 — lobby refund idempotency keys (`/cso` H2, 2026-07-06).** *Hardening — not exploitable today; queue when touching lobby escrow next.* The three lobby escrow **release** paths — `KickParticipantAction.php:63`, `LeaveLobbyAction.php:97/125`, `ToggleReadyAction.php:110` (un-Ready) — call `Wallet::release(...)` with **no `reference`** idempotency key, unlike every other refund path in the codebase (`listing-cancel:{id}`, `match-draw:{id}:{uid}`, `cancel-refund:{id}:{uid}`). Safe *today*: all lobby actions serialize on the listing `lockForUpdate`, and each refund is gated by an immediate `is_ready`/`stake_held_at`/`kicked_at` state transition, so concurrent double-refund is impossible (traced end-to-end during the /cso audit). Risk is **latent** — a future refactor that weakens the row lock or the state guard turns this into a live double-refund. The `hold` side omitting a reference is correctly justified (Ready is a repeatable toggle — a static ref would short the pot on re-Ready); the fix is the *release* side only. **Scope:** add a per-event reference to the 3 releases (e.g. `kick-refund:{listing}:{uid}:{kicked_at}`, `leave-refund:{listing}:{uid}`, `unready-refund:{listing}:{uid}:{ts}`) + a Pest test asserting a double-kick / double-leave posts exactly one refund row. Money-touching → high-stakes mode. (Minor sibling notes from the same audit: assert `config('stakly.platform_fee_rate')` ∈ [0,1) at boot; add `.gstack/` to `.gitignore` before saving any `/cso` report locally.)
- **M34 P3.1 follow-ups** — deferred lobby-page polish scoped out of M34 (each needs its own data plumbing; the lobby shipped cleanly without them). Slot into a follow-up phase on user demand or when the data lands for another reason.
    - **Country flags per player** — a small flag next to each roster name. Source: FACEIT profile `country` (ISO-3166 two-letter), pulled during `FaceitProfileClient::fetch()` and persisted on a new `linked_accounts.country` column; render via a flag-emoji helper or SVG pack. Cheap, but needs a migration + a backfill of existing linked accounts.
    - **Per-player recent W/L form** (`W L W W L` chips on each slot card) — last 5 FACEIT matches via `/players/{guid}/history?game=cs2&limit=5`. Expensive at scale (10 players × per-page-load = 10 FACEIT Data API calls); needs a per-player cache (~1h TTL) + an off-band refresher job so the lobby page never blocks on FACEIT. Momentum / tilt signal.
- **M34 — kick-control redesign (kebab owner menu).** *Built + verified in-browser 2026-07-05 (via `/design-review`); pending user commit.* Resolves the long-open placement question. The bare `✕` (`slot-card.tsx`, `absolute top-1 right-1`) overlapped the FACEIT level dial + Ready pill + W/L strip, had no real affordance, a sub-44px target, and fired `router.delete` with no confirmation. **Goal:** a calm, discoverable, mis-click-safe owner control. **Scope:** replace the `✕` with a kebab (`⋮`) owner-only menu in its own top-right lane (no overlap) → destructive "Remove player" item → shadcn `Dialog` confirm ("Remove :name from the lobby? Their slot reopens.") that calls the existing `onKick` prop — the mutation stays in `team-play-lobby-view.tsx` (unchanged). Reuses the already-installed `DropdownMenu` + `Dialog` primitives (no new deps); mirrors `cancel-listing-dialog.tsx`. Owner-only, non-creator slots only (`showKick` gate unchanged). Kebab committed as `b4b10c9`. **Two lobby follow-ups (2026-07-05, uncommitted):** (a) opponent nickname on the slot card now links to their profile (`/users/{username}`, `slot-card.tsx`); (b) the lobby-header mode chip dropped its game icon so "CS2 · 5v5 · Global" matches the icon-less shared `GameChip` (`lobby-header.tsx` `ModeRow` → `GAME_LABEL`). (M34 core + the 2026-06-28/30 private-lobby-access + invite-share hardening all shipped — archived.)

- **M15 — Multi-game expansion** — **all 6 phases shipped (archived 2026-06-24); CS2 via FACEIT live.** Two non-blocking FACEIT follow-ups remain: lock the webhook `event_id` field name + idempotency decision (20-min empirical capture), and the webhook egress IP-allowlist. **The IP-allowlist is `/cso`-flagged H1 (2026-07-06):** webhook auth is a static shared-secret header via constant-time `hash_equals` (no HMAC body signature — FACEIT doesn't offer one), so a *leaked* secret lets an attacker spam `AutoFetchFaceitGameJob` dispatches → burn FACEIT quota / trip the `ProviderCircuitBreaker` → settlement latency. It **cannot mispay** (the job re-verifies via the Data API; the webhook is never outcome truth) and `throttle:60,1` bounds it. Concrete mitigation = the IP allowlist (there's already a `TODO` for it in `VerifyFaceitWebhook.php`), pending FACEIT support's egress-IP reply. Future games (Dota 2, Valorant, LoL) extend the same per-game adapter pattern — see archive M15 for the anti-cheat catalog + composition reference.
- **M28** — Designed Fees page. **Not built yet** — the milestone was spec'd in full but no code shipped (no `FeesController` / `/fees` route / page on `main`; header still shows Support). Hand-coded `/fees` marketing surface: transparent 5–10% commission disclosure + interactive calculator, replaces footer Support link in header nav. Pre-launch trust signal; design-driven (`ui-ux-pro-max` skill). Full spec in the section below.
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

## ▶ Next up — do this next

_Living "you are here" pointer — the short version of what to work on right now. Update as work lands; full detail lives in the milestone entries above. Last updated 2026-07-06._

**Uncommitted right now — commit these:**
- [ ] M34 (B) platform handle on the match roster — `GameMatchResource`, `team-rosters.tsx`, `types/match.ts`, `TeamMatchResourceTest`. Suggested: `feat: M34 — platform handles on match roster (clickable external profile links)`. _(The (A) redirect was built then reverted this session — `ListingController` + `LobbyPageTest` net to no functional change; `LockedLobbyRedirectTest` deleted.)_
- [ ] (Earlier, still uncommitted) Match roster-card polish + kick-control kebab + kicked-player toast — the prior-session M34 work `team-rosters.tsx` / `slot-card.tsx` also carries.

**M34 match/lobby — 2026-07-06** _(detail in the M34 entry above; pending user commit):_
- [x] **(B) SHIPPED** — each roster player's FACEIT/chess **platform handle** renders on the match page as a clickable external-profile link (`GameMatchResource` ← `providerSnapshots`). `TeamMatchResourceTest` +1 green, browser-verified (10/10 handles).
- [x] **"View lobby" link on the team match page** — the lobby had "View match page →" but the match had no return trip; added a top-right "View lobby" link (`team-match-view.tsx`, team-only, → `/listings/{id}`). Browser-verified round-trip. tsc + build clean.
- [~] **(A) REVERTED** — the locked-lobby→match redirect was built + green but reverted same day: it yanked the player who locked the lobby (final Ready) straight to the match page. Locked lobbies stay on the lobby view like before. If revisited, don't redirect the locking actor.

**Security hardening — queued from `/cso` (2026-07-06):** _money-flow audit came back clean (0 CRITICAL / 0 HIGH / 0 exploitable); two MEDIUM hardening items to slot in when the area is next touched:_
- [ ] **H1 — FACEIT webhook IP allowlist** (2nd auth layer over the static shared-secret; can't mispay, can burn quota). Detail in the **M15** entry above. Needs FACEIT support's egress IPs.
- [ ] **H2 — lobby refund idempotency keys** (`reference` on the 3 lobby `Wallet::release` calls). Detail in the **M34 — lobby refund idempotency keys** entry above. Latent, not live.

**On deck (not started):** M38 P4 (deploy-day Redis wiring) · M28 (Fees page — spec'd, not built) · M20 (email templates — mostly done) · M21 (blacklist — open design Qs).

