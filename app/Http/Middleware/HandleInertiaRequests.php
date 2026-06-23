<?php

namespace App\Http\Middleware;

use App\Enums\Game;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Notifications\PlayerNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Eager-load once so the backwards-compat accessors
        // (`chess_com_username`, etc.) + the `has_chess_link` /
        // `linked_platforms` flags all read from the same collection —
        // no N+1 across the hot Inertia shared-data path.
        $user?->load('linkedAccounts');

        // Only join the moderation log when the user is actually banned —
        // skips an extra query on every Inertia request for the common case.
        if ($user !== null && $user->banned_at !== null) {
            $user->load('latestBanLog');
        }

        // M36/M37 — the user's in-flight matches (Pending / Disputed /
        // ManualReview), team-aware, fetched once. The count powers the sidebar
        // "Matches" badge; the distinct games gate the Take button (M37, one
        // active match per game). At most a couple rows under the per-game cap,
        // so the eager-load is cheap.
        $inFlightMatches = $user !== null
            ? GameMatch::query()
                ->forRosterParticipant($user->id)
                ->whereIn('status', MatchStatus::inProgressValues())
                ->with('listing:id,game')
                ->get(['id', 'listing_id'])
            : collect();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Closures — Inertia evaluates them at response-render time, by
            // which the route middleware (SetLocale) has fired. A static
            // value here would capture the default 'en' set before the
            // middleware chain reaches the route layer.
            'locale' => fn () => App::getLocale(),
            'availableLocales' => fn () => $this->availableLocales(),
            'translations' => fn () => $this->translationsFor(App::getLocale()),
            'auth' => [
                // Float at the JSON boundary (decimal:6 serializes as
                // string by default).
                'user' => $user ? [
                    ...$user->toArray(),
                    'usdt_balance' => (float) $user->usdt_balance,
                    'is_active_mode' => (bool) $user->is_active_mode,
                    // Take-gate + create-gate (permissive "any chess provider" check).
                    'has_chess_link' => $user->hasVerifiedChessLink(),
                    // Per-listing-platform UI reads this to render
                    // platform-specific Take button copy + create-form
                    // picker. Order matches `App\Enums\LinkedAccountProvider`.
                    'linked_platforms' => $user->linkedAccounts
                        ->sortBy(fn ($la) => $la->provider->value)
                        ->pluck('provider')
                        ->map(fn ($provider) => $provider->value)
                        ->values()
                        ->all(),
                    'notifications_last_seen_at' => $user->notifications_last_seen_at?->toIso8601String(),
                    'unread_notifications_count' => $user->playerNotifications()
                        ->where('created_at', '>', $user->notifications_last_seen_at ?? '1970-01-01')
                        ->count(),
                    // M36: matches the user is mid-flight on (Pending / Disputed
                    // / ManualReview), team-aware. Powers the sidebar "Matches"
                    // badge. Derived from the single `$inFlightMatches` fetch.
                    'active_matches_count' => $inFlightMatches->count(),
                    // M37: the distinct games the user is currently mid-match in
                    // — gates the Take button (one active match per game).
                    'in_flight_games' => $inFlightMatches
                        ->pluck('listing.game')
                        ->filter()
                        ->map(fn (Game $game) => $game->value)
                        ->unique()
                        ->values()
                        ->all(),
                    'notification_sound' => $user->notification_sound ?? PlayerNotification::DEFAULT_SOUND_CHOICE,
                    'notification_sound_map' => collect(PlayerNotification::EVENT_TYPES)
                        ->mapWithKeys(fn (string $eventType) => [
                            $eventType => $user->getNotificationPreference($eventType)['sound'],
                        ])
                        ->all(),
                    'username_edit' => [
                        'can_change' => $user->canChangeUsername(),
                        'available_at' => $user->usernameChangeAvailableAt()?->toIso8601String(),
                        'blockers' => $user->usernameChangeBlockers(),
                    ],
                    'ban' => $user->banned_at !== null ? [
                        'reason' => $user->latestBanLog?->reason ?? __('Reason not recorded.'),
                        'banned_at' => $user->banned_at->toIso8601String(),
                    ] : null,
                ] : null,
            ],
            'status' => fn () => $request->session()->get('status'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'playerSidebarCollapsed' => $request->cookie('player_sidebar_collapsed') === 'true',
            // Listings page layout preference. Cookie set client-side on
            // toggle change so the next SSR render matches without flash.
            // Whitelist validation keeps a tampered cookie from poisoning
            // the prop. Default is 'grid' — the richer surface the product
            // is being built around (roster preview avatars, ready-check
            // banner, etc.). Users who want the dense comparison view can
            // toggle to rows and the choice persists via cookie.
            'listingsViewLayout' => in_array(
                $request->cookie('listings_view_layout'),
                ['rows', 'grid'],
                true,
            )
                ? $request->cookie('listings_view_layout')
                : 'grid',
        ];
    }

    /**
     * @return list<array{code: string, native_label: string}>
     */
    private function availableLocales(): array
    {
        return collect(config('stakly.locales_meta', []))
            ->map(fn (array $meta, string $code): array => [
                'code' => $code,
                'native_label' => $meta['native_label'] ?? $code,
            ])
            ->values()
            ->all();
    }

    /**
     * Inertia ships only the active locale's bag — keeps the shared-props
     * payload small. Missing files return `[]`, which lets `useT()` fall
     * back to the key (Laravel's `__()` behavior on the server).
     *
     * @return array<string, string>
     */
    private function translationsFor(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), associative: true);

        return is_array($decoded) ? $decoded : [];
    }
}
