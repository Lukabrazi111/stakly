<?php

namespace App\Actions\Admin;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * M17 Phase 2 — broadcast a Filament notification to every user with the
 * `admin` Spatie role.
 *
 * Uses `sendToDatabase(..., isEventDispatched: true)` so the notification
 * (a) persists in the `notifications` table (Laravel's default — accessible
 * via the bell icon in the Filament panel header thanks to
 * `databaseNotifications()` on the panel provider), and (b) dispatches a
 * `DatabaseNotificationsSent` Echo event so any admin with the panel open
 * sees the bell badge update without polling.
 *
 * Echo + Reverb infrastructure already exists for chat broadcasts (M8) —
 * no new setup needed.
 *
 * Defensive: never includes platform users in the recipient list, even
 * if `is_platform = true` users somehow get the admin role (they can't
 * log into the panel either way per `User::canAccessPanel`, but
 * notifying them would still write a row to the notifications table).
 */
class NotifyAdminsAction
{
    public function handle(string $title, string $body, ?string $url = null, string $color = 'warning'): void
    {
        // Use `whereHas` rather than Spatie's `role()` scope because the
        // scope throws `RoleDoesNotExist` when the admin role hasn't been
        // seeded yet (tests that don't touch admin, fresh deploys
        // pre-AdminUserSeeder, etc.). `whereHas` returns an empty result
        // in that case, which is the right no-op behavior for a notifier.
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
