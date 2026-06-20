<?php

namespace App\Providers;

use App\Listeners\RecordImpersonationEnd;
use App\Listeners\RecordImpersonationStart;
use App\Services\GameApi\ChessGameApi;
use App\Services\GameApi\FaceitGameApi;
use App\Services\GameApi\GameApi;
use App\Services\GameApi\MockGameApi;
use App\Services\Payments\CryptomusGateway;
use App\Services\Payments\MockGateway;
use App\Services\Payments\NowPaymentsGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Provider\FaceitProvider;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use SocialiteProviders\Manager\SocialiteWasCalled;
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
        $this->bindPaymentGateway();
    }

    /**
     * Bind the configured `GameApi` driver as a singleton. Singleton matters
     * because `MockGameApi`'s test helpers (`forceWinner`, `forceUnknown`)
     * mutate instance state — a fresh resolve per call would lose them.
     *
     * `MockGameApi` is also registered as its own concrete binding so tests
     * can resolve + force a winner without going through the public
     * `GameApi` interface (which on the `chess` driver is wrapped by the
     * production composition chain). Both bindings resolve to the SAME
     * singleton instance so a forced winner applied on either is visible
     * to both.
     *
     * M15 P5 — the `chess` driver is now a composition chain
     * (`FaceitGameApi → ChessGameApi → MockGameApi`), not a chess-only
     * adapter. Each link inspects the match's chat for its own provider's
     * card and falls through on miss. So a chess match with a chess card
     * resolves via `ChessGameApi`; a CS2 match with a FACEIT card resolves
     * via `FaceitGameApi`; a card-less match of either game falls through
     * to `MockGameApi` (Unknown → ManualReview). The driver name `chess`
     * is retained for env-config backwards compatibility — adding a new
     * non-chess game adapter (e.g. Riot for Valorant) means prepending a
     * new link to the chain here, not creating a new driver case.
     */
    private function bindGameApi(): void
    {
        $this->app->singleton(MockGameApi::class, fn () => new MockGameApi);

        $this->app->singleton(GameApi::class, function ($app) {
            $driver = config('stakly.game_api_driver');

            return match ($driver) {
                'mock' => $app->make(MockGameApi::class),
                'chess' => new FaceitGameApi(
                    new ChessGameApi($app->make(MockGameApi::class)),
                ),
                default => throw new InvalidArgumentException("Unknown game_api_driver: {$driver}"),
            };
        });
    }

    /**
     * Bind the configured `PaymentGateway` driver (M9 — billing). Mirrors
     * `bindGameApi()`: a config-driven `match()` over `services.payments.driver`
     * is the entire switch surface, so moving NowPayments ⇄ Cryptomus ⇄ mock is
     * one env change plus one implementation class. Default `mock` is
     * provider-agnostic — no signup/keys/sandbox. The real provider gateways
     * are stubs that throw until M9 go-ahead.
     */
    private function bindPaymentGateway(): void
    {
        $this->app->singleton(PaymentGateway::class, function () {
            $driver = config('services.payments.driver');

            return match ($driver) {
                'mock' => new MockGateway,
                'nowpayments' => new NowPaymentsGateway,
                'cryptomus' => new CryptomusGateway,
                default => throw new InvalidArgumentException("Unknown payments driver: {$driver}"),
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
        $this->registerSocialiteListeners();
        $this->registerProviderRateLimiters();
    }

    /**
     * Self-throttle every outbound third-party API call (M35 / CLAUDE.md rule).
     * Each limiter is consumed by `RateLimited` job middleware on the
     * matching `AutoFetch*GameJob`. Caps live in `config/services.*` so
     * they're tunable per-environment without a code change.
     *
     * When a job exceeds the limit the middleware releases it back to the
     * queue (consuming an attempt — the job's `$tries` includes headroom
     * for plausible release counts during burst traffic).
     */
    private function registerProviderRateLimiters(): void
    {
        RateLimiter::for('chess-com-api', fn () => Limit::perMinute(
            (int) config('services.chess_com.requests_per_minute', 30),
        ));

        RateLimiter::for('lichess-api', fn () => Limit::perMinute(
            (int) config('services.lichess.requests_per_minute', 60),
        ));

        RateLimiter::for('faceit-api', fn () => Limit::perMinute(
            (int) config('services.faceit.requests_per_minute', 30),
        ));
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
     * Register Socialite community providers (M15 Phase 2). The FACEIT
     * driver uses our local `App\Services\Provider\FaceitProvider` —
     * a thin PKCE-aware subclass of `socialiteproviders/faceit`'s
     * provider. FACEIT's App Studio only exposes PKCE-enabled OAuth
     * clients these days, and the upstream package (last tagged 2022)
     * doesn't send `code_verifier` on token exchange.
     */
    private function registerSocialiteListeners(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('faceit', FaceitProvider::class);
        });
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
