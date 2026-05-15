<?php

namespace App\Providers;

use App\Services\GameApi\GameApi;
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
     * Only 'mock' is wired in v1. Adding 'chess_com' / 'lichess' in M8 means
     * adding a case here and a new `GameApi` implementation.
     */
    private function bindGameApi(): void
    {
        $this->app->singleton(GameApi::class, function () {
            $driver = config('stakly.game_api_driver');

            return match ($driver) {
                'mock' => new MockGameApi,
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
