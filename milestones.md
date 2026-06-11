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

**In-flight:**

_None — pick the next milestone from "Active / upcoming" below._

**Active / upcoming:**

- **M15** — Multi-game expansion. First cut is CS2 via FACEIT; Dota 2 / Riot adapters extend the same pattern once that ships. Phase 0 = FACEIT API research write-up (Context7 + provider docs). Phases 1–5 = schema extension → OAuth link flow → per-game create form → outcome pipeline → dispute fast-path + telemetry. Phase plan below.
- **M28** — Designed Fees page. Hand-coded marketing surface — transparent 5–10% commission disclosure, interactive calculator, replaces footer Support link in header nav. Pre-launch trust signal; design-driven (`ui-ux-pro-max` skill).
- **M20** — Email notifications. **Spec materially shrunk**: M27 P5 already shipped the in-app preferences UI + `notification_preferences` table + 9 `PlayerNotification` classes; M30 P4 wired the `mail` channel for ban notifications. What's left = branded HTML email templates, flip `'mail'` into `via()` on the remaining PlayerNotification subclasses, production SMTP config. Realistically 2–3 days.
- **M21** — Blacklist + safety. Block users from listings + chat, with anti-evasion considerations. Has open design questions (block semantics + multi-account evasion) — needs alignment before coding.
- **M33** — Listing time-control contract. Make Stakly's accepted time controls (blitz / rapid / classical) explicit in the listing-creation form, surface `time_control_mismatch` as a player-facing banner on stuck matches, and optionally re-enable Slice 3d strictness behind a per-listing opt-in. Reverted from M14 on 2026-06-06 — friction (legitimate correspondence / bullet games rejected silently) outweighed the small sandbag attack surface at this stage. Revisit when launch scale or a real abuse incident makes it relevant.
- **M34** — Team play + lobbies. Production-launch dependency for CS2 and every future 5v5 game (Dota 2 / Valorant / LoL). Adds `team_size` to listings (default 1; chess stays 1, CS2 = 5), a lobby model for multi-player team assembly (private invite-link or public auto-fill), per-player stake collection with all-or-nothing locking (4-of-5 staked → listing waits or expires + refunds), roster-aware match snapshots (`match_provider_snapshots.slot_index` 0–4), per-player skill gate, and settlement payout split across the winning roster. Public / private listings (invite-only via shareable link) also lands here — same lobby surface. **5v5 first; 2v2 Wingman is a fast follow-up since it shares the same lobby infrastructure with a smaller team size.** The FACEIT outcome pipeline (M15 P4) doesn't need to change — `FaceitMatchResult` already parses full 5-player rosters; M34 only changes the identity-mapping side (which snapshot rows we cross-check against the roster).

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

### Current direction (revisable)

Directional calls coming out of the planning discussion before Phase 0 starts. These shape the phase plan below but are not locked — research findings or new constraints may push back on any of them.

- **First adapter**: CS2 via FACEIT. Cleanest API surface, free developer tier, OAuth available, single anti-cheat platform per game.
- **Link method**: OAuth where the provider exposes it (FACEIT now, Riot later). Bio-code paste stays the fallback for providers without OAuth (Steam, when Dota 2 lands).
- **Anti-cheat scope**: accept any FACEIT match (Competitive + Hub). FACEIT AC runs on both — the trust pitch holds without restricting to Competitive.
- **CS2 skill range**: Faceit ELO min/max on listings; current ELO cached on `linked_accounts`, snapshotted onto `match_provider_snapshots` at match creation for sandbag-detection surfaces.
- **Snapshot table extension**: nullable `provider_user_id` text column on both `linked_accounts` (source of truth) and `match_provider_snapshots` (denormalized copy). Holds FACEIT player UUID, Steam ID, future Riot PUUID — all as text. `username` stays for display.
- **Phase 0 output location**: appended inline under this milestone as a new "Phase 0 — research findings" subsection once it wraps.
- **CS2 production launch is gated on M34 (Team play + lobbies)** (added 2026-06-09). FACEIT competitive CS2 is 5v5 — there's no native ranked 1v1 CS2 mode with consistent AC + ELO (only community-run Hubs with per-Hub AC config, which leaks the trust pitch). M15 P3 lets dev users create CS2 listings today; M15 P4 builds the polling pipeline 5v5-aware (`FaceitMatchResult` already parses full rosters). But the create-form is still 1v1-shaped (one creator, one stake), so CS2 listings can't be taken-and-settled end-to-end in production until M34 ships the lobby + multi-player stake collection. 2v2 Wingman is a follow-up after 5v5 lands. The P4 polling pipeline keeps shipping in parallel — its tests use fixture rosters, so the infrastructure validates independently of when M34 unlocks production CS2 takes.

