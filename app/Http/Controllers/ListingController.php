<?php

namespace App\Http\Controllers;

use App\Enums\Game;
use App\Http\Requests\Listings\IndexListingsRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
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
                AllowedFilter::exact('time_control'),
                AllowedFilter::exact('region'),
                AllowedFilter::exact('language'),
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
}
