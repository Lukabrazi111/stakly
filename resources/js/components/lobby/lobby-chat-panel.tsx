import { useMemo } from 'react';
import { MobileChatTrigger } from '@/components/match/mobile-chat-trigger';
import { useMatchChat } from '@/hooks/use-match-chat';
import type { Lobby, MatchPlayer } from '@/types';
import type { ChatMessage } from '@/types/match';

interface Props {
    matchId: number;
    initialMessages: ChatMessage[];
    viewerId: number;
    lobby: Lobby;
}

/**
 * Chat surface for team-play lobbies — wraps `useMatchChat` + the chat FAB.
 * Sibling pattern to `LobbyRealtimeSync`: the parent mounts this only when
 * the viewer is a live lobby participant, which keeps both the WebSocket
 * subscription and the UI gated together.
 *
 * Why a sub-component instead of inline: `useMatchChat` cannot be called
 * conditionally (React hook rule). Putting the hook here means non-
 * participants never invoke it — no `private-match.{id}` subscription,
 * no `/broadcasting/auth` POST, no 403 from `MatchChannel::join`'s
 * participant gate during `LobbyFilling`.
 */
export function LobbyChatPanel({
    matchId,
    initialMessages,
    viewerId,
    lobby,
}: Props) {
    const chat = useMatchChat(matchId, initialMessages, viewerId);

    const participants = useMemo<MatchPlayer[]>(
        () =>
            (
                [...lobby.roster.a, ...lobby.roster.b].filter(
                    (p) => p !== null,
                ) as Array<NonNullable<Lobby['roster']['a'][number]>>
            ).map((p) => p.user),
        [lobby.roster],
    );

    // Picks a non-creator placeholder for the legacy `taker` prop — chat
    // bubble lookup uses `participants` first, so this only matters when
    // the lobby has just the creator + no one else.
    const placeholderTaker: MatchPlayer =
        participants.find((p) => p.id !== lobby.creator.id) ?? lobby.creator;

    const isReadOnly =
        lobby.lobby_state === 'cancelled' || lobby.lobby_state === 'expired';

    return (
        <MobileChatTrigger
            messages={chat.messages}
            viewerId={viewerId}
            creator={lobby.creator}
            taker={placeholderTaker}
            participants={participants}
            isReadOnly={isReadOnly}
            isPending={chat.isPending}
            onSend={chat.send}
            onRetry={chat.retry}
            onDismiss={chat.dismiss}
            uploadProgress={chat.uploadProgress}
            containerClassName=""
        />
    );
}
