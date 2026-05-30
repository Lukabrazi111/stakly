<?php

namespace App\Events;

use App\Models\Message;
use App\Support\MessageAttachmentsPayload;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a new message lands in a match's chat.
 *
 * `ShouldDispatchAfterCommit` defends against a future caller wrapping
 * `SendMessageAction` in an outer transaction — broadcasts are held until
 * commit so a rolled-back message can't leak to listeners.
 */
class MessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  ?string  $correlationId  Client-generated UUID echoed back so
     *                                  the sender can match an optimistic pending bubble with the
     *                                  broadcast-confirmed message and replace it. Null for system messages.
     */
    public function __construct(
        public Message $message,
        public ?string $correlationId = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("match.{$this->message->match_id}"),
        ];
    }

    /**
     * Stable event name decouples the Echo listener from the PHP class FQN.
     * Frontend listens to `.message.sent` (leading dot = raw name, no prefix).
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Queue worker rehydrates `$this->message` without eager-loaded media;
        // `loadMissing` covers it without re-querying for sync broadcasts.
        $this->message->loadMissing('media');

        return [
            'id' => $this->message->id,
            'match_id' => $this->message->match_id,
            'user_id' => $this->message->user_id,
            'type' => $this->message->type->value,
            'content' => $this->message->content,
            'attachments' => MessageAttachmentsPayload::forMessage($this->message),
            'correlation_id' => $this->correlationId,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