### Phases

**Phase 0 — FACEIT API research write-up**

Read-only research pass. Use Context7 + FACEIT developer docs (developers.faceit.com) for all of these. No schema or code changes yet. Output captured as a new subsection under this milestone once Phase 0 wraps.

- [x] OAuth flow: does a Laravel Socialite provider for FACEIT exist? If not, what does a manual OAuth2 client look like? Token lifetime, refresh model, required scopes.
- [x] Data API match endpoint: response shape, winner identifier (player UUID? nickname?), rate limits, error semantics, retry / `Retry-After` headers.
- [x] ELO: where to read current Faceit ELO from — OAuth `me` endpoint or separate call? Refresh cadence (per-link, per-match, periodic background job?).
- [x] Anti-cheat fields per match: confirm FACEIT AC runs on Competitive + Hub; identify the queue-type field that distinguishes them.
- [x] Webhook viability: match-completed payload shape, security model (signed body? IP allowlist?), can webhooks replace polling for FACEIT or only augment it?
- [x] Output: append a "Phase 0 — research findings" subsection under this milestone with the answers + a go/no-go on Socialite vs manual OAuth2 client. Revisit "Current direction" picks above if research surfaces a reason to.

**Phase 1 — Schema extension**

- [x] Migration: add `linked_accounts.provider_user_id` (nullable text, indexed) and `linked_accounts.skill_rating` (nullable int).
- [x] Migration: add `match_provider_snapshots.provider_user_id` (nullable text, indexed for abuse-review parity with the existing `(provider, username)` index) and `match_provider_snapshots.skill_rating_snapshot` (nullable int).
- [x] `TakeListingAction::snapshotProviderAccounts()` writes the new columns from the matching `LinkedAccount` row.
- [x] Factory + seeder updates so dev fixtures populate the new columns for FACEIT/Steam stubs.
- [x] Tests covering the snapshot writer (chess rows stay null on `provider_user_id`; FACEIT rows populated).

**Phase 2 — FACEIT OAuth link flow**

> **Prerequisite (out-of-engineering)**: register Stakly as a FACEIT developer app at developers.faceit.com to obtain `client_id` + `client_secret`. Worth doing in parallel with Phases 0/1 so it isn't a Phase 2 blocker.

- [x] `config/services.faceit` entries for `client_id`, `client_secret`, `redirect`.
- [x] `FaceitLinkController` — redirect to FACEIT's authorize endpoint, handle the callback, fetch the player record (id + nickname + ELO), upsert `LinkedAccount`. State CSRF protection on the callback.
- [x] Extend `LinkedAccountController::index()` provider list with the FACEIT row (different UI affordance than the bio-code paste flow chess uses).
- [x] Settings UI surfaces a "Link FACEIT" OAuth button alongside the existing chess paste flow.
- [x] Tests: callback success path, declined consent, state-CSRF mismatch, idempotency on re-link, ELO populated on the model.

**Phase 3 — Per-game create form + listing creation gating**

Three slices, each shippable + commit-sized.

**Slice 1 — Backend gates** _(commit: `feat(m15-p3): per-game listing validation + isVerifiedOn helper`)_

- [x] `User::isVerifiedOn(LinkedAccountProvider): bool` helper replaces the dynamic `{provider}_verified_at` column lookup in `CreateListingAction` + `TakeListingAction`.
- [x] `StoreListingRequest` per-game platform validation (CS2 platform must be `faceit`; chess platform must be `chess_com` or `lichess`).
- [x] `ListingController::create()` returns `games[]` (Active games from the catalog) + `requirementsByGame` (which `LinkedAccountProvider` each game needs) as Inertia props.
- [x] Pest tests: successful CS2 listing creation, blocked CS2 creation without FACEIT link, blocked cross-platform requests (e.g. CS2 listing with `chess_com` platform).

**Slice 2 — Frontend game picker + per-game form swap** _(commit: `feat(m15-p3): per-game create form (game picker + CS2 fields)`)_

