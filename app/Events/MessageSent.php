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
 * `ShouldDispatchAfterCommit` + `ShouldBroadcast` — if a future caller wraps
 * `SendMessageAction` in an outer transaction, dispatching is held until
 * the transaction commits so a rolled-back message can't leak to listeners.
 * Today the Action dispatches outside its own transaction so the timing is
 * moot, but cheap defense in depth.
 *
 * Channel name is `match.{id}` (private). Auth callback in `routes/channels.php`
 * only allows the two match participants (creator via listing, taker).
 *
 * `broadcastWith()` mirrors the resource shape the frontend's chat list
 * already consumes — keeping the WebSocket payload identical to an
 * Inertia-refresh payload avoids a divergent serializer.
 */
class MessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  ?string  $correlationId  Client-generated UUID echoed back in the
     *                                  broadcast payload so the sender's frontend can match an optimistic
     *                                  pending bubble with the broadcast-confirmed message and replace it.
     *                                  Null for system messages and for any send that didn't supply one.
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
     * Use a stable event name for Echo listeners — without this the event
     * broadcasts as its fully-qualified PHP class name
     * (`App\\Events\\MessageSent`), coupling the frontend listener to the
     * backend namespace. With it, the frontend listens to `.message.sent`
     * (leading dot tells Echo to use the raw name, no auto-prefixing).
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
        // The queue worker that runs broadcastWith re-hydrates `$this->message`
        // from the DB without eager-loaded media. `loadMissing` covers that
        // path without re-querying when the in-memory model already has it
        // (synchronous broadcasts inside the same request, tests).
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
