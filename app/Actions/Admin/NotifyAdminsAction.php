<?php

namespace App\Actions\Admin;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Broadcast a Filament notification to every user with the `admin` Spatie role.
 */
class NotifyAdminsAction
{
    public function handle(string $title, string $body, ?string $url = null, string $color = 'warning'): void
    {
        // `whereHas` rather than Spatie's `role()` scope — the scope throws
        // `RoleDoesNotExist` when the admin role hasn't been seeded yet.
        $admins = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->where('is_platform', false)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->color($color)
            ->icon('heroicon-o-exclamation-triangle');

        if ($url !== null) {
            $notification->actions([
                Action::make('view')
                    ->label('Open match')
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        $notification->sendToDatabase($admins, isEventDispatched: true);
    }
}