- [x] Dropdown game picker in the create form's Game section — single-row trigger (gradient game icon + game name + verification chip + chevron) opens a Popover listing all Active games. Iterated from the original tile-grid sketch during Slice 2 because the dropdown reads tighter and scales better as more games ship.
- [x] Game-aware list surfaces — reusable `GameChip` component (Crown / Target / Swords icon + game label, mirroring the create-form picker) rolled out to `/listings/mine` (new Game column), `/matches` (chip cluster), `/listings` marketplace row, and `/match/{id}` header. Each surface shows the listing's game at a glance so chess + CS2 listings are distinguishable without expanding the row.
- [x] Per-game inline link-account gate — when the selected game's required provider isn't linked, show an inline "Link FACEIT to post CS2 listings →" notice rather than the current full-page swap. Users can preview the form for either game and link from there.
- [x] Extract `ChessFormatFilter` (time-control toggle) + `ChessSkillRangeFilter` (Elo min/max) into `components/listings/`.
- [x] Add `Cs2SkillRangeFilter` (Faceit ELO range) sibling component.
- [x] Platform display branches: chess keeps the existing chess.com / Lichess picker (shown only when both linked); CS2 shows a static FACEIT chip (only platform).
- [x] Drop the obsolete `// Create-listing is chess-only today` comment in `pages/listings/create.tsx`.

**Slice 3 — Defaults + polish + props coverage** _(commit: `feat(m15-p3): default-game logic + create-form copy polish`)_

- [x] Default-game logic: chess-only-linked → defaults to Chess; FACEIT-only-linked → defaults to CS2; both linked → Chess; neither linked → CS2 (drives toward the newer integration). Helper `defaultGameFor()` in `pages/listings/create.tsx`; initial `time_control` also resets to `[]` when the default game isn't chess so a stale `['blitz']` doesn't tag along on CS2 form opens.
- [x] Copy polish on inline link gate: `LinkGateNotice` title now reads `Link :provider to post` (was `Link :provider first`); body collapsed to a first-person sentence with the "about a minute" reassurance preserved.
- [x] Inertia-assertion Feature tests in `tests/Feature/ListingStoreTest.php` lock the per-game `requirementsByGame.{game}.verified` prop that `defaultGameFor()` reads — three cases (FACEIT-only, chess-only, unlinked). A Pest browser smoke test was attempted via `pest-plugin-browser` + Playwright but rolled back: React wasn't hydrating inside the plugin's testbench HTTP server (Vite asset URL rewriting + SSR shell mismatch). Browser-test foundation deferred — auth modal, take-flow, and wallet flow share the same infra need, so we'll set it up properly once we have multiple consumers.

**Phase 4 — FACEIT outcome pipeline**

Polling-first; webhook receiver lives in Slice 4, deferred until FACEIT support replies on webhook egress IPs (one of the Phase 0 "needs human follow-up" items). Polling remains the safety net regardless of webhook.

**Slice 1 — `FaceitGameClient` + Data API wrapper** _(commit: `feat(m15-p4): FaceitGameClient + Data API wrapper`)_

- [x] `FaceitGameClient` — HTTP wrapper for the FACEIT Data API. API-key bearer auth via `config('services.faceit.api_key')` (already wired in Phase 2 — Data API rejects OAuth user tokens with 403, the server-side key is required). Graceful null on missing key (matches `FaceitProfileClient`).
- [x] `GET /data/v4/matches/{match_id}` parser: `results.winner ∈ {faction1, faction2}` + `teams.{faction1,faction2}.roster[]` with `player_id` / `nickname` / `anticheat_required` per player.
- [x] Anti-cheat gate lives on the result DTO via `FaceitMatchResult::isAntiCheatComplete()` and `isDecisive()` (returns false if any roster player has `anticheat_required === false`). Per Phase 0: queue-agnostic gate (FACEIT AC mandatory on `competition_type === 'matchmaking'`, opt-in for Hubs — the per-player boolean is the only reliable signal that AC ran on both teams). Client returns the raw match; the caller (Slice 2 job + adapter) decides what to do with an AC-incomplete match.
- [x] Error mapping into the existing `ProviderError` hierarchy (`Retry-After` parsing via `RateLimitHeaderParser`, classified retry vs permanent failures) — mirrors the shape `LichessGameClient` / `ChessComGameClient` use today.
- [x] Fixture tests (`tests/Feature/Services/Provider/FaceitGameClientTest.php` — 12 cases): happy path (winner identified, both rosters AC=true), AC-incomplete match parses but `isDecisive()` returns false, non-FINISHED status, faction2 winner roster lookup, 4xx-other → `PermanentProviderError`, 5xx → `TransientProviderError`, 429 → `RateLimitedError` with `Retry-After` honoured, malformed JSON → `PermanentProviderError`, missing API key → null (graceful dev path), Authorization Bearer header sent correctly.

