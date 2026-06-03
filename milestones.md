# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M8 all phases, M10, M11, M12 all phases, M14 Slice A, M16 all phases, M17, M18, M19, M22, M23, M24, M25, M27 all phases, M29 all phases). **Parked milestones** (work that isn't being picked up right now) also live in the archive — currently M13. This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Active / upcoming:**

- **M14** — Outcome pipeline hardening (reframed from "automated outcome adapters" — observability + reliability + coverage of the auto-fetch pipeline; Slice A + Phase 1 shipped, Phases 2–4 remain)
- **M20** — Notifications (email infrastructure + per-event preferences UI; M20 owns the surface end-to-end)
- **M21** — Blacklist + safety (block users from listings + chat, with anti-evasion considerations)
- **M15** — Multi-game expansion (FACEIT, OpenDota, Riot adapters)
- **M26** — Filament-managed CMS pages (Privacy, Terms, About — multilingual schema, SEO-indexable via global Inertia SSR; Phases 1–3 shipped; small follow-up for og: tags + APP_NAME; Phase 4 locale switcher deferred until a second language ships)
- **M28** — Designed Fees page (transparent commission disclosure, interactive calculator, header nav — hand-coded React, NOT CMS-managed)
- **M30** — Admin user management (Filament `UserResource` — search, view, manual email verify, reset 2FA, ban toggle with real enforcement, mandatory 2FA on admin role, user-facing ban feedback, custom impersonation flow, 2FA challenge on every admin login; closes the biggest support gap in the admin panel + hardens admin access). **Phases 1 + 2 + 3 + 4 + 6 shipped 2026-06-03. Phase 5 (impersonate) remains.** <- (in process)
- **M31** — Admin wallet ledger (Filament `WalletTransactionResource`, read-only — filter / sort / drill into every money movement; the money-audit surface for the custodial platform)
- **M32** — Admin listing management (Filament `ListingResource` — index, filter, force-cancel via the existing `CancelListingAction` so escrow releases cleanly)

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

## M30 — Admin user management

A first-class user moderation + support surface inside Filament. Today the admin panel has zero user UI — moderation, investigation, manual interventions all require Tinker queries. The first time a real user files a support ticket or a chat-abuse report surfaces, the admin needs to investigate without dropping to the shell. M30 closes that gap.

This is the single biggest support gap in the panel today. For a custodial money platform with player-to-player chat, the longer it takes to act on abuse, the worse it gets.

### Design decisions taken into this milestone

