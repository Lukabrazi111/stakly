<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate. The dashboard exposes job payloads, failed-job
     * traces, and retry/delete controls over the settlement + notification
     * pipeline, so it's locked to admins — the same `User::isAdmin()` check that
     * gates the Filament panel. Horizon bypasses this gate only in `local`.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => (bool) $user?->isAdmin());
    }
}