**Slice 2 — `FaceitGameApi` + `AutoFetchFaceitGameJob`** _(commit: `feat(m15-p4): FaceitGameApi adapter + auto-fetch job`)_

- [x] `FaceitGameClient::searchPlayerMatches()` — `GET /data/v4/players/{id}/history` wrapper, returns slim match-ID list with `game` + `from` + `limit` query params. 10 new tests covering happy path / query-param wiring / 404 / empty list / no-API-key / 4xx / 5xx / 429 / malformed JSON.
- [x] `App\Enums\AutoFetchOutcome::AcIncomplete` — terminal outcome for "match found via API but anti-cheat wasn't required on every roster slot." Recorded in `match_auto_fetch_attempts`; match falls to ManualReview via timeout.
- [x] `App\Models\GameMatch::snapshotProviderUserId()` accessor sibling to `snapshotUsername()` — reads the snapshotted FACEIT GUID (Steam ID, Riot PUUID — text) for cross-provider identity at arbitration.
- [x] `AutoFetchFaceitGameJob` mirrors `AutoFetchChessComGameJob`'s shape — same `ShouldBeUnique` / `ShouldQueueAfterCommit`, same `$tries = 7` budget, same `[5, 15, 30]` backoff + `[5, 15, 45]` no_match retry chain, same `retryUntil()` (match-confirmation timeout), same `ProviderCircuitBreaker` integration. Match-finding strategy per Slice 2 agreement: query creator's history first (top 10 by recency since `match.created_at`), fall back to taker's history if creator yields nothing; for each candidate `match_id` call `fetchMatch()` then verify creator + taker GUIDs sit on OPPOSING factions before posting a card. AC-incomplete → terminal `AcIncomplete` audit row (no card, no retry — per Slice 2 agreement). 10 tests cover happy path (creator + taker wins), AC-incomplete, no-match retry, opposing-roster check, fall-back-to-taker, snapshot-missing skip, already-posted idempotency, permanent provider error, transient provider error.
- [x] `FaceitGameApi` implements `GameApi` — reads the chat card by `provider: 'faceit'` discriminator, returns `GameApiResult` with `winner_user_id` resolved directly off the card (the job already resolved snapshot→user). Defensive participant check refuses cards naming foreign winners. Falls through to `MockGameApi` when no FACEIT card is present, card has no winner (draw), or the named user isn't a match participant. 7 tests.
- [x] `SettleFromCardAction` extension — new fast-path: when card's `winner_user_id` is set (FACEIT), look up `User` by id directly with a participant-check guard; chess cards continue through the existing `winner_username` + snapshot cross-check. `isDraw()` rule per provider: chess on `winner_color === null`, FACEIT on `winner_user_id === null`. 4 new tests + 1 existing test updated (the "unknown provider → no-op" test now uses `'riot'` since FACEIT is no longer unknown).

**Slice 3 — `DispatchAutoFetchAction` wiring + end-to-end** _(commit: `feat(m15-p4): dispatch FACEIT job per listing.platform`)_

- [x] `DispatchAutoFetchAction` extends its match expression to dispatch `AutoFetchFaceitGameJob` when `listing.platform === LinkedAccountProvider::Faceit`. No `default` arm — Steam (the other enum stub) keeps throwing `UnhandledMatchError` loud, which is the right shape until a Steam adapter lands. Snapshot guard untouched: `TakeListingAction::snapshotProviderAccounts()` always populates `username` + `provider_user_id` atomically for FACEIT, so `snapshotUsername()` remains a valid proxy for "snapshot exists with the data the job needs." 2 new dispatch tests (positive + per-provider circuit-breaker isolation).
- [x] End-to-end seeded test at `tests/Feature/Jobs/AutoFetchFaceitPipelineTest.php`: CS2 listing created → `TakeListingAction` (creator-stake hold + taker-stake hold + match + snapshots from linked accounts) → `DispatchAutoFetchAction` (sync queue runs the job inline) → `Http::fake` for FACEIT `/players/{guid}/history` + `/matches/{id}` → system card posted with `winner_user_id` resolved at job time → `SettleFromCardAction` pays the winner + credits platform fee → wallet ledger asserted via BCMath exact (`580.000000` creator winner / `400.000000` taker loser / `20.000000` platform fee at the default 10% rate). `faceitOpposingRosterFixture` promoted from the job test to `Pest.php` so both files share it.
- [x] Idempotency test covers re-dispatch after settlement → `not_pending` skip → wallet balance unchanged + no second card posted. Plus a third defensive test that a CS2-FACEIT listing taken by a non-FACEIT-linked taker short-circuits at `TakeListingAction`'s gate (`not_linked` sentinel, no money moves).