- **Always-rendered wallet invariant on the view page.** Compares `users.usdt_balance` to `SUM(wallet_transactions)` and renders a success/danger badge. Should always pass — exists to catch a regression early when investigating a problem user.
- **Ban toggle requires a reason in both directions.** Initial ban captures *why*; lifting captures *why now*. Months later we want a single trail of "what happened" without cross-referencing.
- **Ban actually does something day one — four enforcement guards land in M30.** The spec was originally "M30 ships the column, M21 wires the enforcement" but that leaves the toggle informational. Instead, M30 wires the four guards that actually *stop the bleed*: (1) `ListingController::create + store` rejects banned users so no new abuse-vector listings land; (2) `ProfileController::update` rejects banned users so they can't evade by changing name / avatar; (3) `ChangeUsernameAction` gains `banned` as a blocker on top of cooldown + in-flight-match, so rename isn't an evasion path; (4) listing-marketplace scopes filter `where('users.banned_at', null)` so existing listings disappear from the public board. M21 still owns chat-send-block, take-listing-block, polished "you've been suspended" page, blacklist (user-to-user) UI, and multi-account anti-evasion.
- **No delete action.** Hard-deleting users breaks FK chains across listings, matches, messages, wallet transactions. `banned_at` is the correct mechanism. If a user requests data deletion under privacy law, that is a user-owned legal call, not an engineering action.
- **Mandatory 2FA on admin role.** New middleware on `/admin/*` gates access on `two_factor_confirmed_at IS NOT NULL` for users with the `admin` Spatie role. Unenrolled admin → redirected to Fortify's existing two-factor-authentication enrollment page with a flash notice. Hardens the admin panel against credential phishing now that admin can impersonate any user. Uses Fortify's existing TOTP infrastructure — no new auth surface, just a route guard.
- **Decision: admin 2FA challenge on every login bridges to Fortify (Phase 6), not a plugin swap.** P3's middleware enforces 2FA *enrollment* before reaching the panel, but Filament's built-in `->login()` form bypasses Fortify's pipeline, so the on-every-login TOTP prompt doesn't fire. The `stephenjude/filament-two-factor-authentication` plugin was evaluated 2026-06-03 and rejected: (a) its `TwoFactorAuthenticatable` trait collides method-name-wise with Fortify's, so installing it requires removing Fortify's 2FA *app-wide* — not localized to admin; (b) Stakly's `/settings/security` is Inertia/React but the plugin's 2FA setup is Livewire, so adopting it routes every user (not just admins) through Filament/Livewire for 2FA setup; (c) `spatie/laravel-passkeys` is a hard composer dep for an unused feature. DIY bridge instead — custom Filament `Login` subclass detects admins with 2FA, bounces to a Stakly-styled `/admin/two-factor-challenge` Inertia page that validates codes via Fortify's existing `TwoFactorAuthenticationProvider`, plus a defense-in-depth middleware that catches the same condition on direct panel hits. Reuses Fortify's TOTP setup at `/settings/security` unchanged; ~200 LOC + tests.
- **User-facing ban feedback fires across three channels (P4).** When admin bans a user, the user MUST learn about it through (a) a persistent banner on every Stakly page they touch, (b) an in-app notification through M27's `PlayerNotification` pipeline (bell + `/notifications` page + real-time Reverb push), and (c) email. Production-grade: any single channel can fail (email in spam, user not on site for the bell push, banner missed because user is reading via email) — together the three guarantee the message lands. Same three channels on unban for symmetry.
- **Ban-feedback banner is non-dismissible.** Banned users shouldn't be able to hide the explanation of why they can't act on the platform. Sticky at top of every page, destructive tone, includes the reason from `user_moderation_logs` + a link to the CMS Support page.
- **Reason source of truth for ban feedback: `user_moderation_logs.reason`.** The banner reads the latest row where `action = 'ban'` via a new `auth.user.banned_reason` field exposed through `HandleInertiaRequests::share()`. The notification snapshots the reason in its own payload so old notifications keep showing the original reason even if a later ban/unban cycle changes "latest."
- **Custom impersonation, not a package.** Stakly is custodial money — minimizing third-party auth packages is the call. The full flow (start route + session marker + banner + exit route + audit) is ~200 LOC under our control. Avoids tracking a package's compatibility matrix against Filament 5.x upgrades.
- **Impersonation requires password confirm before start.** Mirrors GitHub. Route guarded by Fortify's `password.confirm` middleware — admin re-enters their password before the impersonation session starts, even if they're already in `/admin`. Re-confirms every hour (Fortify default).
- **Impersonation auto-expires after 30 minutes.** `started_at` on the audit row; middleware compares against `now` and force-exits past 30 min. Prevents "admin walked away from the desk" scenarios.
- **Impersonation banner rendered in `app.blade.php`.** Persists across every page the impersonating admin lands on — Stakly app pages, auth pages, error pages, even `/admin` if they navigate there. Single source of truth, no React provider plumbing. Shows "Viewing as @username · Exit" with the exit button always one click away.
- **Impersonation reason required at start.** Free-text field on the start modal ("Investigating Alice's wallet-history bug"). The audit row's reason is the answer to "why did admin X impersonate user Y three weeks ago?" — timestamp alone is too thin.
- **Impersonation exit returns to the user's admin page**, not the admin's previous location. Rationale: the admin came to this user's view page to impersonate; they'll likely want to act on what they saw (ban, take notes, escalate). They can navigate back to `/admin` manually if needed.
- **Impersonation blocked for `is_platform` users, banned users, and self.** Three guards on the start route.

### Phases

**Phase 1 — Schema + `UserResource` scaffold + index page** ✅ shipped 2026-06-03

Migrations for `users.banned_at` + the append-only `admin_impersonations` audit table; `Filament/Resources/Users/` folder; index page with 3-column search + 4 filters, `is_platform` excluded, `canCreate() = false`. `AdminImpersonation` model landed here too (was originally scoped to P5 — cleaner alongside the migration). Pest tests cover admin-only access + the filters / search / sort + no-create gate.

**Phase 2 — View page + non-impersonate actions + ban enforcement** ✅ shipped 2026-06-03

Schema: append-only `user_moderation_logs` (`action: ban | unban`, `UPDATED_AT = null`). `UserInfolist` with 7 stacked sections including an always-rendered wallet invariant badge that compares `users.usdt_balance` to `SUM(wallet_transactions.amount)`. Header actions: View as visitor / Verify email / Reset 2FA / Ban toggle (reason required both directions; DB transaction wraps `banned_at` flip + audit row write).

