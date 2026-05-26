<?php

namespace App\Actions\Message;

use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\GameMatch;
use App\Models\Message;

/**
 * Posts a system message into a match's chat thread. Used by lifecycle
 * Actions (TakeListing, SettleMatch, OpenDispute, ResolveMatchTimeout,
 * SettleFromCard, etc.) to narrate state changes inline with the player
 * conversation: match started, settled, dispute opened, API resolved,
 * timeout flagged, etc.
 *
 * Why a separate Action from `SendMessageAction`:
 *   - `type = system` is hard-coded here — there is no HTTP path that can
 *     reach this Action, so system messages can never be impersonated by
 *     a malicious user POSTing crafted JSON.
 *   - No rate limit (system events fire as fast as the lifecycle moves).
 *   - No chat-status guard (the UX-driven "chat read-only after settle"
 *     rule applies to user messages, not platform notifications — admin
 *     resolutions in the Filament panel (M12) also post here on locked
 *     matches).
 *
 * Broadcasts via the same `MessageSent` event used by user messages, so
 * subscribed Echo clients render system bubbles in real time alongside
 * normal chat.
 */
class PostSystemMessageAction
{
    /**
     * @param  list<array<string, mixed>>|null  $attachments  Optional structured
     *                                                        payload appended to `attachments_json`. Phase 4 auto-fetch posts
     *                                                        game-card system messages this way (text describes the event,
     *                                                        attachment is the verified card the frontend renders).
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
