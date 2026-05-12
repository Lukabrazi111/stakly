<?php

namespace App\Http\Controllers;

use App\Enums\Game;
use App\Enums\ListingStatus;
use App\Http\Requests\Listings\IndexListingsRequest;
use App\Http\Requests\Listings\StoreListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            Listing::query()->open()->with('user:id,name'),
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
     */
    public function show(Listing $listing): Response
    {
        $listing->load('user:id,name');

        return Inertia::render('listings/show', [
            'listing' => (new ListingResource($listing))->resolve(),
        ]);
    }

    /**
     * Create-listing form. Auth + email-verified gates are at the route
     * layer; we also pass the user's current USDT balance so the form can
     * disable submit when stake > balance (the request also validates this
     * authoritatively in Phase 7).
     */
    public function create(Request $request): Response
    {
        return Inertia::render('listings/create', [
            'balance' => Wallet::balanceFor($request->user()),
            'regions' => StoreListingRequest::REGIONS,
            'languages' => StoreListingRequest::LANGUAGES,
            'durations' => StoreListingRequest::DURATION_HOURS,
        ]);
    }

    /**
     * Phase 1 (M4) skeleton — creates the listing row but does NOT yet hold
     * the stake. Phase 7 wraps this in `DB::transaction` with `Wallet::hold`
     * keyed on a deterministic reference. Until then the listing exists but
     * no money has moved — fine for UI iteration, NOT fine for prod.
     */
    public function store(StoreListingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $listing = $request->user()->listings()->create([
            'game' => $data['game'],
            'stake_amount' => $data['stake_amount'],
            'skill_min' => $data['skill_min'] ?? null,
            'skill_max' => $data['skill_max'] ?? null,
            'time_control' => $data['time_control'],
            'region' => $data['region'] ?? null,
            'language' => $data['language'] ?? null,
            'expires_at' => now()->addHours((int) $data['duration_hours']),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing created.')]);

        return to_route('listings.show', $listing);
    }

    /**
     * Phase 1 (M4) skeleton — flips status to Cancelled without refunding.
     * Phase 7 adds `ListingPolicy::cancel` authorization + a `DB::transaction`
     * wrapping `Wallet::release` and the status update.
     */
    public function cancel(Listing $listing): RedirectResponse
    {
        $listing->update(['status' => ListingStatus::Cancelled]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listing cancelled.')]);

        return to_route('listings.show', $listing);
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
