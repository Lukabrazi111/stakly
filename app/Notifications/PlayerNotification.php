<?php

namespace App\Notifications;

use App\Models\User;
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

    /** @var list<string> */
    public const EVENT_TYPES = [
        'listing_taken',
        'listing_expired',
        'match_settled',
        'match_manual_review',
        'dispute_opened',
        'dispute_resolved',
        'cancellation_requested',
        'cancellation_accepted',
        'cancellation_rejected',
        'account_banned',
        'account_restored',
    ];

    /** Subset exposed in the /settings/notifications UI. The rest always fire (no opt-out). */
    public const CONFIGURABLE_EVENT_TYPES = [
        'listing_taken',
        'match_settled',
        'match_manual_review',
        'dispute_opened',
        'cancellation_requested',
    ];

    /** Money-affecting events that can't be silenced. */
    public const MANDATORY_EVENT_TYPES = [
        'match_settled',
        'cancellation_requested',
    ];

    public const SOUND_CHOICES = ['off', 'classic', 'soft', 'ding'];

    public const DEFAULT_SOUND_CHOICE = 'classic';

    /** Events whose per-event Sound preference defaults to ON. */
    public const SOUND_DEFAULT_EVENT_TYPES = [
        'listing_taken',
    ];

    /**
     * @return array{in_app: bool, sound: bool, email: bool}
     */
    public static function defaultPreference(string $eventType): array
    {
        return [
            'in_app' => true,
            'sound' => in_array($eventType, self::SOUND_DEFAULT_EVENT_TYPES, true),
            'email' => true,
        ];
    }

    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database', 'broadcast'];
        }

        $eventType = $this->eventType();

        if (in_array($eventType, self::MANDATORY_EVENT_TYPES, true)) {
            return ['database', 'broadcast'];
        }

        if (! $notifiable->getNotificationPreference($eventType)['in_app']) {
            return [];
        }

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
            'related_id' => $this->relatedId(),
        ];
    }
}