`App\Support\BanGuard` helper centralises `isBanned()` / `rejectionMessage()` / `supportUrl()` across the four enforcement surfaces (listing create+store, profile update, username rename blocker, marketplace scope). 14 Pest tests across `UserResourceActionsTest` + `BanEnforcementTest`.

Two calls flagged in the ship report: skipped the parallel `banned-user store-listing` test (the create-form test already proves the controller-level guard); switched flash style from `->with('toast', ...)` to `Inertia::flash('toast', ...)` because the former tripped `assertRedirect`'s session-error inspection.

**Phase 3 — Mandatory 2FA for admin role** ✅ shipped 2026-06-03

`App\Http\Middleware\RequireAdminTwoFactor` registered in `AdminPanelProvider::authMiddleware` — redirects admins without `two_factor_confirmed_at` to `/settings/security` with a toast flash. Non-admins fall through to Filament's `canAccessPanel` 403. `UserFactory::admin()` + `AdminUserSeeder` stamp the column so existing tests + local dev + CI don't trip the gate. Production checklist: operator re-enrolls real 2FA after first login.

Gotcha documented in tests: `/settings/security` is itself behind Fortify's `password.confirm` (`confirmPassword: true` in `config/fortify.php`), so the real flow is `/admin → /settings/security → /user/confirm-password → /settings/security → enrolls 2FA`. 5 Pest tests assert the full chain.

**Phase 4 — User-facing ban feedback (banner + bell + email)** ✅ shipped 2026-06-03

