<?php

namespace App\Providers;

use App\Services\GameApi\GameApi;
use App\Services\GameApi\LichessGameApi;
use App\Services\GameApi\MockGameApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

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
     * `GameApi` interface (which on the `lichess` driver is wrapped by
     * `LichessGameApi`). Both bindings resolve to the SAME singleton
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
                'lichess' => new LichessGameApi($app->make(MockGameApi::class)),
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
    }
}
