<?php

namespace App\Events;

use App\Models\Message;
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

    public function __construct(public Message $message) {}

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
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'match_id' => $this->message->match_id,
            'user_id' => $this->message->user_id,
            'type' => $this->message->type->value,
            'content' => $this->message->content,
            'attachments' => $this->message->attachments_json,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
