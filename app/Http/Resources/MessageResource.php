<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Support\MessageAttachmentsPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape mirrors `MessageSent::broadcastWith()` so the FE renders initial-load
 * and live-broadcast messages with the same type. Sender display info is
 * derived from `match.creator` / `match.taker` by matching `user_id`, so the
 * user object isn't embedded here. `attachments` goes through
 * `MessageAttachmentsPayload` to keep load and broadcast on one source.
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
