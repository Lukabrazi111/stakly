# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M8 all phases, M10, M11, M12 all phases, M14 Slice A, M16 all phases, M17, M18, M19, M22, M23, M24, M25, M27 all phases, M29 all phases, M30 all phases, M31 all phases, M32 all phases). **Parked milestones** (work that isn't being picked up right now) also live in the archive — currently M13. This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Recently shipped** (this week):

- **M30** — Admin user management (all 6 phases, 2026-06-03 → 2026-06-04). UserResource, ban toggle + four enforcement guards, mandatory 2FA on admin role, on-every-login 2FA challenge via Fortify bridge, user-facing ban feedback (banner + bell + email), impersonation via `stechstudio/filament-impersonate` + Stakly audit/reason/expiry layer.
- **M31** — Admin wallet ledger (both phases, 2026-06-04). Read-only `WalletTransactionResource` with filters + sum summarizer, ViewWalletTransaction with infolist + reference-ID parser + sibling-entity lookup.
- **M32** — Admin listing management (both phases, 2026-06-04). Read-only `ListingResource` with status/platform/creator/stake/region/language filters, ViewListing with infolist (details + related match if Taken + wallet transactions via M31 parser) + force-cancel action routed through `CancelListingAction`. **Admin trio now complete — every state on the platform is investigable + actionable from `/admin` without Tinker.**

**In-flight:**

- **M26 Phase 4** — Full-site i18n. Slices A/B/C ✓ 2026-06-04 + global `URL::defaults` fallback ✓ 2026-06-05. **Slices D-1 through D-13 (Listings + Profile + Match + Wallet + Notifications + Settings + Auth + BannedBanner + Validation/flash/errors) ✓ 2026-06-05** — listings (D-1..D-5), public profile (D-6), match (D-7a..D-7e), wallet (D-8), notifications (D-9), settings (D-10), auth (D-11), BannedBanner (D-12), error pages + validation audit (D-13). **M26 P4 engineering complete** — remaining work is translation content (`lang/ka.json` / `lang/ru.json`) which is a content backlog, not engineering. Translation labor (`lang/ka.json` / `lang/ru.json` content) tracked separately as a content backlog.

**Active / upcoming** (after M26):

- **M28** — Designed Fees page. Hand-coded marketing surface — transparent 5–10% commission disclosure, interactive calculator, replaces footer Support link in header nav. Highest-leverage pre-launch trust signal; design-driven (`ui-ux-pro-max` skill).
- **M14** — Outcome pipeline hardening. Slice A + Phase 1 shipped; **Phases 2 (reliability — retries / rate-limit awareness / circuit breaker), 3 (coverage — aborted games / multi-candidate disambiguation / time-control mismatch), 4 (dispute fast-path)** remain. Production-critical for the settlement engine.
- **M20** — Email notifications. **Spec materially shrunk**: M27 P5 already shipped the in-app preferences UI + `notification_preferences` table + 9 `PlayerNotification` classes; M30 P4 wired the `mail` channel for ban notifications. What's left = branded HTML email templates, flip `'mail'` into `via()` on the remaining PlayerNotification subclasses, un-disable the Email toggle in `/settings/notifications`, production SMTP config. Realistically 2–3 days.
- **M21** — Blacklist + safety. Block users from listings + chat, with anti-evasion considerations. Has open design questions (block semantics + multi-account evasion) — needs alignment before coding.
- **M15** — Multi-game expansion (FACEIT, OpenDota, Riot adapters). Large; awaits a concrete game push to motivate scope.

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

Decision pivot: instead of waiting for a second language before shipping the switcher, build full i18n infrastructure now. The whole Stakly site (UI strings, CMS pages, validation messages) becomes translatable. Initial active locales: `en`, `ka` (Georgian), `ru` (Russian). Framework supports adding more as a content task.

**Scope estimate:** roughly 1–2 weeks of engineering — Slice A (Foundation) is ~1–2 days, Slice B (CMS) is ~half a day, Slice C (Switcher + first extraction) is ~1 day, Slice D+ (page-by-page extraction across ~7 surface areas) is the bulk. Translation labor (writing `lang/ka.json` and `lang/ru.json` content) is a separate content backlog; engineering treats those files as drop-in.

**Admin (Filament) is exempt from translation.** Per the design decision below, `/admin/*` chrome + every admin resource (M12 disputes, M24 games, M26 pages, M30 users, M31 wallet ledger, M32 listings) stays English-only. Don't accidentally extract Filament strings during Slice D+ extraction passes.

Design decisions taken into this phase:

- **URL: path prefix everywhere.** `/en/listings`, `/ka/listings`, `/ru/listings`. Unprefixed routes (`/listings`) → 301 redirect to `/{defaultLocale}/listings` (cookie-remembered if user has switched before, else `en`). Cleanest for SEO and link sharing. Matches M26 P1's existing `/{locale}/{slug}` CMS pattern — the whole app now uses the same shape.
- **Tech: Laravel-native bridge, NOT `react-i18next`.** Store strings in standard `lang/en.json`, `lang/ka.json`, `lang/ru.json`. `HandleInertiaRequests::share()` exposes the active locale's bag as a shared Inertia prop. React `useT()` hook reads from it. One source of truth — `__('Create listing')` in PHP and `t('Create listing')` in React both read the same file. Backend strings (validation, future M20 notification emails) work out of the box because Laravel already uses `lang/*.json`. Swap to `react-i18next` later if we ever need ICU plural rules or lazy-loaded locale bundles; call sites change but translation files port cleanly.
- **`URL::defaults(['locale' => ...])` keeps server-side `route()` clean; Wayfinder needed `setUrlDefaults` on the client.** Server-side Laravel honours `URL::defaults` natively, so `route('listings.index')` auto-prefixes. Wayfinder generators run client-side though, where they don't see `URL::defaults` — solved by calling `setUrlDefaults(() => ({ locale: currentLocale }))` in `app.tsx`, with `currentLocale` seeded from the initial `data-page` DOM attribute and refreshed via `router.on('success')`. **Caveat:** Wayfinder calls at module-top-level scope run BEFORE this wires up, so nav arrays must be built inside component bodies (Slice A had to fix the settings layout for this). Multi-param routes also stopped accepting positional args once `{locale}` joined the URI — `show(123)` had to become `show({ listing: 123 })` across ~30 callsites.
- **Filament admin stays unprefixed and English-only.** `/admin/*` is internal, single-language. No locale switcher in admin chrome. Reduces surface area and admin training.
- **CMS pages translate per-locale.** Schema already supports it (M26 P1 baked in `locale` + UNIQUE `(slug, locale)`). Filament resource gets a locale select + filter so admin writes one row per (slug, locale). `PageController::show` queries current locale with fallback to `en`.
- **User-generated content (listing notes, bios, chat) NOT translated.** Shown in whatever language the user typed in. Machine translation (DeepL / Google) is a future polish if ever needed.
- **Detection: no `Accept-Language` sniff.** Always default to `en` on first visit. User picks via switcher, cookie remembers. Avoids surprise redirects, simpler edge cases with VPNs / bots / crawlers / SEO.
- **Translation labor is a content task, not engineering.** Framework ships either way. `lang/ka.json` and `lang/ru.json` start mostly empty; Laravel falls back to the key (English) when a translation is missing, so the site stays usable while copy is written.

Sub-phases:

**P4 Slice A — Foundation (no UI changes) ✓ shipped 2026-06-04**

- [x] `SetLocale` middleware reads `{locale}` from URL, calls `App::setLocale()`, sets `URL::defaults(['locale' => ...])`, queues `stakly_locale` cookie, and **strips `{locale}` from the route parameter bag** to defuse a Laravel positional-dispatch bug.
- [x] `RedirectUnprefixedLocale` middleware: GET/HEAD only, exempts Fortify/admin/broadcasting/static/2–3-letter-locale-like first segments, 301 to `/{cookieLocaleOrDefault}/<path>`. Registered GLOBALLY (not web group) because unmatched routes need to redirect.
- [x] `routes/web.php` + `routes/settings.php` wrapped in `Route::prefix('{locale}')->whereIn('locale', config('stakly.locales'))->middleware(SetLocale)`. M26 P1's nested `/{locale}/{slug}` CMS route refactored to `/{slug}` inside the group; obsolete `pages.redirect` deleted.
- [x] `config/stakly.php` — single source of truth for `locales` (`en, ka, ru`), `default_locale`, `locales_meta` (native label + og:locale per code). `Page::SUPPORTED_LOCALES` const → `Page::supportedLocales()` method reading from config.
- [x] `lang/en.json` populated (~35 starter keys); `lang/ka.json` + `lang/ru.json` empty placeholders.
- [x] `HandleInertiaRequests::share()` adds `locale` / `availableLocales` / `translations` **as closures** (Inertia computes share before route middleware runs, so eager values would capture the default 'en').
- [x] React `useT()` / `useLocale()` / `useAvailableLocales()` in `resources/js/lib/i18n.ts`, `SharedData` interface extended.
- [x] Dynamic `<html lang>` + `og:locale` + `og:locale:alternate` + `<link rel="alternate" hreflang>` per supported locale + `x-default` in `app.blade.php`.
- [x] Wayfinder `setUrlDefaults` wired in `app.tsx` — module-level `currentLocale` seeded from initial `data-page` DOM attribute, refreshed on every `router.on('success')`. All ~30 multi-param Wayfinder call sites migrated from positional `route(123)` to object form `route({ paramName: 123 })`.
- [x] Fortify config — `fortify.home` + `fortify.redirects.logout` updated to `/'.config('stakly.default_locale')`; `FortifyServiceProvider` view callbacks redirect to default-locale home.
- [x] Tests — 19 `tests/Feature/I18n/LocaleRoutingTest.php` (67 assertions): prefixed routes 200, unprefixed 301, cookie-aware target, unsupported locale 404, exempt paths (Fortify/admin/health/favicon), Inertia share payload, cookie queue.
- [x] `tests/TestCase.php` `call()` / `json()` override — auto-prefixes test URIs with `/en/` (mirrors the middleware exempt list). Opt-out via `$this->withoutLocalePrefix()`. Kept ~184 existing literal-URL test calls working without churn.

