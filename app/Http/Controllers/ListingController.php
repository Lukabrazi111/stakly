<?php

namespace App\Http\Controllers;

use App\Actions\Listing\CancelListingAction;
use App\Actions\Listing\CreateListingAction;
use App\Enums\Game;
use App\Enums\ListingStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\Listings\IndexListingsRequest;
use App\Http\Requests\Listings\StoreListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            Listing::query()->onPublicMarketplace()->with('user:id,name,username,is_active_mode'),
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

        return Inertia::render('listings/index', [
            'listings' => ListingResource::collection($listings),
            'filters' => $request->filters(),
            'sorts' => IndexListingsRequest::SORTS,
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
    public function show(Request $request, Listing $listing): Response
    {
        // `is_active_mode` is needed for the frontend's owner-inactive gate
        // on the Take button (M6 Phase 6.5). The marketplace + public profile
        // surfaces never see inactive owners' listings via
        // `scopeOnPublicMarketplace`, but this detail page bypasses that scope
        // (direct URL access stays viewable so owners can share + manage), so
        // the resource needs the flag.
        $listing->load(['user:id,name,username,is_active_mode', 'gameMatch:id,listing_id,taker_user_id']);

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
     * Create-listing form. Auth + email-verified gates are at the route
     * layer; we also pass the user's current USDT balance + active-listings
     * count so the form can disable submit when stake > balance OR the cap
     * is hit. `StoreListingRequest` re-validates both server-side.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        $activeCount = $user->listings()
            ->where('status', ListingStatus::Open)
            ->count();

        return Inertia::render('listings/create', [
            'balance' => Wallet::balanceFor($user),
            'regions' => StoreListingRequest::REGIONS,
            'languages' => StoreListingRequest::LANGUAGES,
            'durations' => StoreListingRequest::DURATION_HOURS,
            'activeListingsCount' => $activeCount,
            'maxActiveListings' => StoreListingRequest::MAX_ACTIVE_LISTINGS,
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
            ->with('user:id,name,username,is_active_mode')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($tab === 'listed') {
            // "Listed" = on-the-board. Open only. Paused listings live in
            // "All Ads" until the user resumes them.
            $query->where('status', ListingStatus::Open);
        }

        $listings = $query->paginate(self::PER_PAGE)->withQueryString();

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
    public function store(StoreListingRequest $request, CreateListingAction $action): RedirectResponse
    {
        try {
            $action->handle($request->user(), $request->validated());
        } catch (InsufficientBalanceException) {
            throw ValidationException::withMessages([
                'stake_amount' => __('Stake exceeds your available balance.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing created.')]);

        // Redirect to the owner's management dashboard rather than the detail
        // page — gives users a single home where they can see all their
        // listings, toggle Active Mode, and post another.
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
     * Time control overlap: `time_control` is now a jsonb array on each
     * listing. The filter value can be a single string or an array (Spatie
     * splits comma-separated values). A listing matches if its offered set
     * intersects the user's selection.
     */
    private function timeControlOverlap(): \Closure
    {
        return function (Builder $q, $value) {
            $values = is_array($value) ? $value : [$value];

            $q->where(function (Builder $inner) use ($values) {
                foreach ($values as $v) {
                    $inner->orWhereJsonContains('time_control', $v);
                }
            });
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
