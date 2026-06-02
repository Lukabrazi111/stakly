<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
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