Non-obvious lessons (worth carrying into Slice B+):

- **Never call Wayfinder generators at module-top-level scope.** They run before `setUrlDefaults` and fall back to the literal `'$locale'` placeholder, producing broken hrefs like `/$locale/settings/profile`. Build nav arrays inside the component body. Slice A had to fix `settings/layout.tsx` for this.
- **Active-state matching needs Wayfinder-generated `matchPrefix`, not literals.** Hardcoded `/wallet` no longer matches `/en/wallet`. Use `walletIndex().url` for both `href` and `matchPrefix`.
- **Inertia shared props that depend on the request locale must be closures.** Eager values capture the default 'en' because Inertia's middleware fires `share()` before route-level middleware sets the locale.
- **An unused route param breaks `array_values($parameters)` ordering** in Laravel's controller dispatcher — adding `{locale}` to a route without a matching `$locale` controller param mismatches positional args and surfaces as a `TypeError` on the next model-bound arg. Fix: `forgetParameter('locale')` inside `SetLocale` after reading it, then read `App::getLocale()` in controllers if you ever need it.
- **`Fortify::redirects('logout', '/')` short-circuits on the non-null default**, so `fortify.home` alone doesn't fix logout. Set `fortify.redirects.logout` explicitly.
- **`withCookies()` in tests encrypts by default**; for cookies in the EncryptCookies except list (like `stakly_locale`), use `withUnencryptedCookie()`.
- **`URL::defaults(['locale' => …])` MUST be seeded globally, not only by `SetLocale` middleware.** Middleware only fires on routes inside the locale-prefix group, so Filament admin / queued notifications / console / Octane workers never get the default. The result is a `Missing required parameter for [Route] [Missing parameter: listing]` crash — Laravel positionally binds the scalar arg to `{locale}` because no default fills it first. Fix: `URL::defaults(['locale' => config('stakly.default_locale')])` inside `AppServiceProvider::boot()` as a fallback. SetLocale middleware still overrides per-request via `array_merge`. This also removes the need for the Pest harness band-aid that was previously mirroring the same default in `beforeEach` — once the fallback is global, the test bag works the same as production.

**P4 Slice B — CMS fallback verification ✓ shipped 2026-06-04**

Originally specced as full Filament multi-locale. Trimmed and shipped the minimal verification slice: three Pest tests in `tests/Feature/PageControllerTest.php:204-242` that pin the locale-prefix routing → `forSlugWithFallback` path under the new M26 P4 URL shape. The Filament admin form already has a working locale `Select` (`PageForm.php:43-48`) from M26 P1 so admins can create Georgian / Russian rows today.

- [x] `GET /ka/{slug}` renders the Georgian row when it exists (sanity-check the locale-prefix routing actually reaches `forSlugWithFallback` with the right locale).
- [x] `GET /ka/{slug}` falls back to the English row when no Georgian row exists (the resolver's documented behaviour, re-verified after Slice A's routing change).
- [x] `GET /ka/{slug}` 404s only when neither Georgian nor English exists.

Still deferred (low-priority polish, not blocking):

- Locale **filter** on the `/admin/pages` index table (column is already shown, just no filter widget yet).
- Seeder stubs for ka / ru — drop in once translation copy exists.

**P4 Slice C — Switcher UI + first string extraction ✓ shipped 2026-06-04**

