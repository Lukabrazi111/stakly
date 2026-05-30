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

    public function index(): Response
    {
        $featured = Listing::query()
            ->onPublicMarketplace()
            ->with(['user:id,name,username,is_active_mode', 'user.linkedAccounts'])
            ->orderBy('expires_at')
            ->orderByDesc('id')
            ->limit(self::FEATURED_COUNT)
            ->get();

        SellerTrust::attachTo($featured);

        // Cache the RESOLVED resource array (not the Eloquent collection) —
        // caching `Eloquent\Collection` round-trips through the cache
        // driver's serialize/unserialize, which trips on `__PHP_Incomplete_Class`
        // when the blob is read back (especially on the Postgres cache driver).
        // `Game::booted` forgets this key on save/delete so admin edits show
        // immediately.
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
