<?php

namespace App\Http\Controllers;

use App\Actions\LinkedAccount\RefreshDisplayedRatingsAction;
use App\Actions\Listing\CancelListingAction;
use App\Actions\Listing\CreateListingAction;
use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\Listings\IndexListingsRequest;
use App\Http\Requests\Listings\StoreListingRequest;
use App\Http\Resources\GameResource;
use App\Http\Resources\ListingResource;
use App\Http\Resources\LobbyResource;
use App\Http\Resources\MessageResource;
use App\Models\Game as GameModel;
use App\Models\Listing;
use App\Services\ParticipantStats;
use App\Services\SellerTrust;
use App\Services\Wallet;
use App\Support\BanGuard;
use App\Support\FaceitLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ListingController extends Controller
{
    private const PER_PAGE = 12;

    /**
     * Public marketplace board. Filterable, sortable, paginated. Server
     * controls page size — never trust a `per_page` query param.
     *
     * URL contract:
     *   /listings?filter[stake_max]=100&filter[time_control]=blitz,rapid&sort=ending_soon&page=2
     *
     * `IndexListingsRequest` validates + redirects on bad input, so by the
     * time we reach here every value is safe and within bounds.
     */
    public function index(IndexListingsRequest $request): Response
    {
        $newest = AllowedSort::callback(
            'newest',
            fn (Builder $q) => $q->orderByDesc('created_at')->orderByDesc('id'),
        );

        $listings = QueryBuilder::for(
            Listing::query()
                ->onPublicMarketplace()
                ->with([
                    'user:id,name,username,is_active_mode',
                    'user.linkedAccounts',
                    'lobbyParticipants' => fn ($q) => $q->live()->orderBy('joined_at'),
                    'lobbyParticipants.user:id,name,username',
                    'lobbyParticipants.user.media',
                ])
                ->withCount(['lobbyParticipants as live_participant_count' => fn ($q) => $q->live()]),
        )
            ->allowedFilters(
                AllowedFilter::exact('game')->default(Game::Chess->value),
                AllowedFilter::callback('stake_min', fn (Builder $q, $value) => $q->where('stake_amount', '>=', $value)),
                AllowedFilter::callback('stake_max', fn (Builder $q, $value) => $q->where('stake_amount', '<=', $value)),
                AllowedFilter::callback('skill_min', $this->skillMinOverlap()),
                AllowedFilter::callback('skill_max', $this->skillMaxOverlap()),
                AllowedFilter::callback('time_control', $this->timeControlOverlap()),
                AllowedFilter::exact('region'),
                AllowedFilter::callback('language', $this->languageMatch()),
            )
            ->allowedSorts(
                $newest,
                AllowedSort::callback('highest_stake', fn (Builder $q) => $q->orderByDesc('stake_amount')->orderByDesc('id')),
                AllowedSort::callback('lowest_stake', fn (Builder $q) => $q->orderBy('stake_amount')->orderByDesc('id')),
                AllowedSort::callback('ending_soon', fn (Builder $q) => $q->orderBy('expires_at')->orderByDesc('id')),
            )
            ->defaultSort($newest)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // M22 Phase 1 — batch-load seller trust aggregates for every creator
        // on the page in ONE query, no N+1. Attaches a transient
        // `seller_trust` attribute that `ListingResource` reads.
        SellerTrust::attachTo($listings);

        // M41 P2/P3b — keep displayed ratings fresh (CS2 FACEIT + chess per-TC).
        // Stale-gated + deduped + throttled, so this is safe on the read path.
        app(RefreshDisplayedRatingsAction::class)->forListings($listings->getCollection());

        // Reuse the homepage's resolved-array cache — `Game::booted` already
        // invalidates it on save/delete, so admin tile edits land here too.
        $games = Cache::remember(
            GameModel::HOMEPAGE_CACHE_KEY,
            now()->addHour(),
            fn () => GameResource::collection(GameModel::forHomepage()->get())->resolve()
        );

        return Inertia::render('listings/index', [
            'listings' => ListingResource::collection($listings),
            'filters' => $request->filters(),
            'sorts' => IndexListingsRequest::SORTS,
            'games' => ['data' => $games],
        ]);
    }

    /**
     * Public listing detail page. Renders regardless of status — taken /
     * expired / cancelled listings still display, just with a status badge
     * and a disabled Take CTA. Avoids 404-ing on shared links to non-open
     * listings.
     *
     * For Taken listings, expose the related match id to the two participants
     * (creator + taker) so the frontend can render a "View match →" link. Non-
     * participants don't get the id — it's not strongly PII, but there's no
     * reason for randoms to be able to enumerate match ids from listing pages.
     */
    public function show(Request $request, Listing $listing): Response|RedirectResponse
    {
        // M34 P3.1 Slice B.1 — team-play listings host their lobby UI directly
        // on this page rather than a sibling `/lobbies/{id}` URL. The branch
        // mirrors `LobbyController::show` eager-loads + payload so the lobby
        // view consumes the same shape it always has (LobbyResource).
        if ($listing->isTeamPlay()) {
            return $this->showTeamPlay($request, $listing);
        }

        // `is_active_mode` is needed for the frontend's owner-inactive gate
        // on the Take button (M6 Phase 6.5). The marketplace + public profile
        // surfaces never see inactive owners' listings via
        // `scopeOnPublicMarketplace`, but this detail page bypasses that scope
        // (direct URL access stays viewable so owners can share + manage), so
        // the resource needs the flag.
        // M23 Phase 1 — detail page renders bio + member_since on the
        // creator card, so we widen the user column whitelist beyond what
        // the marketplace row needs. `bio` is capped at 500 chars (see the
        // users migration) so payload cost is bounded.
        $listing->load([
            'user:id,name,username,is_active_mode,bio,created_at',
            'user.linkedAccounts',
            'gameMatch:id,listing_id,taker_user_id',
            'lobbyParticipants' => fn ($q) => $q->live()->orderBy('joined_at'),
            'lobbyParticipants.user:id,name,username',
            'lobbyParticipants.user.media',
        ]);
        $listing->loadCount(['lobbyParticipants as live_participant_count' => fn ($q) => $q->live()]);

        // M22 Phase 1 — seller trust on the listing detail (single-row batch).
        SellerTrust::attachTo([$listing]);

        // M41 P2/P3b — refresh-on-view (CS2 FACEIT + chess per-TC), stale-gated.
        app(RefreshDisplayedRatingsAction::class)->forListings([$listing]);

        $user = $request->user();
        $match = $listing->gameMatch;
        $isParticipant = $match !== null
            && $user !== null
            && ($user->id === $listing->user_id || $user->id === $match->taker_user_id);

        return Inertia::render('listings/show', [
            'listing' => (new ListingResource($listing))->resolve(),
            'match' => $isParticipant && $match !== null
                ? ['id' => $match->id]
                : null,
        ]);
    }

    /**
     * Team-play branch of `show()` — renders the lobby UI on the canonical
     * listing URL. For visibility checks (private listings, etc.) we use
     * `ListingPolicy::viewLobby` so the auth boundary stays in one place.
     *
     * Mirrors `LobbyController::show`'s eager-loads + chat-message slice so
     * the React page consumes the same `LobbyResource` shape it has since
     * M34 P3.
     */
    private function showTeamPlay(Request $request, Listing $listing): Response|RedirectResponse
    {
        abort_if(Gate::denies('viewLobby', $listing), 404);

        $listing->load([
            'user:id,name,username,bio,created_at',
            'user.linkedAccounts',
            'lobbyParticipants.user:id,name,username',
            'lobbyParticipants.user.linkedAccounts',
            // `created_at` powers the M34 P7 locked-state countdown in
            // `LobbyResource::match_deadline_at` (match.created_at + N hours).
            'gameMatch:id,listing_id,status,created_at',
        ]);

        // Match the marketplace + my-listings card payload so the listing
        // resource carries the same `live_participant_count` field. Cheap
        // separate query; keeps the resource's read-from-attribute path safe.
        $listing->loadCount(['lobbyParticipants as live_participant_count' => fn ($q) => $q->live()]);

        // M34 P3.1 Slice B.2 — center column needs every participant's
        // seller_trust (trust block) + per-player Overall/Last-20 stats
        // (slot card stats line). Both are batched aggregations across
        // the live-participant user IDs; LobbyResource reads the attached
        // `seller_trust` + `platform_stats` attributes per user.
        $userIds = $listing->lobbyParticipants
            ->whereNull('kicked_at')
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
        $trust = SellerTrust::forBatch($userIds);
        $stats = ParticipantStats::forBatch($userIds);
        foreach ($listing->lobbyParticipants as $participant) {
            if ($participant->kicked_at !== null) {
                continue;
            }
            $participant->user->setAttribute(
                'seller_trust',
                $trust[$participant->user_id] ?? ['rate_30d' => null, 'settled_lifetime' => 0],
            );
            $participant->user->setAttribute(
                'platform_stats',
                $stats[$participant->user_id] ?? null,
            );
        }

        // M41 P2 — refresh-on-view for the lobby: the creator + every live
        // participant whose rating shows in the roster.
        $refreshRatings = app(RefreshDisplayedRatingsAction::class);
        $refreshRatings->forListings([$listing]);
        $refreshRatings->forAccounts(
            collect($listing->lobbyParticipants)
                ->whereNull('kicked_at')
                ->flatMap(fn ($participant) => $participant->user->linkedAccounts)
        );

        // Chat content is participant-only — strangers, guests, and kicked
        // users get an empty collection. Without this gate the messages.data
        // payload would render on the page JSON for anyone with the URL,
        // mirroring the WebSocket subscription gate enforced on the React
        // side. `$userIds` is the live-participant set computed above.
        $viewer = $request->user();
        $isLiveParticipant = $viewer !== null && in_array($viewer->id, $userIds, true);

        $messages = $isLiveParticipant && $listing->gameMatch !== null
            ? $listing->gameMatch
                ->messages()
                ->with(['user:id,name,username', 'media'])
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->reverse()
                ->values()
            : collect();

        return Inertia::render('listings/show', [
            'listing' => (new ListingResource($listing))->resolve(),
            'lobby' => (new LobbyResource($listing))->resolve(),
            'messages' => MessageResource::collection($messages),
            // Match column kept for chess back-compat in the shared props
            // shape; team-play readers ignore it in favor of `lobby.match_id`.
            'match' => null,
        ]);
    }

    /**
     * Create-listing form. Auth + email-verified gates are at the route
     * layer; we also pass the user's current USDT balance + active-listings
     * count so the form can disable submit when stake > balance OR the cap
     * is hit. `StoreListingRequest` re-validates both server-side.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (BanGuard::isBanned($user)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => BanGuard::rejectionMessage()]);

            return to_route('listings.index');
        }

        $activeCount = $user->listings()
            ->where('status', ListingStatus::Open)
            ->count();

        // Surface the user's verified-provider set so the create form can
        // (a) hide the platform picker entirely when they only have one
        // verified provider (auto-selected), (b) show the picker when they
        // have multiple, (c) swap the form for the link-CTA notice when
        // they have zero (handled by the existing `has_chess_link` flag in
        // shared auth.user props).
        $user->loadMissing('linkedAccounts');
        $linkedPlatforms = $user->linkedAccounts
            ->pluck('provider')
            ->map(fn (LinkedAccountProvider $provider) => $provider->value)
            ->sort()
            ->values()
            ->all();

        // M41 P2 — the creator's own FACEIT rating drives the CS2 live preview
        // + the in-form "your verified rating" note. Null when no FACEIT link.
        $faceit = $user->linkedAccounts->firstWhere('provider', LinkedAccountProvider::Faceit);
        $userFaceitRating = $faceit === null ? null : [
            'elo' => $faceit->skill_rating,
            'level' => FaceitLevel::fromElo($faceit->skill_rating),
            'is_unrated' => $faceit->skill_rating === null,
        ];

        // Reuse the homepage's resolved-array cache — `Game::booted` already
        // invalidates it on save/delete, so admin tile edits land here too.
        $games = Cache::remember(
            GameModel::HOMEPAGE_CACHE_KEY,
            now()->addHour(),
            fn () => GameResource::collection(GameModel::forHomepage()->get())->resolve()
        );

        // Per-game requirement map: which providers gate which game, and
        // whether the user already qualifies. Drives the tile picker's
        // "Link FACEIT to post CS2" inline hint in Slice 2.
        $requirementsByGame = collect(Game::cases())
            ->mapWithKeys(fn (Game $game) => [
                $game->value => [
                    'providers' => array_map(
                        fn (LinkedAccountProvider $provider) => $provider->value,
                        $game->requiredProviders(),
                    ),
                    'verified' => collect($game->requiredProviders())
                        ->contains(fn (LinkedAccountProvider $provider) => $user->isVerifiedOn($provider)),
                    // Drives the Format picker on `listings/create` — single-
                    // element arrays render no picker (chess + Dota stay
                    // hidden); multi-element arrays render a segmented
                    // control. CS2 ships [5] today; flips to [2, 5] when
                    // Wingman lands.
                    'allowed_team_sizes' => $game->allowedTeamSizes(),
                ],
            ])
            ->all();

        return Inertia::render('listings/create', [
            'balance' => Wallet::balanceFor($user),
            'regions' => StoreListingRequest::REGIONS,
            'languages' => StoreListingRequest::LANGUAGES,
            'durations' => StoreListingRequest::DURATION_HOURS,
            'activeListingsCount' => $activeCount,
            'maxActiveListings' => StoreListingRequest::MAX_ACTIVE_LISTINGS,
            'linkedPlatforms' => $linkedPlatforms,
            'games' => ['data' => $games],
            'requirementsByGame' => $requirementsByGame,
            // Single source for the create-form Deal summary (M40) — the same
            // config the wallet ledger uses at settlement. Float at the JSON
            // boundary only; internal money math stays BCMath.
            'feeRate' => (float) config('stakly.platform_fee_rate'),
            'userFaceitRating' => $userFaceitRating,
        ]);
    }

    /**
     * Owner's listings management dashboard (M6 Phase 6.5). Tabs:
     *   - `listed` (default): only Open listings, what's currently on the
     *     public board (assuming Active Mode is on).
     *   - `all`: every status the user has ever held — full history.
     *
     * Both tabs scope to `user_id = auth user` so a user can never see
     * another user's listings here. Pagination 12/page; bad `tab` values
     * silently fall back to `listed` (consistent with other URL-driven
     * filters that prefer graceful degradation over 422 walls).
     */
    public function mine(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->is_platform, 403);

        $tab = in_array($request->input('tab'), ['listed', 'all'], true)
            ? $request->input('tab')
            : 'listed';

        $query = $user->listings()
            ->with([
                'user:id,name,username,is_active_mode',
                'user.linkedAccounts',
                'lobbyParticipants' => fn ($q) => $q->live()->orderBy('joined_at'),
                'lobbyParticipants.user:id,name,username',
                'lobbyParticipants.user.media',
            ])
            ->withCount(['lobbyParticipants as live_participant_count' => fn ($q) => $q->live()])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($tab === 'listed') {
            // "Listed" = on-the-board. Open only. Paused listings live in
            // "All Ads" until the user resumes them.
            $query->where('status', ListingStatus::Open);
        }

        $listings = $query->paginate(self::PER_PAGE)->withQueryString();

        // M22 Phase 1 — same N+1-safe batch on the owner's own listings
        // page so the trust chip renders consistently with the public
        // marketplace. The owner's seller_trust will be identical across
        // all rows on this page (single creator), so the batch trivially
        // collapses to one aggregate.
        SellerTrust::attachTo($listings);

        // M41 P2/P3b — refresh-on-view (CS2 + chess; gated/deduped/throttled).
        app(RefreshDisplayedRatingsAction::class)->forListings($listings->getCollection());

        // Counts both Open and Paused — the cap is about "listings holding
        // your capital that aren't yet concluded." Mirrors the rule in
        // `StoreListingRequest::withValidator`.
        $activeCount = $user->listings()
            ->where('status', ListingStatus::Open)
            ->count();

        return Inertia::render('listings/mine', [
            'listings' => ListingResource::collection($listings),
            'tab' => $tab,
            'activeCount' => $activeCount,
            'maxActive' => StoreListingRequest::MAX_ACTIVE_LISTINGS,
        ]);
    }

    /**
     * Creates a listing AND escrows the stake. Business logic lives in
     * `CreateListingAction` — this method is the HTTP adapter: validate,
     * delegate, map domain exceptions to HTTP, flash, redirect.
     *
     * `InsufficientBalanceException` is a race-only path (balance dropped
     * between the form-request pre-check and the wallet's row-locked
     * re-check). Converted to a `ValidationException` keyed on
     * `stake_amount` so the form re-renders cleanly with field-level feedback.
     */
    public function store(
        StoreListingRequest $request,
        CreateListingAction $createListing,
        CreateTeamPlayListingAction $createTeamPlayListing,
    ): RedirectResponse {
        if (BanGuard::isBanned($request->user())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => BanGuard::rejectionMessage()]);

            return to_route('listings.index');
        }

        $data = $request->validated();
        $isTeamPlay = (int) ($data['team_size'] ?? 1) > 1;

        try {
            $result = $isTeamPlay
                ? $createTeamPlayListing->handle($request->user(), $data)
                : $createListing->handle($request->user(), $data);
        } catch (InsufficientBalanceException) {
            throw ValidationException::withMessages([
                'stake_amount' => __('Stake exceeds your available balance.'),
            ]);
        }

        if ($result === 'not_linked') {
            $platform = LinkedAccountProvider::from($request->validated('platform'));

            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('Link a :platform account before posting a :platform listing.', [
                    'platform' => $platform->displayName(),
                ]),
            ]);

            return to_route('linked-accounts.edit');
        }

        if ($result === 'already_in_lobby') {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('You\'re already in an active lobby. Leave it before creating another team-play listing.'),
            ]);

            return to_route('listings.mine');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing created.')]);

        // Team-play creators land on the lobby itself so they can ready up,
        // chat, and (for private listings) grab the invite link from the
        // owner-only banner. 1v1 creators still bounce to /listings/mine —
        // their listing has no lobby surface.
        if ($result instanceof Listing && $result->isTeamPlay()) {
            return to_route('listings.show', $result);
        }

        return to_route('listings.mine');
    }

    /**
     * Cancels an Open listing and refunds the escrow. Business logic lives
     * in `CancelListingAction`. Authorization (`ListingPolicy::cancel`:
     * creator-only AND status === Open) runs here in the controller before
     * the Action is invoked.
     *
     * Redirects to `/listings/mine` rather than the now-cancelled detail
     * page — cancel is a management action, and the cancelled detail view
     * has no actions left anyway.
     */
    public function cancel(Listing $listing, CancelListingAction $action): RedirectResponse
    {
        Gate::authorize('cancel', $listing);

        $action->handle($listing);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Listing cancelled. $:amount USDT refunded.', [
                'amount' => number_format((float) $listing->stake_amount, 2, '.', ''),
            ]),
        ]);

        return to_route('listings.mine');
    }

    /**
     * Skill-range overlap (lower bound): a listing matches if its
     * [skill_min, skill_max] band intersects the user's filter lower bound,
     * OR if the listing has no upper bound ("any skill").
     */
    private function skillMinOverlap(): \Closure
    {
        return fn (Builder $q, $value) => $q->where(
            fn (Builder $inner) => $inner->whereNull('skill_max')->orWhere('skill_max', '>=', $value),
        );
    }

    /**
     * Skill-range overlap (upper bound): mirror of the above for the user's
     * filter upper bound. Listings with no lower bound also match.
     */
    private function skillMaxOverlap(): \Closure
    {
        return fn (Builder $q, $value) => $q->where(
            fn (Builder $inner) => $inner->whereNull('skill_min')->orWhere('skill_min', '<=', $value),
        );
    }

    /**
     * Time control filter: each listing now stores a SINGLE `time_control`
     * string (M41 P3a). The filter stays multi-select — the value can be a
     * single string or an array (Spatie splits comma-separated values) — and a
     * listing matches if its one time control is in the user's selection.
     */
    private function timeControlOverlap(): \Closure
    {
        return function (Builder $q, $value) {
            $values = array_filter(is_array($value) ? $value : [$value]);

            // A present-but-empty filter (e.g. `?filter[time_control]=`) leaves
            // no values — skip the constraint so the board stays unfiltered
            // rather than `whereIn([])` collapsing to zero rows.
            if ($values === []) {
                return;
            }

            $q->whereIn('time_control', $values);
        };
    }

    /**
     * Language match: `language` is a jsonb array on each listing, or null
     * (no restriction). A user filtering by language=Russian should see
     * listings whose array includes Russian AND listings with no restriction.
     */
    private function languageMatch(): \Closure
    {
        return fn (Builder $q, $value) => $q->where(
            fn (Builder $inner) => $inner->whereJsonContains('language', $value)->orWhereNull('language'),
        );
    }
}