- [x] `LocaleSwitcher` dropdown — `Languages` icon trigger + native-label items (English / ქართული / Русский). Swaps the leading `/{locale}/` segment via `router.visit` with `preserveScroll`. Mounted in `SiteHeader` (desktop, between nav and user controls) and `MobileMenu` (sheet header, next to the Stakly logo). The `stakly_locale` cookie persists automatically — `SetLocale` middleware queues it on every locale-prefixed request, so no cookie write on the client.
- [x] Strings extracted across the chrome — `SiteHeader`, `SiteFooter`, `Hero`, `GameSelector`, `MobileMenu`, and the marquee items (in `site-layout.tsx` — built inside the component so `useT()` resolves against the active locale, not module-load defaults).
- [x] `lang/en.json` expanded from 35 → 63 keys, alphabetised, includes `:year` interpolation for the footer copyright. Footer slugs (`about` / `support` / `terms` / `privacy`) compose with the page's `locale` prop instead of hardcoded `/en/` literals.
- [x] Fixed a Slice A miss — `mobile-menu.tsx` had a module-top-level `navLinks` array that called `listingsIndex()` before `setUrlDefaults` was wired. Moved inside the component body alongside the new `useT()` usage.

Lessons folded back from Slice C:

- **The "no module-top-level Wayfinder" rule applies to translated nav arrays too** — anywhere the chrome builds an `{ label, href }` collection, build it inside the component so both `useT()` and `setUrlDefaults` are populated.
- **Footer / link arrays composed from locale prop**, not hardcoded `/en/` paths. The default-locale URL only stays correct for users in the default locale; everyone else gets a redirect on click.

Smoke test (manual, user-driven):

- [ ] On `/en/`, open header LocaleSwitcher → pick ქართული → URL flips to `/ka/`, `<html lang>` becomes `ka`, copy stays in English (no `ka.json` content yet, expected). Back-arrow returns to `/en/`. Cookie `stakly_locale=ka` set.
- [ ] On `/en/listings/123`, switch to Русский → lands on `/ru/listings/123` (locale segment swapped, path preserved). Switcher highlights the current locale.
- [ ] Mobile menu sheet → switcher renders, swap works, sheet closes naturally on navigation.

**Follow-up polish (post-ship, 2026-06-04):**