P2 shipped enforcement (banned users can't act) but only a generic flash on guarded actions. P4 closes the explanation loop across three channels so the user can't miss it.

- `BannedBanner` in `SiteLayout` above `SiteHeader`. Reads `auth.user.ban` ({reason, banned_at}) lazy-loaded via `User::latestBanLog` (HasOne with `latestOfMany`) only when `banned_at !== null` — unbanned users skip the join entirely.
- `AccountBanned` / `AccountRestored` extend `PlayerNotification` but override `via()` to fan out `['database', 'broadcast', 'mail']` unconditionally. Bypasses parent's preference flow because moderation can't be silenced. Mail uses `MailMessage` greeting/line/action with Laravel's default `notifications::email` Blade layout — custom branded templates deferred to a polish pass.
- Real-time banner refresh: `NotificationProvider` calls `router.reload({ only: ['auth'] })` on the `account_banned` / `account_restored` broadcast.
- Dispatch sits outside the DB transaction (`ViewUser::banToggleAction`) so a queue/notification failure doesn't roll back the moderation write.
- 9 Pest tests in `BanNotificationTest` (44 assertions) cover dispatch, channels, mail content, Inertia share, ban→unban→ban chain.

Support CTA is `mailto:support@stakly.com` (dedicated `/support` page deferred to M21).

**Phase 5 — Impersonate action + audit + banner**

- [ ] Routes: `POST /admin/impersonate/{target}` (start), `POST /impersonate/exit` (stop). Both auth-gated; start additionally guarded by `password.confirm` middleware.
- [ ] `ImpersonationController::start(User $target)` — guards against `is_platform`, `banned`, and self. Writes `admin_impersonations` row with `started_at`, `reason`, `ip_address`, `user_agent`. Sets session marker `impersonated_by` to the admin's id. Calls `Auth::login($target)`. Redirects to `route('home')`.
- [ ] `ImpersonationController::stop()` — reads `impersonated_by`, force-logs back in as that admin, stamps `ended_at` on the active row, returns to the user's admin page (`route('filament.admin.resources.users.view', $target)`).
- [ ] Header action on `ViewUser` page: "Impersonate" → opens modal with required reason Textarea → submits to start route.
- [ ] Middleware `App\Http\Middleware\HandleImpersonation` — for any request with the `impersonated_by` session marker: (a) reject if `now() - started_at > 30 min` (force-exit and flash "Impersonation session expired"); (b) inject a Blade-renderable signal so the banner partial shows.
- [ ] Blade partial `resources/views/partials/impersonation-banner.blade.php` included unconditionally in `app.blade.php`. Reads the session marker + target's username; renders the persistent banner with the exit `POST` form.
- [ ] Pest tests: full round trip (start → see banner → exit → audit row has `ended_at`), reason required, password-confirm gate, 30-min auto-expiry, blocked for `is_platform` / `banned` / self, exit lands on the user's admin view page.

**Phase 6 — Admin 2FA challenge on every login (bridge to Fortify)** ✅ shipped 2026-06-03

Build-time research found Filament 5 ships **native multi-factor authentication** in `Filament\Auth\Pages\Login::authenticate()` — it iterates registered `MultiFactorAuthenticationProvider`s, swaps the login form to a challenge form, re-runs validation on submit, and rate-limits at 5 attempts per user. The original spec (custom login subclass + dedicated route + Inertia page + controller + middleware + rate limiter) collapses to a single provider class.

- `App\Filament\MultiFactor\FortifyAppAuthentication` (5 contract methods, ~120 LOC) bridges Filament's MFA hook to Fortify's existing 2FA columns. `isEnabled` reads `two_factor_confirmed_at`. `getChallengeFormComponents` returns `OneTimeCodeInput` + recovery `TextInput` with toggle. Validation rules call into Fortify's `TwoFactorAuthenticationProvider::verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)` and `replaceRecoveryCode(...)`. `getManagementSchemaComponents` returns `[]` so the Filament panel doesn't leak its own 2FA setup UI — admins enroll at `/settings/security` (Inertia/React), same as every other user.
- Registered in `AdminPanelProvider` via `->multiFactorAuthentication([FortifyAppAuthentication::make()])`. No custom login subclass, routes, controller, Inertia page, rate limiter, or middleware.
- `RequireAdminTwoFactor` middleware (P3) stays — it enforces 2FA *enrollment* before reaching the panel; P6 enforces 2FA *challenge* on every login. Complementary, both run.
- 8 Pest tests in `AdminTwoFactorChallengeTest` cover the full Livewire MFA flow including recovery code consumption + the admin-without-2FA fallthrough. TOTP codes generated per-test via `(new Google2FA)->getCurrentOtp(...)` — Fortify encrypts the secret via its own encrypter, not a model cast.

No "I lost my TOTP device" cancel button: admins close the tab (no session leak, they're not logged in yet) and contact support, who uses the existing `reset_2fa` action in `ViewUser` (P2) to wipe the columns. Recovery codes remain the in-band escape.

### Not in M30

- Bulk actions (bulk ban, bulk verify). Solo-dev support cadence doesn't need bulk operations.
- Chat-send-block + take-listing-block enforcement of `banned_at`. Those land in M21 (blacklist + safety) — same column, additional guards inside `SendMessageAction` + `TakeListingAction`.
- Polished "your account has been suspended" full-page landing (separate from the banner). M30 P4 ships the persistent banner + in-app notification + email — those cover the "user knows they're banned and why" surface area. A dedicated suspension-landing page (the experience when banned users click the banner's CTA or hit a guarded route) is still M21's scope alongside the broader appeals UX.
- Multi-account / IP-evasion detection for banned users. Lives with M21's anti-evasion scope.
- User-to-user blacklist UI (Alice blocks Bob). Different mental model — admin ban vs user-driven block. M21 owns the user-driven version.
- Admin action audit log beyond `admin_impersonations` + `user_moderation_logs`. M30 P2 + P4 cover the two highest-leverage audit surfaces (ban actions, impersonation sessions). Broader action coverage — a generic `admin_actions` table logging every Filament action across every resource — is its own milestone; revisit when a second-admin scenario, internal-audit requirement, or specific compliance need drives the shape.
- Notification preference / linked account mutating on behalf of the user. View-only on the user page is enough; changes to those values should still go through the user-facing settings flow.
- Admin session timeout / IP allowlist / email-on-admin-login. Adjacent admin-hardening ideas; mandatory 2FA covers the highest-leverage threat (credential phishing). Layer more on if a concrete incident drives it.
- Support / read-only admin role with restricted resource access. The `admin` Spatie role is the only privileged role today. When a real support hire happens, add a `support` role + per-resource Filament policies (read-only on UserResource / WalletTransactionResource, no impersonation, no ban, no 2FA reset). Doesn't block M30; revisit when there's a second person in the admin panel.
- Built-in support ticket system / contact form. The CMS Support page covers the contact channel today (admin writes whatever — email / Discord / form). A dedicated ticket queue is its own milestone if volume justifies.

---

## M31 — Admin wallet ledger

Read-only audit visibility into every money movement on the platform. The single most important support tool for a custodial platform — without it, "where did my $12.50 go?" requires reconstructing the ledger by hand in Tinker. M31 makes the answer one filter-click away.

The architectural decisions about money writes (M3.5 — Wallet service is the only path, BCMath strings, append-only ledger) all stay intact. This milestone is purely a read surface on top of the ledger that already exists.

### Design decisions taken into this milestone

- **Read-only resource.** Zero write actions, zero mass-mutation. Every money write must continue to go through `App\Services\Wallet` to preserve the `users.usdt_balance == SUM(wallet_transactions.amount)` invariant asserted in `WalletTest.php`. Filament resources default to allowing edit / create — both explicitly disabled here.
- **Index columns** — user (link to UserResource view), type badge (Deposit / Hold / Release / Payout / Fee / Withdrawal with semantic colors mirroring the wallet UI), amount (right-aligned, BCMath-string display, NOT cast to float for display precision), `reference_id` (truncated with copy-to-clipboard), `created_at` (humanized + raw on hover).
- **Filters** — user typeahead (by username), type multi-select, date range, amount range, `reference_id` contains.
- **View page** — full row data plus contextual links. If `reference_id` matches a known pattern (`match-{id}` / `listing-{id}` / `cancel-{id}`), surface a link to the related match or listing. "Sibling transactions" section lists other rows sharing the same `reference_id` — useful for the deposit-confirm pattern where one event generates several rows.
- **Footer sum.** Below the table, total of currently-visible rows broken down by type. Lets the admin filter "type = Fee, this month" and see the platform's monthly revenue in one click without exporting. `OpsOverview` widget already gives a top-line number; this is the drill-down.
- **No "create transaction" action.** If a manual correction is ever genuinely needed, it routes through a future `Wallet::adjust(...)` method that does not exist today. By design — every money write today has a domain reason routed through a specific service method.

### Not in M31

- CSV export. Add when the user has a concrete external workflow that needs it (tax filing, accounting integration, auditor request) — the column / format decisions follow the destination.
- Charts / time-series of money flow. Visual summary belongs on the dashboard widget, not the resource list. `OpsOverview` already exposes monthly platform earnings.
- Per-currency filtering. USDT-only today; if M15-era multi-currency happens, this extends.
- Refund / adjust mutation actions. Genuinely don't belong here — refunds happen via `Wallet::release` triggered by match-state events; manual adjustments don't have a domain reason today.
- Cross-user transfer / "send money from A to B" action. Same reason — no domain trigger, just a footgun if it existed.

---

## M32 — Admin listing management

Operational visibility + force-cancel for the marketplace. The lowest-urgency of the three admin gaps, but enables takedown of abusive listings (sub-penny stakes, off-platform deal solicitation in the title, harassment-style descriptions) without dropping to Tinker. Admin views every listing the same way users see them, plus a single moderation action.

### Design decisions taken into this milestone

- **Index columns** — id, creator (link to UserResource view), state badge (Open / Taken / Cancelled / Expired), platform (chess.com / Lichess), stake_amount (right-aligned), skill range, time controls, region, languages, created_at, expires_at.
- **Filters** — state multi-select, platform, stake range, creator typeahead, region, has-language.
- **One action: Force cancel.** Routes through the existing `CancelListingAction` so escrow releases via `Wallet::release` and the ledger stays clean — the admin never writes to `usdt_balance` directly. Confirm dialog names the listing id + stake + creator so a wrong click is hard. Listing must be in `Open` state; Taken / Cancelled / Expired states have no force-cancel action (the corresponding match flow handles those cases through `GameMatchResource`).
- **View page.** Full listing data, related match (if Taken — link to `GameMatchResource`), related wallet transactions (escrow hold + any release on cancel).
- **No edit action.** Stake / skill range / platform are immutable on a real listing — changing them mid-flight invalidates expectations for any taker. If a listing needs changes, the right path is force-cancel + the creator re-creates.
- **No bulk cancel.** One listing at a time; bulk-cancel is a footgun and there's no operational scenario that needs it.

### Not in M32

- Manual "create listing on behalf of a user" action. No legitimate support reason; a vector for admin abuse if it existed.
- Force-expire (separate from force-cancel). The expiry clock is automatic; manual expiry without refund is a money operation that should go through the existing cancellation path. If we ever need "skip the timer," it's the cancellation action with the same refund behavior.
- Listing dispute moderation (separate from match dispute moderation). Match disputes are covered by `GameMatchResource` (M12). Pre-match listing disputes don't exist as a concept.
- Editing listing description / title (no fields exist today on listings — listing is just stake + skill + time control + region + languages). If a future listing schema adds free-text fields, moderation routes through M13 chat-anti-abuse patterns, not via direct admin edits.

