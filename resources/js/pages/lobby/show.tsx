import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { GameChip } from '@/components/listings/game-chip';
import { LobbyActions } from '@/components/lobby/lobby-actions';
import { LobbyStatusBanner } from '@/components/lobby/lobby-status-banner';
import { TeamRoster } from '@/components/lobby/team-roster';
import { ChatPanel } from '@/components/match/chat-panel';
import { MobileChatTrigger } from '@/components/match/mobile-chat-trigger';
import { BackLink } from '@/components/site/back-link';
import { PageMeta } from '@/components/site/page-meta';
import { useMatchChat } from '@/hooks/use-match-chat';
import SiteLayout from '@/layouts/site-layout';
import { useT } from '@/lib/i18n';
import { index as listingsIndex } from '@/routes/listings';
import { join, kick, leave, ready } from '@/routes/lobbies';
import type { Lobby, LobbyShowProps, LobbySide, MatchPlayer } from '@/types';

const POLL_INTERVAL_MS = 5000;

const TERMINAL_STATES = new Set(['locked', 'cancelled', 'expired']);

export default function LobbyShow({ lobby, messages }: LobbyShowProps) {
    const t = useT();
    const { auth } = usePage().props;

    const viewerId = auth.user?.id ?? null;
    const matchId = lobby.match_id;

    // Lobby chat sits on the same Reverb channel as match chat — paired
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
    // `lobby:{listing_id}` are a deferred follow-up — 5 s polling
    // delivers the experience without the broadcast plumbing.
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
    // 'username'), so the Lobby resource ships participant.user.username for
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

    const skillRange =
        lobby.skill_min !== null && lobby.skill_max !== null
            ? `${lobby.skill_min}–${lobby.skill_max}`
            : t('Any skill');

    return (
        <SiteLayout>
            <PageMeta
                title={t('Lobby #:id', { id: lobby.id })}
                description={t('Team-play lobby for :stake USDT.', {
                    stake: lobby.stake_amount.toFixed(0),
                })}
                noindex
            />

            <div className="mx-auto max-w-7xl px-4 py-10 md:px-6 md:py-14">
                <div className="mb-6">
                    <BackLink fallback={listingsIndex().url} />
                </div>

                <header className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <h1 className="font-display text-3xl font-bold tracking-tight text-foreground">
                            {t(':teamSize v :teamSize lobby', {
                                teamSize: lobby.team_size,
                            })}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('Hosted by :name', {
                                name: lobby.creator.name,
                            })}
                            {lobby.region !== null && (
                                <>
                                    {' · '}
                                    {lobby.region}
                                </>
                            )}
                            {' · '}
                            {skillRange}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <GameChip game={lobby.game} />
                        {!lobby.is_public && (
                            <span className="inline-flex items-center rounded-full border border-accent/40 bg-accent/10 px-3 py-1 text-xs font-medium text-accent">
                                {t('Invite-only')}
                            </span>
                        )}
                    </div>
                </header>

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
            </div>
        </SiteLayout>
    );
}
