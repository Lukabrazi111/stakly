<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Player-facing notification row — surfaces the `data` jsonb payload assembled
 * by `App\Notifications\PlayerNotification::payload()` for the bell dropdown
 * and the full `/notifications` page.
 *
 * Read state lives in two independent places on purpose:
 *   - `read_at` — per-item, toggled by clicking the item or "Mark all read."
 *   - Bell badge count — notifications with `created_at >
 *     users.notifications_last_seen_at`, computed client-side. Opening the
 *     bell bumps `last_seen_at`, which clears the badge without touching any
 *     item's `read_at`.
 *
 * Filament admin notifications share the same `notifications` table; the
 * controller filters them out via `type LIKE 'App\Notifications\%'`, so this
 * resource only ever wraps PlayerNotification subclasses.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data;

        return [
            'id' => $this->id,
            'event_type' => $data['event_type'] ?? null,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'action_url' => $data['action_url'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'sound_priority' => $data['sound_priority'] ?? 'none',
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
