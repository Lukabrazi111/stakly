<?php

namespace App\Providers;

use App\Listeners\RecordImpersonationEnd;
use App\Listeners\RecordImpersonationStart;
use App\Services\GameApi\ChessGameApi;
use App\Services\GameApi\GameApi;
use App\Services\GameApi\MockGameApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use STS\FilamentImpersonate\Events\EnterImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->bindGameApi();
    }

    /**
     * Bind the configured `GameApi` driver as a singleton. Singleton matters
     * because `MockGameApi`'s test helpers (`forceWinner`, `forceUnknown`)
     * mutate instance state — a fresh resolve per call would lose them.
     *
     * `MockGameApi` is also registered as its own concrete binding so tests
     * can resolve + force a winner without going through the public
     * `GameApi` interface (which on the `chess` driver is wrapped by
     * `ChessGameApi`). Both bindings resolve to the SAME singleton
     * instance so a forced winner applied on either is visible to both.
     *
     * Adding a future 'chess_com' driver (Phase 4b) means a new case here
     * plus a new `App\Services\GameApi\ChessComGameApi` implementation
     * following the same fallback-to-mock pattern.
     */
    private function bindGameApi(): void
    {
        $this->app->singleton(MockGameApi::class, fn () => new MockGameApi);

        $this->app->singleton(GameApi::class, function ($app) {
            $driver = config('stakly.game_api_driver');

            return match ($driver) {
                'mock' => $app->make(MockGameApi::class),
                'chess' => new ChessGameApi($app->make(MockGameApi::class)),
                default => throw new InvalidArgumentException("Unknown game_api_driver: {$driver}"),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerImpersonationListeners();
    }

    /**
     * Wire `stechstudio/filament-impersonate` events to our audit-row
     * persistence (M30 Phase 5).
     */
    private function registerImpersonationListeners(): void
    {
        Event::listen(EnterImpersonation::class, RecordImpersonationStart::class);
        Event::listen(LeaveImpersonation::class, RecordImpersonationEnd::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        // Safety net for `route()` calls outside the locale-prefix group —
        // Filament admin, queue workers, console commands. `SetLocale`
        // middleware only fires on web routes wrapped in `Route::prefix('{locale}')`,
        // so without this every server-side `route('listings.show', ...)` from
        // admin / a queued notification would 500 with `Missing parameter: {locale}`.
        // Middleware still overrides per-request for the locale-prefix group.
        URL::defaults(['locale' => config('stakly.default_locale', 'en')]);
    }
}
