# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M8 all phases, M10, M11, M12 all phases, M14 Slice A, M16 all phases, M17, M18, M19, M22, M23, M24, M25). This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Active / upcoming:**

- **M13** — Chat anti-abuse + moderation [parked — design needs review]
- **M14** — Outcome pipeline hardening (reframed from "automated outcome adapters" — observability + reliability + coverage of the auto-fetch pipeline; Slice A + Phase 1 shipped, Phases 2–4 remain)
- **M20** — Notifications (email infrastructure + per-event preferences UI; M20 owns the surface end-to-end)
- **M21** — Blacklist + safety (block users from listings + chat, with anti-evasion considerations)
- **M15** — Multi-game expansion (FACEIT, OpenDota, Riot adapters)
- **M26** — Filament-managed CMS pages (Privacy, Terms, About — multilingual schema, SEO-indexable via global Inertia SSR; Phases 1–3 shipped; small follow-up for og: tags + APP_NAME; Phase 4 locale switcher deferred until a second language ships)
- **M27** — In-app notifications + action-required UX + sound (bell in `SiteHeader`, real-time via Reverb, per-event sound priority, sticky action banners; designed to enable M20 email without rework)
- **M28** — Designed Fees page (transparent commission disclosure, interactive calculator, header nav — hand-coded React, NOT CMS-managed)

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

---

## M26 — Filament-managed CMS pages (multilingual + SSR)

Move Privacy Policy, Terms of Service, and About Us from hardcoded React pages to database-backed, admin-editable, multilingual content. SEO-indexable thanks to global Inertia SSR (Path A — turned on for the whole app, not just CMS pages).

The schema bakes in `locale` from day one even though English is the only language at launch, so adding a second language later is a content task, not a migration.

### Design decisions taken into this milestone

- **Markdown body**, edited via Filament's built-in `MarkdownEditor`. Reasons: XSS-safe by construction (we whitelist syntax), preview is straightforward, content diffs cleanly in git if we ever export. These pages don't need rich-text features (no images, no tables, no embeds).
- **Locale baked in from day one** — `pages` table has a `locale` column, UNIQUE `(slug, locale)`. App default is `en`. Future languages add rows, not migrations.
- **URL pattern: `/{locale}/{slug}`** with `/{slug}` redirecting to the user's locale (`/privacy` → `/en/privacy` by default). SEO-correct multilingual pattern — Google indexes per-locale URLs as distinct pages.
- **Global Inertia SSR (Path A)**, not Blade-only for CMS. SEO works on every Inertia page in the app — homepage, listings index, listing detail, profile pages — not just the three CMS pages. The SSR work is the heaviest part of this milestone but the benefit is broad.
- **Hardcoded routes per page**, not a wildcard `/p/{slug}` catch-all. `/privacy`, `/terms`, `/about` are first-class destinations with brand value — clean URLs matter.

### Phases

**Phase 1 — Schema + Filament admin + first page (About) end-to-end** ✅ Shipped 2026-05-29

- [x] Migration: `pages` table — `id`, `slug` (string 64), `locale` (string 5, default `'en'`), `title` (string 200), `body` (text, markdown), `published_at` (nullable datetime — admin saves drafts), `created_at`, `updated_at`. UNIQUE `(slug, locale)`.
- [x] `App\Models\Page` with `forSlugWithFallback($slug, $locale)` + `isPublished()` — falls back to `'en'` row if requested locale not yet translated. Cache key `cms.page.{locale}.{slug}` with `saved` / `deleted` events busting it across every supported locale (mirrors M24 game catalog pattern).
- [x] Factory + seeder. Seeder writes an initial `About` row in English so a fresh `migrate:fresh --seed` has a working `/about`.
- [x] Filament `PageResource` at `/admin/pages` — table list (title, slug, locale, status badge, updated_at), edit form with `MarkdownEditor` for body + locale `Select` + "Preview" record action that opens a temporary signed URL in a new tab (30-min expiry; bypasses cache + published-at gate so admin can see drafts).
- [x] `PageController::show($locale, $slug)` — resolves via model + `Inertia::render('cms/page', [...])`. Public path goes through `Cache::rememberForever` keyed on the cache key; signed URLs bypass both the cache and the publish gate.
- [x] Public routes: `Route::get('/{locale}/{slug}', …)->whereIn('locale', SUPPORTED_LOCALES)` + `Route::get('/{slug}', …)` → 301 to `/en/{slug}` when the page exists, 404 otherwise.
- [x] React `cms/page.tsx` — server-rendered markdown HTML inside `SiteLayout`. Laravel's built-in CommonMark (`Str::markdown()`) strips raw HTML, so `dangerouslySetInnerHTML` is XSS-safe. Hand-rolled `.cms-prose` block in `app.css` for body styling — deferred installing `@tailwindcss/typography` until a richer page type needs it.
- [x] Tests: 43 Pest tests across `PageTest` (model behavior + cache invalidation + XSS guard), `PageControllerTest` (render / 404 / preview / cache / redirect / unsupported locale), `Admin/PageResourceTest` (form, slug uniqueness composite, publish/unpublish, status filter).

Bonus extensions shipped during Phase 1 (not in original scope):

