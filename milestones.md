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
- **M15 Phase 5** — Dispute fast-path + telemetry (3 items, 2026-06-11). `OpenDisputeAction` capability gate (`Game::hasArbitrationDriver()` replaces `Game::Chess` hardcode) + `GameApi` composition chain (`FaceitGameApi → ChessGameApi → MockGameApi`) so FACEIT disputes actually settle via the fast-path. `PipelineHealth` widget per-provider breakdown so FACEIT activity is visible alongside chess. Per-provider circuit-breaker config — thresholds tunable per provider via `services.{provider}.circuit_breaker.*` with M14 defaults preserved. **M15 functionally complete for gameplay** — only Slice 4 follow-ups (event-id field + IP allowlist) and M34 (team play / lobbies, production CS2 gate) remain.
- **M35** — Outbound third-party API rate-limit audit (all 3 phases + skipped Phase 4, 2026-06-11). Self-throttle (Laravel `RateLimiter::for(...)` + `RateLimited` job middleware) on `AutoFetchChessComGameJob` (30/min), `AutoFetchLichessGameJob` (60/min), `AutoFetchFaceitGameJob` (30/min); profile clients (`ChessComProfileClient` / `LichessProfileClient` / `FaceitProfileClient`) brought up to game-client parity (429 → `RateLimitedError` + `Retry-After` parsing, breaker integration). All caps env-tunable via `{PROVIDER}_REQUESTS_PER_MINUTE`. Goal: zero production 429-driven settlement freezes.

**In-flight:**

- **M34 Phase 2** — Private invite links. Backend-only slice between P1's actions and P3's frontend: extend `StoreListingRequest` to accept `team_size` / `creator_side` / `is_public`, branch `ListingController::store` to `CreateTeamPlayListingAction` for team-play, hide invite-only listings from the public marketplace, add the `/lobbies/{token}` route resolving by `invite_token`. **Phase 1 shipped 2026-06-12** — 9 lobby actions (Create / Join / Leave / ToggleReady / Kick / ReadyCheck / Lock / ReadyCheckTimeout / FillTimeout) + 2 cron commands + `Pending`-consumer audit (Policy, MatchChannel, GameMatchController, usernameChangeBlockers, Filament resources, TS exhaustiveness) + 48 new tests. Phase 0 shipped 2026-06-12 (schema + models + factories + seeder + 18 tests).

**Active / upcoming:**

- **M15** — Multi-game expansion. First cut is CS2 via FACEIT; Dota 2 / Riot adapters extend the same pattern once that ships. **All 6 phases shipped** (Phase 0 research → Phase 5 dispute fast-path + telemetry). Two small Slice 4 follow-ups remain when FACEIT data arrives (event-id field name + IP allowlist) — neither is blocking. **CS2 production launch gated on M34** (team play / lobbies) — see M34 below.
- **M28** — Designed Fees page. Hand-coded marketing surface — transparent 5–10% commission disclosure, interactive calculator, replaces footer Support link in header nav. Pre-launch trust signal; design-driven (`ui-ux-pro-max` skill).
- **M20** — Email notifications. **Spec materially shrunk**: M27 P5 already shipped the in-app preferences UI + `notification_preferences` table + 9 `PlayerNotification` classes; M30 P4 wired the `mail` channel for ban notifications. What's left = branded HTML email templates, flip `'mail'` into `via()` on the remaining PlayerNotification subclasses, production SMTP config. Realistically 2–3 days.
- **M21** — Blacklist + safety. Block users from listings + chat, with anti-evasion considerations. Has open design questions (block semantics + multi-account evasion) — needs alignment before coding.
- **M33** — Listing time-control contract. Make Stakly's accepted time controls (blitz / rapid / classical) explicit in the listing-creation form, surface `time_control_mismatch` as a player-facing banner on stuck matches, and optionally re-enable Slice 3d strictness behind a per-listing opt-in. Reverted from M14 on 2026-06-06 — friction (legitimate correspondence / bullet games rejected silently) outweighed the small sandbag attack surface at this stage. Revisit when launch scale or a real abuse incident makes it relevant.
- **M34** — Team play + lobbies (production-launch dependency for CS2). Soft-join lobby model with hybrid stake-at-Ready commitment. Players join lobbies for free (no stake), chat + coordinate, toggle "Ready" to escrow their stake per-player (refundable until all Ready). Match flips to `Pending` when all Ready, lobby locks, leaving becomes a forfeit. Lobby owner can kick any participant (refunds them if they'd Ready'd). 5-min ready-check timeout when lobby reaches max soft-joined. One active lobby per user. Public listings show in marketplace; private listings via invite token URL. Skill range applied per-player. CS2 = 5v5 first; 2v2 Wingman follow-up. Chess (1v1) keeps current `TakeListingAction` flow — lobby applies only when `team_size > 1`. **Phase 0 shipped 2026-06-12**; P1 in-flight above. Detailed section below.
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

