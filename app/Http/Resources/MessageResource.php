<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Support\MessageAttachmentsPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a chat message. Shape mirrors `MessageSent::broadcastWith()`
 * so the frontend can render initial-load and live-broadcast messages with
 * the same type. The frontend derives sender display info (name/username)
 * from the `match.creator` / `match.taker` props by matching `user_id` —
 * embedding the user object here would be redundant for the two-participant
 * universe of a single match.
 *
 * `attachments` is built via `MessageAttachmentsPayload` so the initial-load
 * and broadcast paths share one source of truth. See that class for shape.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'match_id' => $this->match_id,
            'user_id' => $this->user_id,
            'type' => $this->type->value,
            'content' => $this->content,
            'attachments' => MessageAttachmentsPayload::forMessage($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
