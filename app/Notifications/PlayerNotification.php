<?php

namespace App\Notifications;

use App\Enums\SoundPriority;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Base class for player-facing notifications. Multi-channel by default —
 * `database` (Stakly bell + history page) + `broadcast` (Reverb push for
 * real-time bell updates). `mail` is added by M20 without changes here;
 * `via()` filters by config.
 *
 * Queued because `broadcast` hits Reverb over HTTP — a sync dispatch on a
 * Reverb hiccup would fail the originating user's action (e.g. TakeListing).
 *
 * Subclasses implement the six abstract methods; the base assembles the
 * payload uniformly across channels.
 */
abstract class PlayerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    abstract public function eventType(): string;

    abstract public function soundPriority(): SoundPriority;

    abstract public function title(): string;

    abstract public function body(): string;

    abstract public function actionUrl(): string;

    abstract public function relatedId(): ?int;

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'event_type' => $this->eventType(),
            'title' => $this->title(),
            'body' => $this->body(),
            'action_url' => $this->actionUrl(),
            'sound_priority' => $this->soundPriority()->value,
            'related_id' => $this->relatedId(),
        ];
    }
}