**Phase 2 — Private invite links**

Public/private toggle on listing creation + the `/lobbies/{token}` route.

- [ ] `StoreListingRequest` accepts `is_public` (bool). When false, server-side generates `invite_token` (`Str::random(32)`).
- [ ] `LobbyController::show` route at `/lobbies/{token}` resolves listing by `invite_token`. 404 if token doesn't exist or listing is cancelled / locked. Bypass the "user must be in skill range" check at view time (read-only) but enforce at join time.
- [ ] Private listings hidden from the `/listings` marketplace index (`whereNull('invite_token')` filter OR `where('is_public', true)`).
- [ ] Tests: invite-only access, token rotation if needed, hidden-from-marketplace check.

**Phase 3 — Frontend lobby UI**

- [ ] `/lobbies/{listing_id}` Inertia page. Grid showing both teams' slots — filled or empty, with player name + FACEIT username + skill rating + Ready badge. Real-time updates via Reverb on `lobby:{listing_id}` channel.
- [ ] "Join Team A / Team B" buttons gated by skill range + FACEIT-link + one-lobby-at-a-time.
- [ ] "Ready" toggle for the current user. Disabled when insufficient balance, with "Top up to ready" copy.
- [ ] Kick button (creator-only) on each other participant's slot.
- [ ] Lobby chat embedded — reuses the existing chat component, scoped to the match row.
- [ ] Countdown banner when in `ReadyChecking` state (5-min timer).
- [ ] Empty state when no slots filled yet (creator alone in their lobby).
- [ ] Locked state when lobby moves to Pending — shows "Match started, coordinate on FACEIT" + the lobby chat.
- [ ] Tests: Pest Feature tests for the page rendering, route resolution. Browser smoke test deferred to the wider browser-test foundation (per M15 P3 Slice 3 note — set up once we have multiple consumers).

**Phase 4 — FACEIT 5v5 verification extension**

- [ ] `AutoFetchFaceitGameJob::isOpposingRosterPair()` generalized to `isOpposingTeamRosters()` — accepts arrays of Team A + Team B GUIDs, verifies all Team A players are on one faction and all Team B players on the other.
- [ ] Match-finding: query each side's first-slot player's history; same per-candidate roster check.
- [ ] `SettleFromCardAction` extended for `winning_team` payload — splits the pot across all winning roster's user_ids via existing `Wallet::payout` calls (one per winner). Fee credited once.
- [ ] Card payload: add `winning_team` ('a' | 'b') and `winner_user_ids` (list<int>). Existing 1v1 fields stay for chess back-compat.
- [ ] Tests: 5v5 happy path (Team A wins, 5 payouts + 1 fee, BCMath-exact); AC-incomplete on 5v5; roster mismatch (4 of 5 on faction1, 1 on faction2 = no candidate); same-team queue (all 10 on faction1 = no candidate).

**Phase 5 — 2v2 Wingman**

- [ ] `Game` enum gains a 2v2-shape variant OR CS2 listings accept a `team_size = 2` variant. Discuss whether Wingman is its own `Game::Cs2Wingman` case or a `listings.team_size = 2` config on the existing `Game::Cs2`.
- [ ] Verification — FACEIT Wingman has its own queue + match format. Confirm `FaceitGameClient` parses Wingman matches with the same roster structure (Phase 4's generalized roster check should work).
- [ ] Tests + dev seed listings.

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
