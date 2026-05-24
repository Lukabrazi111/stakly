<?php

namespace App\Filament\Infolists\Components;

use App\Models\GameMatch;
use App\Models\Message;
use Filament\Infolists\Components\Entry;
use Illuminate\Support\Collection;

/**
 * M12 Phase 2 — read-only chat-history renderer for the admin dispute
 * panel. Loads every message on the match (text, system, image
 * attachments, link cards, auto-fetched provider cards) and hands the
 * collection to the Blade view, which is responsible for the actual
 * markup. The Blade view is intentionally simple (Tailwind utility
 * classes, no React) — admin doesn't need the live websocket UX, just
 * the immutable record of what happened.
 *
 * Eager loads:
 *   - `user` so we can show "Alice said:" without an extra query per row
 *   - `media` so attachment URLs resolve cheaply
 */
class ChatHistoryEntry extends Entry
{
    protected string $view = 'filament.infolists.components.chat-history-entry';

    public function getMessages(): Collection
    {
        $record = $this->getRecord();

        if (! $record instanceof GameMatch) {
            return collect();
        }

        return $record->messages()
            ->with(['user', 'media'])
            ->orderBy('id')
            ->get();
    }

    public function attachmentUrl(Message $message, bool $thumb = true): ?string
    {
        $media = $message->getFirstMedia(Message::ATTACHMENTS_COLLECTION);

        if (! $media) {
            return null;
        }

        return route(
            'matches.messages.attachment',
            [
                'match' => $message->match_id,
                'message' => $message->id,
                'media' => $media->id,
            ],
        ).($thumb ? '?conversion='.Message::THUMBNAIL_CONVERSION : '');
    }
}
