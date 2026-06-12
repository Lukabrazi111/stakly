import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { LobbyCenterColumn } from '@/components/lobby/center/center-column';
import { TeamSlotColumn } from '@/components/lobby/team-slot-column';
import { MobileChatTrigger } from '@/components/match/mobile-chat-trigger';
import { useMatchChat } from '@/hooks/use-match-chat';
import { join, kick, leave, ready } from '@/routes/lobbies';
import type { Lobby, LobbySide, MatchPlayer } from '@/types';
import type { ChatMessage } from '@/types/match';

const POLL_INTERVAL_MS = 5000;

const TERMINAL_STATES = new Set(['locked', 'cancelled', 'expired']);

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

    const isReadOnlyChat =
        lobby.lobby_state === 'cancelled' || lobby.lobby_state === 'expired';

    return (
        <>
            <div className="space-y-6">
                {/* The headline 3-col layout — Team A | Center | Team B at
                    lg+. Below lg the columns stack so mobile reads
                    top-to-bottom: Team A → Center blocks → Team B. The
                    Money block hosts Ready / Leave actions for the viewer;
                    slot cards stay display-only. */}
                <div className="grid gap-4 lg:grid-cols-[1fr_minmax(360px,400px)_1fr] lg:items-start lg:gap-6">
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

            {/* Chat — FAB only when the viewer has joined. The chat is a
                team-coordination room, not a public comment thread; browsers
                see roster + stats but not banter. Once they join, FAB appears. */}
            {auth.user && matchId !== null && lobby.viewer?.is_participant && (
                <MobileChatTrigger
                    messages={chat.messages}
                    viewerId={auth.user.id}
                    creator={lobby.creator}
                    taker={placeholderTaker}
                    participants={participants}
                    isReadOnly={isReadOnlyChat}
                    isPending={chat.isPending}
                    onSend={chat.send}
                    onRetry={chat.retry}
                    onDismiss={chat.dismiss}
                    uploadProgress={chat.uploadProgress}
                    containerClassName=""
                />
            )}
        </>
    );
}
