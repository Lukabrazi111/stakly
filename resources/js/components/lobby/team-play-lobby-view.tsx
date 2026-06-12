import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { LobbyActions } from '@/components/lobby/lobby-actions';
import { LobbyStatusBanner } from '@/components/lobby/lobby-status-banner';
import { TeamRoster } from '@/components/lobby/team-roster';
import { ChatPanel } from '@/components/match/chat-panel';
import { MobileChatTrigger } from '@/components/match/mobile-chat-trigger';
import { useMatchChat } from '@/hooks/use-match-chat';
import { join, kick, leave, ready } from '@/routes/lobbies';
import type { ChatMessage } from '@/types/match';
import type { Lobby, LobbySide, MatchPlayer } from '@/types';

const POLL_INTERVAL_MS = 5000;

const TERMINAL_STATES = new Set(['locked', 'cancelled', 'expired']);

interface Props {
    lobby: Lobby;
    messages: { data: ChatMessage[] };
}

/**
 * Team-play lobby UI block — hosted on `pages/listings/show.tsx` for
 * `listing.team_size > 1`. Encapsulates the polling loop, action handlers,
 * and the responsive 2-col layout (TeamRoster | Chat) inherited from the
 * original `pages/lobby/show.tsx` (M34 P3) so the routing flip is a pure
 * reparenting, not a rebuild.
 */
export function TeamPlayLobbyView({ lobby, messages }: Props) {
    const { auth } = usePage().props;

    const viewerId = auth.user?.id ?? null;
    const matchId = lobby.match_id;

    // Lobby chat sits on the same Reverb channel as match chat — the paired
    // GameMatch row exists from day 1 (M34 P1's CreateTeamPlayListingAction).
    const chat = useMatchChat(matchId ?? 0, messages.data, viewerId);

    const participants = useMemo<MatchPlayer[]>(
        () =>
            (
                [...lobby.roster.a, ...lobby.roster.b].filter(
                    (p) => p !== null,
                ) as Array<NonNullable<Lobby['roster']['a'][number]>>
            ).map((p) => p.user),
        [lobby.roster],
    );

    // Polling-based real-time updates. Reverb broadcast events on
    // `lobby:{listing_id}` are a deferred follow-up — 5 s polling delivers
    // the experience without the broadcast plumbing.
    useEffect(() => {
        if (
            lobby.lobby_state === null ||
            TERMINAL_STATES.has(lobby.lobby_state)
        ) {
            return;
        }

        const id = window.setInterval(() => {
            router.reload({ only: ['lobby'] });
        }, POLL_INTERVAL_MS);

        return () => window.clearInterval(id);
    }, [lobby.lobby_state]);

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

    // Pick a non-creator placeholder for the legacy `taker` prop — chat
    // bubble lookup uses `participants` first, so this only matters when
    // the lobby has just the creator + no one else (chat is empty anyway).
    const placeholderTaker: MatchPlayer =
        participants.find((p) => p.id !== lobby.creator.id) ?? lobby.creator;

    return (
        <>
            <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_440px] lg:gap-6">
                <div className="min-w-0 space-y-6">
                    <LobbyStatusBanner lobby={lobby} />

                    <TeamRoster
                        lobby={lobby}
                        onJoin={handleJoin}
                        onKick={handleKick}
                    />

                    <LobbyActions
                        lobby={lobby}
                        onToggleReady={handleToggleReady}
                        onLeave={handleLeave}
                        isProcessing={isProcessing}
                    />
                </div>

                {auth.user && matchId !== null && (
                    <aside className="hidden lg:sticky lg:top-28 lg:block lg:h-[750px]">
                        <ChatPanel
                            messages={chat.messages}
                            viewerId={auth.user.id}
                            creator={lobby.creator}
                            taker={placeholderTaker}
                            participants={participants}
                            isReadOnly={
                                lobby.lobby_state === 'cancelled' ||
                                lobby.lobby_state === 'expired'
                            }
                            isPending={chat.isPending}
                            onSend={chat.send}
                            onRetry={chat.retry}
                            onDismiss={chat.dismiss}
                            uploadProgress={chat.uploadProgress}
                        />
                    </aside>
                )}
            </div>

            {auth.user && matchId !== null && (
                <MobileChatTrigger
                    messages={chat.messages}
                    viewerId={auth.user.id}
                    creator={lobby.creator}
                    taker={placeholderTaker}
                    participants={participants}
                    isReadOnly={
                        lobby.lobby_state === 'cancelled' ||
                        lobby.lobby_state === 'expired'
                    }
                    isPending={chat.isPending}
                    onSend={chat.send}
                    onRetry={chat.retry}
                    onDismiss={chat.dismiss}
                    uploadProgress={chat.uploadProgress}
                />
            )}
        </>
    );
}
