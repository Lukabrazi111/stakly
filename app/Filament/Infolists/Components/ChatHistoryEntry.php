<?php

namespace App\Filament\Infolists\Components;

use App\Enums\MessageType;
use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\Message;
use Filament\Infolists\Components\Entry;
use Illuminate\Support\Collection;

/**
 * Read-only chat-history renderer for the admin dispute panel. The Blade
 * view is deliberately plain (no React) — admin doesn't need the live
 * websocket UX, just the immutable record of what happened.
 */
class ChatHistoryEntry extends Entry
{
    protected string $view = 'filament.infolists.components.chat-history-entry';

    /**
     * Memoized user_id => side ('a'|'b') map for team-play lobbies, built once
     * from the listing's lobby participants to avoid an N+1 across messages.
     *
     * @var array<int, string>|null
     */
    private ?array $sideMap = null;

    private bool $sideMapBuilt = false;

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

    public function getCreatorId(): ?int
    {
        $record = $this->getRecord();

        return $record instanceof GameMatch ? $record->listing?->user_id : null;
    }

    public function getTakerId(): ?int
    {
        $record = $this->getRecord();

        return $record instanceof GameMatch ? $record->taker_user_id : null;
    }

    public function roleOf(Message $message): string
    {
        if ($message->type === MessageType::System) {
            return 'system';
        }

        $record = $this->getRecord();

        // Team play has no taker (taker_user_id is null); every speaker except
        // the lobby owner is resolved to their side via the participant map.
        if ($record instanceof GameMatch && $record->listing?->isTeamPlay()) {
            if ($message->user_id === $this->getCreatorId()) {
                return 'creator';
            }

            return match ($this->sideMap()[$message->user_id] ?? null) {
                LobbyParticipant::SIDE_A => 'team_a',
                LobbyParticipant::SIDE_B => 'team_b',
                default => 'other',
            };
        }

        if ($message->user_id === $this->getCreatorId()) {
            return 'creator';
        }

        if ($message->user_id === $this->getTakerId()) {
            return 'taker';
        }

        return 'other';
    }

    /**
     * Build the user_id => side map once for the current team-play listing.
     * Live (non-kicked) rows take precedence over kicked rows for the same
     * user, so a departed speaker still maps to whatever side they last held.
     *
     * @return array<int, string>
     */
    private function sideMap(): array
    {
        if ($this->sideMapBuilt) {
            return $this->sideMap ?? [];
        }

        $this->sideMapBuilt = true;
        $this->sideMap = [];

        $record = $this->getRecord();

        if (! $record instanceof GameMatch || ! $record->listing?->isTeamPlay()) {
            return $this->sideMap;
        }

        $record->listing->loadMissing('lobbyParticipants');

        $participants = $record->listing->lobbyParticipants
            ->sortBy(fn (LobbyParticipant $participant): bool => $participant->kicked_at !== null);

        foreach ($participants as $participant) {
            if ($participant->user_id === null || $participant->side === null) {
                continue;
            }

            $this->sideMap[$participant->user_id] ??= $participant->side;
        }

        return $this->sideMap;
    }

    public function attachmentUrl(Message $message, bool $thumb = true): ?string
    {
        $media = $message->getFirstMedia(Message::ATTACHMENTS_COLLECTION);

        if (! $media) {
            return null;
        }

        $base = route(
            'matches.messages.attachment',
            [
                'match' => $message->match_id,
                'message' => $message->id,
                'media' => $media->id,
            ],
        );

        // Thumbnail conversion only exists for image media — PDFs return the
        // original file at the same URL regardless of the `thumb` flag.
        return $thumb && str_starts_with((string) $media->mime_type, 'image/')
            ? $base.'?conversion='.Message::THUMBNAIL_CONVERSION
            : $base;
    }

    public function attachmentIsImage(Message $message): bool
    {
        $media = $message->getFirstMedia(Message::ATTACHMENTS_COLLECTION);

        return $media !== null
            && str_starts_with((string) $media->mime_type, 'image/');
    }

    public function attachmentName(Message $message): ?string
    {
        $media = $message->getFirstMedia(Message::ATTACHMENTS_COLLECTION);

        return $media?->name ?: $media?->file_name;
    }

    public function attachmentSizeLabel(Message $message): ?string
    {
        $media = $message->getFirstMedia(Message::ATTACHMENTS_COLLECTION);

        if (! $media) {
            return null;
        }

        $bytes = (int) $media->size;

        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1024 / 1024, 1).' MB';
    }
}
