<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Http\Resources\ListingResource;
use App\Models\Game;
use App\Models\Listing;
use App\Services\SellerTrust;
use Illuminate\Support\Facades\Cache;
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
            ->onPublicMarketplace()
            ->with(['user:id,name,username,is_active_mode', 'user.linkedAccounts'])
            ->orderBy('expires_at')
            ->orderByDesc('id')
            ->limit(self::FEATURED_COUNT)
            ->get();

        // M22 Phase 1 — seller trust on the featured strip too.
        SellerTrust::attachTo($featured);

        // M24 Phase 1 — game tile catalog. Cached because games change
        // rarely (admin-edited via Filament); `Game::booted` forgets this
        // key on save/delete so admin edits show immediately.
        //
        // We cache the RESOLVED resource array (not the Eloquent collection)
        // — caching `Eloquent\Collection` round-trips through the cache
        // driver's serialize/unserialize, which trips on `__PHP_Incomplete_Class`
        // when the cached blob is read back (especially on the Postgres
        // cache driver). Storing a flat array of dicts dodges that entirely.
        $games = Cache::remember(
            Game::HOMEPAGE_CACHE_KEY,
            now()->addHour(),
            fn () => GameResource::collection(Game::forHomepage()->get())->resolve()
        );

        return Inertia::render('welcome', [
            'featured' => ListingResource::collection($featured),
            'games' => ['data' => $games],
        ]);
    }
}
