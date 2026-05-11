<?php

namespace App\Http\Controllers;

use App\Http\Requests\Listings\IndexListingsRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class ListingController extends Controller
{
    private const PER_PAGE = 12;

    /**
     * Public marketplace board. Filterable, sortable, paginated. Server
     * controls page size — never trust a `per_page` query param.
     */
    public function index(IndexListingsRequest $request): Response
    {
        $filters = $request->filters();

        $listings = Listing::query()
            ->open()
            ->with('user:id,name')
            ->tap(fn (Builder $q) => $this->applyFilters($q, $filters))
            ->tap(fn (Builder $q) => $this->applySort($q, $filters['sort']))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('listings/index', [
            'listings' => ListingResource::collection($listings),
            'filters' => $filters,
            'sorts' => IndexListingsRequest::SORTS,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query->where('game', $filters['game']);
        $query->when($filters['stake_min'] !== null, fn (Builder $q) => $q->where('stake_amount', '>=', $filters['stake_min']));
        $query->when($filters['stake_max'] !== null, fn (Builder $q) => $q->where('stake_amount', '<=', $filters['stake_max']));

        // Skill-range overlap: a listing matches if its [skill_min, skill_max]
        // intersects the user's filter range, OR if the listing has no skill
        // range set ("any skill"). Either bound is optional.
        $query->when($filters['skill_min'] !== null, function (Builder $q) use ($filters) {
            $q->where(function (Builder $inner) use ($filters) {
                $inner->whereNull('skill_max')
                    ->orWhere('skill_max', '>=', $filters['skill_min']);
            });
        });

        $query->when($filters['skill_max'] !== null, function (Builder $q) use ($filters) {
            $q->where(function (Builder $inner) use ($filters) {
                $inner->whereNull('skill_min')
                    ->orWhere('skill_min', '<=', $filters['skill_max']);
            });
        });

        $query->when(! empty($filters['time_control']), fn (Builder $q) => $q->whereIn('time_control', $filters['time_control']));
        $query->when($filters['region'] !== null, fn (Builder $q) => $q->where('region', $filters['region']));
        $query->when($filters['language'] !== null, fn (Builder $q) => $q->where('language', $filters['language']));
    }

    private function applySort(Builder $query, string $sort): void
    {
        // Whitelist-driven match. Never interpolate user input into ORDER BY.
        match ($sort) {
            'highest_stake' => $query->orderByDesc('stake_amount')->orderByDesc('id'),
            'lowest_stake' => $query->orderBy('stake_amount')->orderByDesc('id'),
            'ending_soon' => $query->orderBy('expires_at')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