- Publish / Unpublish row + bulk actions on the Filament resource. Bulk confirms with a modal; per-row Publish is one-click (Unpublish confirms because content disappears). Skips already-in-target-state rows silently — idempotent.
- Status filter on the table (Draft / Scheduled / Published) — computed in SQL from `published_at` math, so flipping the dropdown narrows the result set.
- Preview record action both on the table row AND on the EditPage header so the admin can verify rendered markdown without leaving the form.

**Phase 2 — Discoverability + Privacy/Terms scaffolding** ✅ Shipped 2026-05-29

- [x] Seed Privacy + Terms rows as **drafts** (`published_at = null`) so they appear in Filament admin but 404 publicly until admin fills in the copy + publishes. (Bonus: added `Support` as a fourth draft row in the same pass.)
- [x] Wire `About`, `Privacy`, `Terms` links in `SiteFooter` to their `/en/{slug}` URLs (replace the current placeholder `href="#"`). Final footer surfaces four utility links: About, Support, Terms, Privacy.
- [x] Fix `How it Works` in `SiteHeader` to anchor to `/#how-it-works`. Decided to **remove** `How it Works` from the footer entirely instead of duplicating it — header owns product nav, footer owns utility/legal. Added a global `html { scroll-behavior: smooth }` in `app.css` (inside `@layer base`, guarded by `prefers-reduced-motion`) so the anchor jump is animated for users who haven't opted out.
- [x] `MobileMenu` decision: primary nav mirrors the desktop header (Listings + How it Works) only — CMS links live in the footer (visible on every scroll). Inline comment in `mobile-menu.tsx` documents the choice so a future contributor doesn't "fix" it by adding them back.

Deferred out of Phase 2 (not blocking close):

- Footer test asserting the four hardcoded link URLs. Low-value — these are presentational `<Link>` literals with no logic; if we add URL generation behind them later (locale-aware `route()` helpers), add the test alongside that change.
- Manual content writing for Privacy / Terms / Support bodies. Editorial task, not engineering — the admin can write them at any time via Filament once they're ready; the routes 404 in the meantime, which is the desired pre-launch behaviour.

**Phase 3 — Global Inertia SSR enablement (Path A)** ✅ Shipped 2026-05-30

