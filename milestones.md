# Stakly Milestones

Frontend-first build. UI against real DB infrastructure + seeded fake data; backend logic (escrow, payouts, on-chain integration) lands per page once the UI is validated. Milestones are work-chunk labels, not version commitments — decisions inside any of them are revisitable.

> **Shipped milestones live in `milestones_archived.md`** (M1, M2, M2.5, M3, M3.5, M4, M5, M6, M7, M8 all phases, M10, M11, M12 all phases, M14 Slice A, M16 all phases, M17, M18, M19, M22, M23, M24, M25). This file is for active + upcoming work + the cross-cutting architectural decisions that earlier milestones established.

## Phases (map)

**Active / upcoming:**

- **M13** — Chat anti-abuse + moderation [parked — design needs review]
- **M14** — Outcome pipeline hardening (reframed from "automated outcome adapters" — observability + reliability + coverage of the auto-fetch pipeline; Slice A shipped, Phase 1 next)
- **M20** — Notifications (email infrastructure + per-event preferences UI; M20 owns the surface end-to-end)
- **M21** — Blacklist + safety (block users from listings + chat, with anti-evasion considerations)
- **M15** — Multi-game expansion (FACEIT, OpenDota, Riot adapters)
- **M26** — Filament-managed CMS pages (Privacy, Terms, About — multilingual schema, SEO-indexable via global Inertia SSR; Phase 1 shipped, Phase 2 next: discoverability + Privacy/Terms scaffolding)
- **M27** — In-app notifications + action-required UX + sound (bell in `SiteHeader`, real-time via Reverb, per-event sound priority, sticky action banners; designed to enable M20 email without rework)

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

**Phase 2 — Discoverability + Privacy/Terms scaffolding**

- [ ] Seed Privacy + Terms rows as **drafts** (`published_at = null`) so they appear in Filament admin but 404 publicly until admin fills in the copy + publishes.
- [ ] Wire `About`, `Privacy`, `Terms` links in `SiteFooter` to their `/en/{slug}` URLs (replace the current placeholder `href="#"`).
- [ ] Fix `How it Works` in `SiteHeader` + `SiteFooter` to anchor to `/#how-it-works` (currently both are placeholder `href="#"`).
- [ ] Mirror new footer links inside `MobileMenu` if applicable.
- [ ] Tests: `SiteFooter` renders the three CMS links pointing at the correct URLs.
- [ ] Manual content writing pass on Privacy + Terms when ready (not a blocker for Phase 2 close).

**Phase 3 — Global Inertia SSR enablement (Path A)**

The big architectural piece. Benefits every Inertia page, not just CMS.

- [ ] Configure `@inertiajs/vite` SSR mode. Dev SSR is automatic per the plugin.
- [ ] Production SSR build step in `package.json` (`build:ssr`).
- [ ] `compose.yaml` adds a Node SSR sidecar service (`stakly.ssr`) — same image base as the existing Node setup, runs the SSR server on a fixed port.
- [ ] Laravel `config/inertia.php` — point SSR mode at the sidecar URL.
- [ ] Audit pass for SSR-unsafe code: any `window.` / `document.` / `localStorage` access in initial render needs a `typeof window === 'undefined'` guard. Likely candidates: `AuthModalProvider` (already guarded — confirmed), any other `useEffect`-less browser-API usage in component bodies.
- [ ] Verification: a curl-with-no-JS of the homepage / about page returns fully-rendered HTML. Optional: Lighthouse SEO score before/after.

**Phase 4 — Locale switcher in `SiteHeader`** [deferred until a second language ships]

- [ ] Dropdown in header — sets `app()->setLocale($locale)` (sticky cookie) + reroutes to `/{newLocale}/{currentSlug}` if on a localised page.
- [ ] When only `en` exists, this phase doesn't ship — adding the switcher without other languages is dead UI.

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
- **Per-event sound priority on the notification class itself.** Each `App\Notifications\*` declares a `soundPriority(): 'urgent' | 'soft' | 'none'`. The frontend uses this to pick which audio file to play (or skip silently). "Listing taken" is `urgent` (your money is now in a live match); "Settled" is `soft`; informational events are `none`. Avoids the "every notification dings" anti-pattern.
- **Multi-tab sound coordination via `BroadcastChannel`.** If a user has multiple Stakly tabs open, only the first tab to receive the broadcast plays the sound — the others suppress. Prevents triple-ding when one event lands.
- **Notification classes designed to support `mail` channel from day one** even though M27 only lights up `database`. M20 wires the Blade templates later without touching dispatch sites or class signatures.
- **Sound toggle lives on the preferences page (M27 Phase 5)**, alongside per-event in-app and email toggles. Defaults: urgent ON, soft OFF (most users find soft confirmation sounds annoying after the first day — opt-in).
- **Mandatory events cannot be silenced.** "Settled — you won/lost" and "Cancellation request awaiting response" are operational, not informational — turning them off would let users miss money-affecting events. UI greys those toggles.

