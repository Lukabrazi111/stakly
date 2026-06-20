<?php

namespace App\Providers\Filament;

use App\Filament\MultiFactor\FortifyAppAuthentication;
use App\Filament\Widgets\OpsOverview;
use App\Filament\Widgets\PipelineHealth;
use App\Filament\Widgets\ProviderCircuitBanner;
use App\Http\Middleware\RequireAdminTwoFactor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->multiFactorAuthentication([
                FortifyAppAuthentication::make(),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                // M14 Slice 2d — banner renders only when a provider's
                // circuit breaker has tripped. Hidden via `canView()` when
                // all chess providers are closed.
                ProviderCircuitBanner::class,
                // Bundle stats into one widget so Filament renders them as a
                // responsive horizontal grid — `StatsOverviewWidget` defaults
                // to `columnSpan = 'full'`, so separate widgets stack
                // full-width vertically.
                OpsOverview::class,
                PipelineHealth::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RequireAdminTwoFactor::class,
            ]);
    }
}
