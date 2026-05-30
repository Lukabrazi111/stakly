<?php

namespace App\Actions\Message;

use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\GameMatch;
use App\Models\Message;

/**
 * Posts a system message into a match's chat thread. Separate from `SendMessageAction`
 * so `type = system` is hard-coded (no HTTP path can reach this — system messages can
 * never be impersonated) and so there's no rate limit / chat-status guard (admin actions
 * in Filament post here on locked matches).
 */
class PostSystemMessageAction
{
    /**
     * @param  list<array<string, mixed>>|null  $attachments  Optional structured payload
     *                                                        appended to `attachments_json` (e.g. auto-fetched game cards).
     */
    public function handle(GameMatch $match, string $content, ?array $attachments = null): Message
    {
        $message = Message::create([
            'match_id' => $match->id,
            'user_id' => null,
            'type' => MessageType::System,
            'content' => $content,
            'attachments_json' => $attachments,
        ]);

        MessageSent::dispatch($message);

        return $message;
    }
}