- Header restructured into three visual zones (nav · action · account). LocaleSwitcher moved from inline-between-nav-and-account into the account cluster (between Bell and Avatar). Subtle vertical divider added between the Create-listing CTA and the account cluster (authed users only).
- Compact LocaleSwitcher trigger now matches `BellButton` shape exactly — `size-10 rounded-full`, `size-5` icon, same hover (`bg-primary/10` + primary icon) + open-state styling. The account cluster reads as evenly spaced 40×40 circles.
- Dropdown panel refactored to full-width rows: container is `overflow-hidden p-0`, items lose individual `rounded-md`, active row is a `bg-primary/25` edge-to-edge wash + primary check on the right. Hover on inactive rows = `bg-primary/10` wash. Active row's hover/focus locked to its own bg so hovering the current selection doesn't shift colour.
- Mobile placement moved out of the sheet header (was colliding with shadcn `SheetContent`'s built-in close-X button) into a dedicated settings-style row at the bottom of the sheet, above the auth / user-card section. Label "Language" on the left, the trigger on the right.
- Mobile (non-compact) trigger switched from `ghost` variant to `outline` — the ghost variant's baked-in `hover:[text-shadow:var(--text-shadow-glow)]` was combining with custom `hover:text-primary` + `hover:bg-primary/10` and rendering as a loud pink-text-with-white-glow blob. Outline variant has a calmer bordered-pill shape with subtle pink-wash hover.
- First `ka.json` / `ru.json` entries land (`Get started`, `How it works`) — proves the translation loop end-to-end before Slice D+'s bulk extraction.

Lessons folded back:

- **shadcn `DropdownMenuItem` has built-in `hover:bg-primary/10 focus:bg-primary/10`** in its default class. Any custom active state needs an explicit `hover:bg-X focus:bg-X` matching its bg, or the default override fires on hover and shifts the colour.
- **`ghost` variant + `hover:text-primary` is a bad combo** when text is visible. The variant's text-shadow glow stacks with the colour change and reads as a muddy blur. Use `outline` (border + bg-card + bg-primary/10 hover, no text-shadow) when the button has visible label text. Reserve `ghost` for icon-only triggers where the label is `sr-only`.
- **`shadcn SheetContent` ships an absolutely-positioned close-X button at `top-4 right-4`.** Anything placed in the sheet's first content row collides with it. Keep that row brand-only; put utility controls in their own row lower down.

**P4 Slices D+ — Page-by-page extraction (one slice per area, each its own commit)**

- [x] Listings (D-1 index ✓ 2026-06-05 · D-2 detail ✓ 2026-06-05 · D-3 create form ✓ 2026-06-05 · D-4 mine + active-mode ✓ 2026-06-05 · D-5 backend flash + validation ✓ 2026-06-05).
- [x] Profile (D-6 ✓ 2026-06-05 — header + tabs + trust strip + stats card + share/owner sections + listing/match rows).
- [x] Match (D-7a list ✓ 2026-06-05 · D-7b detail chrome ✓ 2026-06-05 · D-7c actions + banners ✓ 2026-06-05 · D-7d chat ✓ 2026-06-05 · D-7e backend strings ✓ 2026-06-05).
- [x] Wallet (D-8 ✓ 2026-06-05 — index + deposit + withdraw + history + 6 components + backend strings).
- [x] **D-9 Notifications ✓ 2026-06-05** — `BellButton` + `BellDropdown` + `NotificationItem` + `NotificationCard` + `NotificationPagination` strings extracted, `/notifications` history page extracted, `/settings/notifications` preferences UI extracted (EVENT_META + EVENT_GROUPS + SOUND_META kept module-level, `t()` resolves at render). Shared `lib/notifications-format.ts` helper for relative-time across bell-dropdown and history-card. All 11 backend notification classes already `__()`-wrapped — their keys (titles, bodies, and email mailable copy) registered in `lang/en.json`. ~96 new keys.
- [x] **D-10 Settings ✓ 2026-06-05** — `layouts/settings/layout.tsx` nav, profile page (+ `ProfilePreview`, `AvatarCropModal`, `UsernameHelper`), security page (password + 2FA gating), linked-accounts page (`VerifiedRow`, `PendingRow`, `RequestForm`), starter-kit `TwoFactorRecoveryCodes` + `TwoFactorSetupModal`. Backend: `LinkedAccountController` flash messages wrapped (verify-code lifecycle + provider-unavailable + sentinels). ~110 new keys.
- [x] **D-11 Auth flows ✓ 2026-06-05** — auth-simple-layout (layout title/description routed through `t()` so page-level static `.layout` props become translation keys), AuthModal (sr-only labels + close + view META), login/register/forgot-password forms (labels + buttons + switcher copy), reset-password + two-factor-challenge + confirm-password pages (incl. `setLayoutProps` memo), `UnverifiedChip` resend label + cooldown messages. Backend: `RegisterResponse`, `EmailVerificationNotificationSentResponse`, `FortifyServiceProvider` reset-link error, `ThrottleVerificationSend` 429 abort. ~42 new keys.
- [x] **D-12 M30 user-facing surfaces ✓ 2026-06-05** — `BannedBanner` extracted (title + "Reason:" label + "Contact support" CTA). `AccountBanned` / `AccountRestored` notification + email copy already wrapped in D-9. `BanGuard::rejectionMessage()` already wrapped in D-10 (and reused by `ListingController`, `ProfileController`, `ChangeUsernameAction`). Free-text admin-typed `ban.reason` stays raw — it's user content from admin, not chrome. ~3 new keys.
- [x] **D-13 Validation + flash + errors ✓ 2026-06-05** — FormRequest `messages()` arrays (ProfileUpdate, OpenDispute, Withdraw) + `ProfileValidationRules` trait already `__()`-wrapped; controller flash-toast audit confirmed zero unwrapped messages remained (D-1..D-12 caught them all). **Inertia error page**: new `pages/errors/error.tsx` (single component, status-driven COPY map for 403/404/500/503) + Inertia `withExceptions` `respond` callback in `bootstrap/app.php` (debug-mode + non-Inertia request bypass; 419 left untouched so CSRF stays on the default reload toast; 422 untouched so field errors render inline). ~14 new keys.

Translation labor (writing `lang/ka.json` and `lang/ru.json` content) tracked separately as content backlog; engineering treats those files as drop-in.

### Not in M26

- Page versioning / draft history. Single live row per `(slug, locale)` plus `updated_at` is sufficient signal; if legal needs an audit trail of changes, revisit then.
- Rich-text editor with image uploads. Markdown is enough for these pages. If a future content type needs images, that's a separate decision.
- Wildcard `/p/{slug}` routing. Hardcoded routes per page keep the URL space disciplined.
- Localised admin UI. Filament admin stays in English regardless of the content language.

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