The big architectural piece. Benefits every Inertia page, not just CMS — homepage, listings index, listing detail, and profile pages all become first-byte-rendered HTML. Also unlocks (but doesn't fully deliver — see Phase 3 follow-ups) social link previews on Discord / Twitter / Slack.

Audit pass surfaced that prior work already scaffolded the config (`'ssr' => ['enabled' => true, 'url' => '127.0.0.1:13714']` in `config/inertia.php`) and the build script (`build:ssr` in `package.json`). What was completed:

- [x] **SSR entry.** `resources/js/ssr.tsx` mirrors `app.tsx` (TooltipProvider + AuthModalProvider + Toaster wrap, same layout switch). Uses `createServer` from `@inertiajs/react/server` + `ReactDOMServer.renderToString`. Critically does **NOT** call `configureEcho` — Echo's WebSocket client is client-only and would crash Node. Echo stays in `app.tsx`.
- [x] **Hydration fixes** for six surfaces that rendered different content on server vs client first paint (throwing console warnings + brief visual flicker). Pattern applied to all six: initialize state to a stable neutral value during render, sync to the real value (`localStorage` / URL / `Date.now()`) inside a `useEffect` on mount.
    - [x] `components/match/match-timer.tsx` — `useState(() => Date.now())` → null + `--:--:--` placeholder + mount sync
    - [x] `components/match/waiting-for-game-card.tsx` — two `useState(() => Date.now())` calls → null pair + mount sync
    - [x] `components/site/player-sidebar.tsx` — read localStorage during render → default false + mount sync (write moved into toggle handler to avoid clobbering on first paint)
    - [x] `components/site/unverified-chip.tsx` — `useState(() => readCooldownRemaining())` → 0 + mount sync
    - [x] `components/profile/profile-tabs.tsx` — `useState(readTabFromUrl)` → DEFAULT_TAB + mount sync
    - [x] `components/auth/auth-modal-provider.tsx` — read URL + DOM during render → `{open: false, view: 'login'}` + mount sync
- [x] **SSR URL env-driven.** `config/inertia.php` now reads `env('INERTIA_SSR_URL', 'http://127.0.0.1:13714')`. `.env.example` documents `INERTIA_SSR_URL=http://ssr:13714` so the Docker sidecar service name resolves inside the `laravel.test` container.
- [x] **`compose.yaml` SSR sidecar.** New `ssr` service on the `sail-8.5/app` image running `php artisan inertia:start-ssr`. Profile-gated (`profiles: [ssr]`) so it doesn't auto-start before a bundle exists — `inertia:start-ssr` exits without a bundle and a profile-less service would surface as a confusing "exited" container in `docker compose ps`. Production deploys drop the profiles block.
- [x] **Cross-platform pnpm install.** `pnpm-workspace.yaml` now declares `supportedArchitectures` for both `current` and `linux` / `arm64` / `x64`. Without this, a Mac-host `pnpm install` only hoists darwin native bindings, so Vite (which uses rolldown under the hood) crashes inside the Sail Linux container with "Cannot find native binding." Required a `rm -rf node_modules && sail pnpm install` to take effect once.
- [x] **Verification.** `sail npm run build:ssr` produces `bootstrap/ssr/ssr.js` (574 KB). Four spot-checked pages return fully-rendered HTML via the sidecar at `http://ssr:13714`: `/` (71.9 KB), `/listings` (126.5 KB), `/users/testuser` (71.2 KB), `/en/about` (31.5 KB, all CMS markdown headings rendered).

Gotchas / what we learned:

- **Vite hot routing overrides the sidecar.** `Inertia\Ssr\HttpGateway::dispatch()` checks `Vite::isRunningHot()`. If `public/hot` exists, SSR goes to Vite's `/__inertia_ssr` endpoint instead of the sidecar — and Vite dev SSR isn't actually wired up in this project, so requests fail silently and Inertia falls back to client rendering. **For local SSR verification, kill `npm run dev` and `rm public/hot` first.** Dev workflow stays as-is (HMR + client render); SSR is a production / verification concern.
- **Audit pre-work that didn't need touching:** `use-mobile.tsx` (uses `useSyncExternalStore` with explicit `getServerSnapshot`), `use-current-url.ts`, and `wayfinder/index.ts` are fully SSR-safe via existing `typeof window === 'undefined'` guards. Every other browser-API usage in the codebase is safe by location (inside `useEffect` / event handlers / callbacks, never during render) — `crypto.randomUUID()` in `use-match-chat.ts`'s send callback, `document.createElement('canvas')` in `avatar-crop-modal.tsx`'s save handler, `window.history.back()` in `back-link.tsx`'s click handler, all `navigator.clipboard.writeText` usages, etc.
- **Tolerable edge case not fixed:** `site-footer.tsx`'s `new Date().getFullYear()` only mismatches at midnight UTC on Dec 31. Negligible.

**Phase 3 follow-ups** ✅ Shipped 2026-05-30 (code) — image asset still pending

The "nice link previews" payoff of SSR. Shipped:

- [x] `APP_NAME=Stakly` set in `.env` (and `.env.example`).
- [x] Always-present structural meta tags in `resources/views/app.blade.php` (canonical link, `og:type`, `og:site_name`, `og:url`, `og:image`, `og:image:width`/`height`, `twitter:card`, `twitter:image`). Live OUTSIDE the `<x-inertia::head>` slot because Inertia's SSR replaces the slot's contents entirely — anything inside is fallback for when SSR is off.
- [x] Shared `PageMeta` React component (`resources/js/components/site/page-meta.tsx`) that wraps Inertia's `<Head>` with a typed API + `head-key` dedup for: `<title>`, `meta[name=description]`, `og:title`, `og:description`, `og:image`, `twitter:title`, `twitter:description`, `twitter:image`, `robots` (noindex), `og:type` (overrides website default).
- [x] Global TS augmentation in `resources/js/types/global.d.ts` so `head-key` is type-clean on every JSX element.
- [x] Public pages with PageMeta — homepage, listings index, listing detail (dynamic per-listing title + description including creator handle, stake, time controls, platform, skill range, completion stats), profile (dynamic title + description; backend-provided `og` payload enriched with `bio` fallback + stats-based fallback), CMS pages (dynamic title + description stripped from rendered HTML; payload caches alongside the rest).
- [x] Private / auth-flow pages flagged `noindex,nofollow` — `match/*`, `wallet/*`, `settings/*`, `listings/mine`, `listings/create`, `auth/{confirm-password,two-factor-challenge,reset-password}`.
- [x] Verification: `/`, `/listings`, `/listings/{id}`, `/users/{username}`, `/en/about` all return SSR HTML with the full meta block (canonical + structural defaults + page-specific og:/twitter:/description). Per-page canonical URL varies correctly. Backend cache cleared so old `cms/page` payload shape is rebuilt with the new `description` field.

Outstanding (asset-only — not a code task):

- [ ] Drop a 1200×630 `public/og-image.png` for link-preview cards. The tag is already wired; without the file, link previews show title + description but no image. Brand-design task — could be plain dark-bg "Stakly" wordmark on the pink→purple gradient, or a more designed card.
- [ ] Public-URL preview check (paste an ngrok / staging URL into Discord, Twitter, Slack and see the rich card render). `localhost` URLs can't reach external link-preview bots.

**Phase 4 — Full-site i18n (UI strings + locale switcher + multi-locale CMS rows)**

Decision pivot: instead of waiting for a second language before shipping the switcher, build full i18n infrastructure now. The whole Stakly site (UI strings, CMS pages, validation messages) becomes translatable. Initial active locales planned: `en`, `ka` (Georgian), `ru` (Russian). Framework supports adding more as a content task.

Design decisions taken into this phase:

- **URL: path prefix everywhere.** `/en/listings`, `/ka/listings`, `/ru/listings`. Unprefixed routes (`/listings`) → 301 redirect to `/{defaultLocale}/listings` (cookie-remembered if user has switched before, else `en`). Cleanest for SEO and link sharing. Matches M26 P1's existing `/{locale}/{slug}` CMS pattern — the whole app now uses the same shape.
- **Tech: Laravel-native bridge, NOT `react-i18next`.** Store strings in standard `lang/en.json`, `lang/ka.json`, `lang/ru.json`. `HandleInertiaRequests::share()` exposes the active locale's bag as a shared Inertia prop. React `useT()` hook reads from it. One source of truth — `__('Create listing')` in PHP and `t('Create listing')` in React both read the same file. Backend strings (validation, future M20 notification emails) work out of the box because Laravel already uses `lang/*.json`. Swap to `react-i18next` later if we ever need ICU plural rules or lazy-loaded locale bundles; call sites change but translation files port cleanly.
- **`URL::defaults(['locale' => ...])` keeps Wayfinder generators clean.** Middleware sets the URL default at request boundary so `route('listings.index')` and `index().url` auto-prefix without per-call-site changes. No Wayfinder regen needed.
- **Filament admin stays unprefixed and English-only.** `/admin/*` is internal, single-language. No locale switcher in admin chrome. Reduces surface area and admin training.
- **CMS pages translate per-locale.** Schema already supports it (M26 P1 baked in `locale` + UNIQUE `(slug, locale)`). Filament resource gets a locale select + filter so admin writes one row per (slug, locale). `PageController::show` queries current locale with fallback to `en`.
- **User-generated content (listing notes, bios, chat) NOT translated.** Shown in whatever language the user typed in. Machine translation (DeepL / Google) is a future polish if ever needed.
- **Detection: no `Accept-Language` sniff.** Always default to `en` on first visit. User picks via switcher, cookie remembers. Avoids surprise redirects, simpler edge cases with VPNs / bots / crawlers / SEO.
- **Translation labor is a content task, not engineering.** Framework ships either way. `lang/ka.json` and `lang/ru.json` start mostly empty; Laravel falls back to the key (English) when a translation is missing, so the site stays usable while copy is written.

Sub-phases:

**P4 Slice A — Foundation (no UI changes)**

- [ ] `SetLocale` middleware reads `{locale}` from URL, calls `App::setLocale()`, sets `URL::defaults(['locale' => ...])`.
- [ ] `RedirectUnprefixedLocale` middleware: any web request without a locale prefix → 301 to `/{defaultLocale}/<path>` (cookie-aware default, fallback `en`).
- [ ] All web routes wrapped in `Route::prefix('{locale}')->whereIn('locale', ['en','ka','ru'])->group(...)`. Admin (Filament), API (if added), and Reverb WS routes stay unprefixed.
- [ ] `lang/en.json` populated with a starter set; `lang/ka.json` + `lang/ru.json` empty (Laravel falls back to key).
- [ ] `HandleInertiaRequests::share()` adds `translations` (cached per locale), `locale` (current), `availableLocales` (list with native labels).
- [ ] React `useT()` hook with `:name` interpolation.
- [ ] Dynamic `<html lang="{$locale}">` in `app.blade.php` + `og:locale` + `<link rel="alternate" hreflang="...">` per supported locale.
- [ ] Tests: middleware behavior, redirect for unprefixed requests, unsupported locale → 404, cookie-remembered default.

**P4 Slice B — CMS multi-locale**

- [ ] Filament `PageResource` gets locale `Select` on form, locale column on table, locale filter.
- [ ] `PageController::show` already uses `forSlugWithFallback($slug, $locale)` from M26 P1 — verify it picks current locale and falls back to `en` when row missing.
- [ ] Seeder writes `About` in `en` + stubs `ka` + `ru` versions (or leaves them missing to exercise fallback path).
- [ ] Tests assert: Georgian request gets Georgian row if present, English fallback if not, 404 only when no locale's row exists.

**P4 Slice C — Switcher UI + first string extraction**

- [ ] `LocaleSwitcher` dropdown in `SiteHeader` — native labels (English / ქართული / Русский). On change: set `stakly:locale` cookie + `router.visit('/{newLocale}/{currentSlug}', { preserveScroll: true })`.
- [ ] Extract strings from `SiteHeader`, `SiteFooter`, `MarqueeStrip`, `Hero`, `GameSelector` into translation keys.
- [ ] Smoke test the full loop: switch to `/ka`, see Georgian where keys are translated, English fallback elsewhere.

**P4 Slices D+ — Page-by-page extraction (one slice per area, each its own commit)**

- [ ] Listings (index + detail + create + mine + filters).
- [ ] Profile (header + tabs + match history + listings section).
- [ ] Match (show + chat + banners + waiting card + settled card).
- [ ] Wallet (index + deposit + withdraw + history).
- [ ] Settings (profile + security + linked accounts).
- [ ] Auth flows (login + register + forgot/reset password + 2FA + email verification).
- [ ] Validation messages + flash toasts + error pages.

Translation labor (writing `lang/ka.json` and `lang/ru.json` content) tracked separately as content backlog; engineering treats those files as drop-in.

### Not in M26

- Page versioning / draft history. Single live row per `(slug, locale)` plus `updated_at` is sufficient signal; if legal needs an audit trail of changes, revisit then.
- Rich-text editor with image uploads. Markdown is enough for these pages. If a future content type needs images, that's a separate decision.
- Wildcard `/p/{slug}` routing. Hardcoded routes per page keep the URL space disciplined.
- Localised admin UI. Filament admin stays in English regardless of the content language.

---

## M27 — In-app notifications + action-required UX + sound

The synchronous in-app channel that complements M20 (email, asynchronous). Today Stakly has no player-facing notification surface — if Bob takes Alice's listing while she's offline, and Alice visits Stakly later without checking email, she has no visible signal anything happened until she navigates to `/matches`. M27 closes that gap with a bell in `SiteHeader` + real-time push via Reverb + a sound for high-priority events. Symmetric on the admin side — `OpsOverview` gets SLA-aware surfaces so old disputes can't be ignored accidentally.

The `notifications` table already exists (it shipped with M12's `NotifyAdminsAction` for the Filament admin bell). M27 reuses that table via Laravel's `database` notification channel; M20 layers `mail` channel on top later.

### Design decisions taken into this milestone

- **Real-time via Reverb + Laravel Echo + private per-user channel.** Already in the stack. No polling fallback. Sub-second push is what makes sound notifications usable — a 30s polling delay would feel broken.
- **Sound default is narrow, sound preference is per-event.** Defaults to ON for `listing_taken` only (`PlayerNotification::SOUND_DEFAULT_EVENT_TYPES`) — the only event where the user is reliably away from the page and needs to come back NOW. The other 4 configurable events (`match_settled`, `match_manual_review`, `dispute_opened`, `cancellation_requested`) default OFF, but the user can opt them in via /settings/notifications Sound checkboxes. The 4 informational events (`listing_expired`, `dispute_resolved`, `cancellation_accepted`, `cancellation_rejected`) are non-configurable + always silent — pings, not signals.
- **Multi-tab sound coordination via `BroadcastChannel`.** If a user has multiple Stakly tabs open, only the first tab to receive the broadcast plays the sound — the others suppress. Prevents triple-ding when one event lands. (Phase 2 work.)
- **Notification classes designed to support `mail` channel from day one** even though M27 only lights up `database` + `broadcast`. M20 wires the Blade templates later without touching dispatch sites or class signatures.
- **Sound toggle lives on the preferences page (M27 Phase 5)**, alongside per-event in-app and email toggles. Default: `ListingTaken` sound ON for everyone, no other event has a sound toggle because no other event plays sound.
- **Mandatory events cannot be silenced.** "Settled — you won/lost" and "Cancellation request awaiting response" are operational, not informational — turning them off would let users miss money-affecting events. UI greys those toggles.
- **Coexist with Filament admin bell in the same `notifications` table** via the `type` discriminator. Filament reads `data->>'format' = 'filament'`; player notifications have `type = 'App\Notifications\XxxNotification'` and no `format` key, so each consumer queries its own subset. No schema changes, no migration of existing admin notifications.
- **`ShouldQueue` from day one.** `broadcast` channel hits Reverb over HTTP — a sync dispatch on a Reverb hiccup would fail the originating user's action (e.g. TakeListing). Queued notifications process in 100-500ms via Redis worker; user-action state (match created, listing taken) remains synchronous.
- **After-commit dispatch, never inside transactions.** Mirrors the existing `OpenDisputeAction::notifyAdminsOfDispute()` pattern — inside the transaction, a notification would fire even on rollback, pointing the bell at a match that "didn't happen." Settle Actions return an outcome marker from their `DB::transaction` closure; the outer `handle()` dispatches notifications after commit.

### Phases

**Phase 1 — Notification dispatch infrastructure** ✅ Shipped 2026-05-30

Scope expanded from 7 → 9 notification classes during P1 (added `DisputeResolvedNotification` so the admin-intervened path reads distinctly from auto-settle, and `ListingExpiredNotification` since the existing `ExpireListingAction` scheduler had no user signal beyond a wallet ledger row).

- [x] `App\Notifications\PlayerNotification` abstract base — handles `via(['database','broadcast'])`, `toDatabase()`, `toBroadcast(BroadcastMessage)`, assembles uniform payload `{event_type, title, body, action_url, related_id}`. Subclasses implement 5 abstract methods. `ShouldQueue` so Reverb hiccups don't fail user actions.
- [x] 9 concrete notification classes — `ListingTaken` (creator), `MatchSettled` (both), `MatchManualReview` (both), `DisputeOpened` (opponent of opener), `DisputeResolved` (both), `CancellationRequested` (opponent), `CancellationAccepted` (original requester), `CancellationRejected` (original requester), `ListingExpired` (creator).
- [x] After-commit dispatch wired into 11 Actions:
    - `TakeListingAction` → `ListingTakenNotification` to creator
    - `SettleFromCardAction` → `MatchSettledNotification` to both (won/lost branch + draw branch)
    - `AdminSettleToWinnerAction` → `MatchSettledNotification` to both (admin manual settle of ManualReview)
    - `AdminSettleDrawAction` → `MatchSettledNotification` to both (admin manual draw)
    - `ResolveDisputeAction` → `DisputeResolvedNotification` to both (Confirmed + Drawn branches) OR `MatchManualReviewNotification` to both (Unknown branch)
    - `ResolveMatchTimeoutAction` → `MatchManualReviewNotification` to both (alongside existing admin Filament bell)
    - `OpenDisputeAction` → `DisputeOpenedNotification` to opponent (alongside existing admin Filament bell)
    - `RequestCancellationAction` → `CancellationRequestedNotification` to opponent
    - `AcceptCancellationAction` → `CancellationAcceptedNotification` to original requester
    - `RejectCancellationAction` → `CancellationRejectedNotification` to original requester
    - `ExpireListingAction` → `ListingExpiredNotification` to creator
- [x] `SettleMatchAction::computeWinnerPayout($stake)` public static helper so calling Actions can render payout in `MatchSettledNotification` / `DisputeResolvedNotification` body without duplicating the bcmul chain.
- [x] Tests: 14 Pest tests in `tests/Feature/Notifications/PlayerNotificationsTest.php` — one per (Action, expected_notification, expected_recipient) tuple.
- [x] Existing 877 tests stay green — Action signature changes (transaction return shape on `SettleFromCardAction`, `ResolveDisputeAction`, cancellation Actions) are internal; public `handle()` signatures unchanged.

Gotchas / what we learned:

- **`routes/channels.php` already has the `App.Models.User.{id}` channel auth** — Laravel auto-creates it in the starter kit. No additional channel registration needed for broadcast notifications.
- **`Inertia\Ssr\HttpGateway::dispatch()` Vite-hot routing was a separate concern** (M26 P3 gotcha) — unrelated to notification broadcasts which go directly to Reverb.
- **Postgres `LIKE` on the `type` column doesn't work cross-driver** — `\%` in a Postgres LIKE pattern means literal `%` because `\` is the default escape character, so `'App\\Notifications\\%'` matched nothing. Switched the player/admin discriminator to `whereNotNull('data->event_type')` (every `PlayerNotification::payload()` emits `event_type`; Filament admin rows don't). Database-agnostic + survives namespace refactors.
- **CSRF for the bell's mutation endpoints uses the `XSRF-TOKEN` cookie**, not a `<meta name="csrf-token">` tag. Inertia/Laravel already set the cookie; bell's `postJson()` helper reads it and sends as `X-XSRF-TOKEN` header. No meta tag was added.
- **`SoundPriority` enum was wrong abstraction — removed during P5 testing.** Original P1 design declared a per-class `SoundPriority` enum (`Urgent | Soft | None`); the frontend hook gated playback on `priority !== 'none'`. P5 then exposed per-event Sound checkboxes — but those checkboxes were silently no-ops on 4 of 5 events because the classes still returned `None`. Two unrelated concepts had collided: backend "event urgency" and the user's chime choice (the user-facing values `classic / soft / ding` even share the word "soft" with the enum but mean an audio file). Fix: deleted the enum, the abstract `soundPriority()` method, and the `sound_priority` payload field. Sound playback is now gated only by (a) user's `notification_sound !== 'off'` and (b) the per-event `notification_sound_map[event_type]` preference. Default audibility lives in `SOUND_DEFAULT_EVENT_TYPES = ['listing_taken']` — same outcome for new users, but the rest of the events now respect the user's checkbox.

**Phase 2 — Bell UI in `SiteHeader` + real-time + sound**

- [x] Bell icon + unread count badge in `SiteHeader` (auth-gated — anonymous visitors see no bell). Popover on desktop, Sheet on mobile via `useIsMobile`.
- [x] Dropdown with last ~15 notifications, each linking to its `action_url`. "Mark all read" affordance. "View all" → full notifications page. Optimistic local-state mark-read on click.
- [x] Full notifications page at `/notifications` — paginated list, all notifications, expanded card rows with event icon + title + body + timestamp + read indicator. All / Unread filter chips with filter-aware optimistic mark-read. Mark-individual (click) + mark-all controls.
- [x] Laravel Echo subscribed to `App.Models.User.{id}` via `useEchoNotification` (kept the default channel rather than the originally-drafted `private-users.{id}` — the channel auth already exists in `routes/channels.php`, zero overrides needed). On broadcast: increment badge + prepend dropdown entry + invoke sound playback hook.
- [x] Sound assets in `public/sounds/` — three files needed: `classic.mp3`, `soft.mp3`, `ding.mp3` (user picks which one plays via /settings/notifications). Free, royalty-clear, short (<1s) chimes. Hook ships ready — pending file commit.
- [x] `useNotificationSound` hook reads the user's `notification_sound` choice off Inertia share, plays the matching file via `new Audio('/sounds/${choice}.mp3').play()`. Multi-tab coordination via `BroadcastChannel('stakly:notification-sound')` — claiming tab posts `{type:'claim', at:ts}`; tabs receiving a claim within 500ms suppress.
- [x] Browser autoplay policy handled implicitly — by the time a notification lands, the user has interacted with Stakly at least once.
- [x] **`NotificationProvider` context** mounted in `SiteLayout` holds the unread count (initial from `auth.user.unread_notifications_count`, bumped on broadcast, cleared on bell open) and the `lastBroadcast` Notification (signal for the dropdown to prepend). The Echo subscription lives in an inner `AuthedNotificationProvider` so the hook only mounts for authed users. Re-syncs from Inertia share on every navigation (server is authoritative on multi-tab mark-read).

**Phase 3 — Action-required banners on match pages** ✅ Shipped 2026-06-02

Full-width status banners at the top of `match/show.tsx`, mutually exclusive by status. Visible whenever the player views the match page, independent of bell state — the banner is the source of truth for "what does this player need to do here."

- [x] **`CancellationRequestBanner`** — Pending matches with an open cancellation request. Two viewer-aware variants in one component: `RequesterWaitingBanner` (Clock icon, "Cancellation request sent" + opponent name) for the requester, `RespondBanner` (Handshake icon, requester name in title, Accept/Decline buttons with processing states) for the opponent. Both render the reason in a card below the body. Shipped pre-M27 as part of the cancellation flow.
- [x] **`AdminReviewBanner`** — Disputed + ManualReview matches. Three copy variants via a single `resolveCopy(match, viewerId)` helper:
    - `manual_review` → "Match flagged for admin review" (auto-flag, no human opener).
    - `disputed` + viewer opened it → "You reported a problem" + escrow + evidence prompt.
    - `disputed` + opponent opened it → "{Opener name} reported a problem" + same prompt. Opener resolved client-side from `match.dispute.opened_by_id`, matched against `creator.id` / `taker.id`.
    - All three carry a "Post evidence in chat" outline button that dispatches a `stakly:focus-chat` window event.
- [x] **Chat-focus mechanism via `window` custom event `stakly:focus-chat`** — banner fires the event; `ChatInput` always listens and focuses + scrollIntoView's its textarea; `MobileChatTrigger` listens only when `useIsMobile()` is true and opens the Sheet first, then re-fires the event 250ms later so the freshly-mounted inner `ChatInput` catches it. `open` guard breaks the re-dispatch loop.
- [x] **`GameMatchResource` exposes `dispute.opened_by_id` + `dispute.opened_at`** so the frontend can do the viewer-aware split. Backend columns existed since dispute flow shipped; just weren't on the resource.
- [x] **Tests**: 4 new Pest feature tests in `tests/Feature/GameMatchShowTest.php` cover the dispute resource shape — fresh match nulls, Disputed opened by creator (creator id surfaced), Disputed opened by taker (taker id surfaced), ManualReview (null opener — auto-flag). UI rendering assertions are not part of this slice because Stakly's test suite is Pest-only; the React side has no Vitest/RTL setup. The component is small, pure, and exercised manually + via the resource-shape contract above.

Gotchas / what we learned:

- **Mobile chat-focus needs a re-dispatch.** On mobile, `MobileChatTrigger`'s ChatInput isn't mounted while the sheet is closed — the in-input event listener doesn't exist yet, so a direct dispatch from the banner would no-op. The trigger listens for the same event, opens the sheet (state change → render → ChatInput mounts), and re-fires the event after a 250ms delay so the now-mounted listener picks it up. The `if (open) return` guard short-circuits the re-fire on the second pass so we don't loop.
- **`useIsMobile()` gating on the trigger's listener is required** — without it, on desktop the trigger's listener would still fire and `setOpen(true)` the Sheet (which renders via portal regardless of the `lg:hidden` wrapper), causing the sheet's ChatInput to ALSO claim focus and steal it from the always-mounted desktop ChatInput.
- **React 19 forwards refs through function components by default.** No `forwardRef` needed on the Textarea primitive — passing `ref={textareaRef}` to `<Textarea>` flows through to the underlying `<textarea>` via `{...props}`. Saved a primitive rewrite.

**Phase 4 — Admin SLA surfaces** ✅ Shipped 2026-06-02

- [x] **`OpsOverview` → new "Aging disputes (≥6h)" stat** alongside the existing "Open disputes" stat. Stat value = count of Disputed + ManualReview matches whose aging timestamp is ≥6h old. Description + color escalation:
    - 0 aging → green / "No aging disputes"
    - 1+ in 6h–12h window → amber / "N between 6h–12h"
    - 1+ over 12h → red / "N over 12h"

    The two dispute stats render side-by-side on row 1 of the dashboard (`getColumns() = 2`) so admins see "total" + "aging" together.
- [x] **Aging timestamp = `COALESCE(dispute_opened_at, updated_at)`** — Disputed matches have `dispute_opened_at` set, and ManualReview routed from a Dispute-Unknown branch also has it. ManualReview matches from match-timeout (no dispute event ever fired) fall back to `updated_at`, which corresponds to when the status flipped to MR. One consistent aging field across both statuses.
- [x] **`GameMatchesTable` default sort: newest first** (`created_at DESC`). The earlier P4 iteration tried "oldest unactioned at top" via a COALESCE expression, but admin browsing UX consistently wants the most recent at the top — the SLA cues live in the Age column's color badge + the OpsOverview "Aging disputes" stat, not in the row ordering. Click-sort on the Age column gives admin oldest-first when they want to triage.
- [x] **Per-row age badge** — the existing `dispute_opened_at` column is relabeled "Age" and rendered as a colored `->badge()` with the same SLA scale as the OpsOverview widget (success / warning / danger at the same 6h / 12h thresholds). Non-dispute rows (when admin widens the filter past the default) render `'gray'` and a `—` placeholder. Color resolver is `GameMatchesTable::ageBadgeColor()` — kept inside the table class so the SLA scale lives in one place.
- [ ] (Deferred) Slack / Discord webhook to admin channel when a dispute crosses the 12h `danger` threshold without action. Out of scope for now; opens a follow-up if email-to-admin pings prove too quiet in practice.
- [x] **Tests**: 4 new Livewire tests in `tests/Feature/Admin/DashboardWidgetsTest.php` covering the aging-disputes stat (zero / between-6h-12h / over-12h / MR-from-timeout fallback to updated_at). 1 new test in `tests/Feature/Admin/GameMatchResourceTest.php` asserting `assertCanSeeTableRecordsInOrder([oldest, middle, newest])` for the default-sort behavior.

Gotchas:

- **Filament 4 `defaultSort()` accepts a Closure.** When the sort key isn't a simple column (we need `COALESCE(dispute_opened_at, updated_at)`), pass a `fn (Builder $q) => $q->orderByRaw(...)` instead of column name + direction. The closure form is documented but easy to miss; the column-name form would have required a virtual column on the model.
- **`getColumns(): int` controls the stats-row wrap.** Adding a 5th stat to a 2-column grid produces a 2 / 2 / 1 layout (the last stat alone in row 3). Acceptable here because "active users" sits alone on row 3 cleanly. If we add another stat later, bump to 3 columns or shuffle the pairing.

**Phase 5 — Preferences UI (shared surface with M20)** ✅ Shipped 2026-06-02

Final design landed after three iterations (icon-tile + custom Switch → single combined card → Dribbble-style **matrix grid**, which is what shipped).

- [x] New `/settings/notifications` page — sub-header with title + two pill bulk-action buttons (Switch off all / Email only — one-shot client-side state mutations, respect mandatory In-app), then **three cards** using a shared row pattern: card header strip (title + description) + divider + event/choice rows underneath.
    - **Match activity** card — Listing taken, Match settled (locked), Cancellation requested (locked).
    - **Disputes & moderation** card — Dispute opened, Match flagged for review.
    - Each event row = event name (+ Lock icon for mandatory events with tooltip "Required — affects your money. Can't be silenced.") + 3 checkbox+label columns: **In-app / Sound / Email**.
    - **Sound** card — 4 radio rows (Off / Classic / Soft / Ding) with contextual lucide icons (`VolumeX`, `Bell`, `Music`, `BellRing`); each non-Off row has a ▶ preview button.
- [x] Checkboxes use a custom `components/ui/checkbox.tsx` rebuilt on the **native peer pattern** — `<input type="checkbox" className="peer sr-only">` + sibling box `<span>` + sibling lucide `<Check>`, all wrapped in a `relative inline-flex`. State driven entirely by Tailwind `peer-checked:` / `peer-focus-visible:` / `peer-disabled:` modifiers. 16px box, no motion. Public API (`checked` / `onCheckedChange` / `disabled` / `aria-label`) is shadcn-compatible.
- [x] Per-event preferences scoped to the 5 main events: `listing_taken`, `match_settled`, `match_manual_review`, `dispute_opened`, `cancellation_requested`. The other 4 (`listing_expired`, `dispute_resolved`, `cancellation_accepted`, `cancellation_rejected`) always fire and aren't user-configurable — they're after-the-fact informational pings, not signals that need a mute toggle. `PlayerNotification::CONFIGURABLE_EVENT_TYPES` is the source of truth.
- [x] Email column is freely togglable. The backend records the preference today; delivery activates with M20 (no UI placeholder gating — just an honest "set it now, mailer ships later" model).
- [x] Schema: `notification_preferences` table — `user_id` FK cascade, `event_type` string, `in_app` + `sound` + `email` booleans, UNIQUE `(user_id, event_type)`. Per-event `sound` toggle gates whether the chime fires for that event; the global `users.notification_sound` choice (Off/Classic/Soft/Ding) decides which file plays. Lazy default policy in code instead of seeding rows on user creation — `PlayerNotification::defaultPreference($eventType)` returns the default (sound defaults ON only for `listing_taken`); `User::getNotificationPreference` returns the DB row if present, else the default. Avoids backfill and keeps the table sparse.
- [x] Sound choice picker — `users.notification_sound` string nullable column on users. Four valid values: `off | classic | soft | ding` (validated server-side via `Rule::in(PlayerNotification::SOUND_CHOICES)`). `useNotificationSound` reads `auth.user.notification_sound` (defaults to `'classic'` when null) and skips playback entirely when the choice is `off`. The per-event `sound` preference is shared as `auth.user.notification_sound_map` and checked by `NotificationProvider` before calling the hook — `false` (explicitly muted) suppresses, `true` or missing plays normally. Per-sound preview button on the settings page plays the file directly via `new Audio(url).play()`. **Sound files** (`public/sounds/{classic,soft,ding}.mp3`) are pending commit by the user — picking actual royalty-free chimes is a taste call.
- [x] Backend enforcement: `PlayerNotification::via()` returns `[]` for muted optional events (suppresses both database + broadcast channels). Mandatory events bypass the preference unconditionally.
- [x] Cleanup: the abandoned `components/ui/switch.tsx` and `components/ui/radio-group.tsx` (built for earlier P5 iterations) are deleted — both confirmed unreferenced before removal.

### Cross-milestone notes

- **M20** plugs into M27's notification classes by writing Blade email templates + wiring SMTP config. The dispatch layer is reused as-is. M20's preferences UI piggybacks on M27 Phase 5's page.
- **M9 (chain integration)** will add `DepositConfirmedNotification` and `WithdrawalProcessingNotification` when it lands. The pattern is established by M27.
- **M13 (chat anti-abuse)** can add `MessageFlaggedForReviewNotification` (admin-side) when it ships, using the same dispatch pattern.
- **M14** ManualReview escalation already exists via `ResolveMatchTimeoutAction` → `NotifyAdminsAction`; M27 P4 surfaces it as an SLA-tracked dashboard widget rather than just a single admin bell ping.

### Not in M27

- Mobile push (APNS / FCM). Web push (browser Notification API) is also out of scope — `Notification.requestPermission()` introduces a permission-prompt UX that's worth handling deliberately, not bundling into the in-app milestone.
- SMS notifications. Different channel, different milestone if ever needed.
- Email channel. M20 owns that end-to-end; M27 just makes sure the dispatch layer supports it without rework.
- Notification analytics / read-rate tracking. Premature.
- Per-tab focus-aware sound suppression (i.e. "don't ding the tab the user is actively looking at"). The `BroadcastChannel` coordination already prevents the triple-ding case; layering "is this tab focused" on top is polish that can wait for user feedback.

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