**Slice 4 — Webhook receiver (in-progress; full ship awaiting FACEIT support reply)**

Webhooks collapse the polling lag — when FACEIT pings us the moment a match ends, settlement runs within seconds instead of waiting for page-visit / chat-send / cron triggers. The polling pipeline (Slices 1–3) stays underneath as the safety net so we're never *dependent* on webhooks for correctness.

What we can build now (pre-answer):

- [x] `POST /webhooks/faceit` endpoint behind `VerifyFaceitWebhook` middleware (constant-time `hash_equals` against `X-Faceit-Webhook-Secret` header → 401 on mismatch, 503 when the receiver isn't configured) + per-IP `throttle:60,1`. Excluded from CSRF in `bootstrap/app.php`. Sits outside the `{locale}` prefix group — `webhooks` added to `RedirectUnprefixedLocale::EXEMPT_FIRST_SEGMENTS` AND to `tests/TestCase.php`'s parallel exempt list (they share the locale-routing model; comment in the test-case file flagged the manual sync requirement).
- [x] `FaceitWebhookController` defensively scans the JSON payload for player guids (both at the root and under a `payload` envelope), looks up Pending Stakly matches via the `(provider, provider_user_id)` index on `match_provider_snapshots`, and dispatches `AutoFetchFaceitGameJob` for each candidate. Always 200 on authenticated requests — webhook is a *notification*, NOT proof. The dispatched job re-fetches via Data API and runs the existing AC + opposing-roster + winner checks. Settlement path unchanged from Slice 3.
- [x] Idempotency deferred to job level (`ShouldBeUnique` keyed on Stakly `match_id` + `alreadyPosted()` short-circuit) — no `faceit_webhook_events` table until we confirm the event-id field name from support. Duplicate webhooks become two cheap dispatch attempts that both no-op.
- [x] 10 feature tests cover: 401 missing/wrong secret, 503 unconfigured receiver, CSRF exemption, dispatch on valid + matching payload, no-dispatch on no candidates / Settled match / malformed payload, defensive single-player-guid match, and the alternative flat-envelope shape.

⏳ **Awaiting FACEIT support answers** (questions live in [`docs/faceit-support-questions.md`](docs/faceit-support-questions.md)) — when replies arrive we'll revisit:

- **Webhook egress IPs** → add IP-allowlist middleware in front of the shared-secret check. The secret alone is a single point of compromise; the allowlist is the second layer.
- **Webhook retry policy** → align our 5xx response semantics with FACEIT's retry budget so transient errors don't drop events. Informs whether we need a dead-letter table.
- **Exact event payload shape** → confirm the `event_id` field name + envelope structure. Phase 0 has a community-docs sketch; we'll verify against the real schema and adjust the controller's parser if it differs.

Code areas that will likely change once answers land: `app/Http/Middleware/VerifyFaceitWebhook.php` (IP allowlist), `config/services.php` (`faceit.webhook_egress_ips`), and the event-id parsing in the controller.

**Phase 5 — Dispute fast-path + telemetry**

- [ ] `OpenDisputeAction` — replace the `Game::Chess` hard-check on the fast-path gate with a capability check ("does this game have a real `GameApi` adapter registered?"). Could be a method on the `Game` enum (e.g. `hasArbitrationDriver(): bool`) or a config-driven allowlist.
- [ ] `PipelineHealth` widget surfaces FACEIT alongside chess.com / Lichess (auto-settlements, errors, latency, volume).
- [ ] Circuit breaker thresholds / cooldowns for FACEIT, distinct from chess's.
- [ ] End-to-end dev test: seeded CS2 listing → take → result polled → card posted → settlement fires → wallet updates correct.

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
