import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { LobbyCenterColumn } from '@/components/lobby/center/center-column';
import { LobbyChatPanel } from '@/components/lobby/lobby-chat-panel';
import { LobbyHeader } from '@/components/lobby/lobby-header';
import { LobbyRealtimeSync } from '@/components/lobby/lobby-realtime-sync';
import { TeamSlotColumn } from '@/components/lobby/team-slot-column';
import { join, kick, leave, ready } from '@/routes/lobbies';
import type { Lobby, LobbySide } from '@/types';
import type { ChatMessage } from '@/types/match';

// Locked is live too — match-status flips (settle / dispute / cancel /
// timeout) fire `LobbyUpdated` via `GameMatchObserver`, which the
// Reverb listener picks up to refresh the Coord pulse + chat-lock +
// header countdown live without a manual reload.
const LIVE_STATES = new Set(['recruiting', 'ready_checking', 'locked']);

interface Props {
    lobby: Lobby;
    messages: { data: ChatMessage[] };
}

/**
 * Team-play lobby UI block — hosted on `pages/listings/show.tsx` for
 * `listing.team_size > 1`. Encapsulates the polling loop, action handlers,
 * and the 3-col layout (Team A | center column | Team B) with chat as a
 * FAB at every viewport.
 */
export function TeamPlayLobbyView({ lobby, messages }: Props) {
    const { auth } = usePage().props;

    const viewerId = auth.user?.id ?? null;
    const matchId = lobby.match_id;

    // Real-time roster + state via Reverb. `LobbyRealtimeSync` subscribes
    // to `private-lobby.{id}` and triggers `router.reload({ only: ['lobby'] })`
    // on `.lobby.updated`. Mounted only while the lobby is live AND the
    // viewer is authenticated (private channels require auth) — terminal
    // states (locked / cancelled / expired) need no further updates, so
    // unmounting tears down the WebSocket subscription cleanly.
    const isRealtimeActive =
        auth.user !== null &&
        lobby.lobby_state !== null &&
        LIVE_STATES.has(lobby.lobby_state);

    const [isProcessing, setIsProcessing] = useState(false);

    const handleJoin = (side: LobbySide) => {
        if (viewerId === null) {
            return;
        }

        setIsProcessing(true);
        router.post(
            join({ listing: lobby.id }).url,
            { side },
            {
                preserveScroll: true,
                onFinish: () => setIsProcessing(false),
            },
        );
    };

    const handleLeave = () => {
        setIsProcessing(true);
        router.post(
            leave({ listing: lobby.id }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsProcessing(false),
            },
        );
    };

    const handleToggleReady = () => {
        setIsProcessing(true);
        router.post(
            ready({ listing: lobby.id }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsProcessing(false),
            },
        );
    };

    // User route binding resolves by username (User::getRouteKeyName returns
    // 'username'), so the lobby resource ships participant.user.username for
    // this lookup. We pass the slot's user object via TeamRoster → SlotCard.
    const handleKick = (username: string) => {
        setIsProcessing(true);
        router.delete(kick({ listing: lobby.id, user: username }).url, {
            preserveScroll: true,
            onFinish: () => setIsProcessing(false),
        });
    };

    return (
        <>
            {isRealtimeActive && <LobbyRealtimeSync listingId={lobby.id} />}

            <div className="space-y-6">
                <LobbyHeader lobby={lobby} />

                {/* The headline 3-col layout — Team A | Center | Team B at
                    xl+. Below xl the columns stack so the page reads
                    top-to-bottom: Team A → Center blocks → Team B. Gated at
                    xl (not lg) because the P8 FACEIT-roster slot card's
                    min-content (~300px) plus the 400px center column overflows
                    a 1024px viewport's two 1fr tracks — at lg the right column
                    spilled off-screen. The Money block hosts Ready / Leave
                    actions for the viewer; slot cards stay display-only. */}
                <div className="grid gap-4 xl:grid-cols-[1fr_minmax(360px,400px)_1fr] xl:items-start xl:gap-6">
                    <TeamSlotColumn
                        lobby={lobby}
                        side="a"
                        onJoin={handleJoin}
                        onKick={handleKick}
                    />
                    <LobbyCenterColumn
                        lobby={lobby}
                        onToggleReady={handleToggleReady}
                        onLeave={handleLeave}
                        isProcessing={isProcessing}
                    />
                    <TeamSlotColumn
                        lobby={lobby}
                        side="b"
                        onJoin={handleJoin}
                        onKick={handleKick}
                    />
                </div>
            </div>

            {/* Chat — mounts only when the viewer has joined a slot. The
                hook (`useMatchChat`) lives inside `LobbyChatPanel` so it
                only subscribes to `private-match.{id}` for participants;
                otherwise the chat channel auth would 403 non-participants
                during `LobbyFilling`. */}
            {auth.user &&
                matchId !== null &&
                viewerId !== null &&
                lobby.viewer?.is_participant && (
                    <LobbyChatPanel
                        matchId={matchId}
                        initialMessages={messages.data}
                        viewerId={viewerId}
                        lobby={lobby}
                    />
                )}
        </>
    );
}
