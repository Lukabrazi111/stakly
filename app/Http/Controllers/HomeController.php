<?php

namespace App\Http\Controllers;

use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    private const FEATURED_COUNT = 4;

    /**
     * Public landing page. Renders Hero + featured listings strip
     * (top N ending-soon open listings) + GameSelector + HowItWorks.
     *
     * Server-fetched so the strip always reflects real database state.
     */
    public function index(): Response
    {
        $featured = Listing::query()
            ->open()
            ->with('user:id,name,username')
            ->orderBy('expires_at')
            ->orderByDesc('id')
            ->limit(self::FEATURED_COUNT)
            ->get();

        return Inertia::render('welcome', [
            'featured' => ListingResource::collection($featured),
        ]);
    }
}