### Phases

**Phase 1 — Notification dispatch infrastructure**

- [ ] One `App\Notifications\*` class per event:
    - `ListingTakenNotification` (creator-side — sound priority `urgent`)
    - `MatchSettledNotification` (both sides — `soft`)
    - `MatchManualReviewNotification` (both sides — `urgent`)
    - `DisputeOpenedNotification` (the opponent of the opener — `urgent`)
    - `CancellationRequestedNotification` (the opponent of the requester — `urgent`)
    - `CancellationAcceptedNotification` (the original requester — `soft`)
    - `CancellationRejectedNotification` (the original requester — `soft`)
- [ ] Each class implements `via()` returning `['database', 'mail']` (mail no-ops until M20 ships the Blade templates), `toDatabase()` returning shape `{title, body, action_url, event_type, sound_priority, related_id}`, and `soundPriority()`.
- [ ] Dispatch sites: each Action that triggers the corresponding event calls `$user->notify(new XxxNotification(...))` after the DB transaction commits (never inside — broadcast on rollback would lie).
- [ ] Tests with `Notification::fake()` confirm each Action dispatches the right class to the right user.

**Phase 2 — Bell UI in `SiteHeader` + real-time + sound**

- [ ] Bell icon + unread count badge in `SiteHeader` (auth-gated — anonymous visitors see no bell).
- [ ] Dropdown with last ~15 notifications, each linking to its `action_url`. "Mark all read" affordance. "View all" → full notifications page.
- [ ] Full notifications page at `/notifications` — paginated list, all notifications, mark-individual + mark-all controls.
- [ ] Laravel Echo subscribed to `private-users.{id}` channel; on broadcast, increment badge + prepend dropdown entry + invoke sound playback hook.
- [ ] Sound assets in `public/sounds/` — `urgent.mp3` and `soft.mp3` (two sounds, three priorities — `none` plays nothing). Free, royalty-clear, short (<1s) chimes. Pick something tasteful — flag samples for review before committing.
- [ ] `useNotificationSound` hook reads the sound priority off the broadcast, plays the matching file via `new Audio(...).play()` if user pref allows. Wraps the `BroadcastChannel` coordination so multi-tab plays once.
- [ ] Browser autoplay policy is handled implicitly — by the time a notification lands, the user has interacted with Stakly at least once (they're logged in). No special permission UI needed.

**Phase 3 — Action-required banners on match pages**

- [ ] Sticky banners on `match/show.tsx` for states requiring the player's response:
    - Cancellation requested by opponent → "Accept / Reject" banner with both buttons. Persists until actioned.
    - Match in `ManualReview` → "Post evidence in chat" banner with chat-focus CTA.
    - Match `Disputed` opened by opponent → "Your opponent reported a problem — admin reviewing" info banner.
- [ ] These are UI surfaces tied to match status, not new notification types — visible whenever the player views the match page, even if they dismissed the bell entry already.
- [ ] Tests assert each banner renders for the right status × viewer combination.

**Phase 4 — Admin SLA surfaces**

- [ ] Extend `OpsOverview` with a "Disputes > 6h old" stat — separate from total open disputes, color escalates `warning` at 6h, `danger` at 12h.
- [ ] `GameMatchResource` table — sort default puts oldest unactioned at the top. Per-row age badge (green / amber / red) matching the SLA color scale.
- [ ] (Optional, deferred) Slack / Discord webhook to admin channel when a dispute crosses the 12h `danger` threshold without action. Out of scope for Phase 4 itself; opens a follow-up if the email-to-admin pattern isn't enough.

**Phase 5 — Preferences UI (shared surface with M20)**

- [ ] New `/settings/notifications` page — per-event grid: rows are event types, columns are channels (in-app, sound, email).
- [ ] Mandatory events have their toggles greyed-out with a tooltip explaining why.
- [ ] Email column is visible but greyed-out with "Available when email notifications launch" until M20 ships, then becomes interactive.
- [ ] Schema: `notification_preferences` table — `user_id`, `event_type`, `in_app` (bool), `sound` (bool), `email` (bool). UNIQUE `(user_id, event_type)`. Defaults inserted on user creation matching the per-event default policy.

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

