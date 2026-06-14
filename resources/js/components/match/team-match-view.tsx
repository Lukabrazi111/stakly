import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { GameChip } from '@/components/listings/game-chip';
import { AdminReviewBanner } from '@/components/match/admin-review-banner';
import { CancellationRequestBanner } from '@/components/match/cancellation-request-banner';
import { CancellationSummary } from '@/components/match/cancellation-summary';
import { ChatPanel } from '@/components/match/chat-panel';
import { MatchFaq } from '@/components/match/match-faq';
import { MatchTimer } from '@/components/match/match-timer';
import { MatchTimestamps } from '@/components/match/match-timestamps';
import { MobileChatTrigger } from '@/components/match/mobile-chat-trigger';
import { OpenDisputeButton } from '@/components/match/open-dispute-button';
import { RequestCancellationButton } from '@/components/match/request-cancellation-button';
import { TeamRosters } from '@/components/match/team-rosters';
import { TeamSettlementSummary } from '@/components/match/team-settlement-summary';
import { WaitingForGameCard } from '@/components/match/waiting-for-game-card';
import { useNotificationContext } from '@/components/notifications/notification-provider';
import { BackLink } from '@/components/site/back-link';
import { PageMeta } from '@/components/site/page-meta';
import { useMatchChat } from '@/hooks/use-match-chat';
import { useT } from '@/lib/i18n';
import { isMatchChatReadOnly } from '@/lib/match-chat-readonly';
import { teamLabel } from '@/lib/team-leader';
import { show as listingShow } from '@/routes/listings';
import type { Match, MatchStatus, TeamMatchPlayer } from '@/types';
import type { ChatMessage } from '@/types/match';

const CANCEL_COOLDOWN_MINUTES = 30;

interface TeamMatchViewProps {
    match: Match;
    messages: { data: ChatMessage[] };
}

const STATUS_LABEL: Record<MatchStatus, string> = {
    // LobbyFilling redirects to /listings/{id}; unreachable in practice.
    lobby_filling: 'Lobby filling',
    pending: 'Pending — playing now',
    disputed: 'Disputed — under review',
    settled: 'Settled',
    manual_review: 'Manual review',
    cancelled: 'Cancelled — stakes refunded',
};

const STATUS_TONE: Record<MatchStatus, string> = {
    lobby_filling: 'border-muted-foreground/40 bg-muted text-muted-foreground',
    pending: 'border-warning/40 bg-warning/10 text-warning',
    disputed: 'border-destructive/40 bg-destructive/10 text-destructive',
    settled: 'border-success/40 bg-success/10 text-success',
    manual_review: 'border-muted-foreground/40 bg-muted text-muted-foreground',
    cancelled: 'border-muted-foreground/40 bg-muted text-muted-foreground',
};

/**
 * Team-aware match show page (M34 P6 Slice E). Mirrors the 1v1
 * `match/show.tsx` rhythm — header → status banners → action area →
 * roster → chat — but with team-shaped data: per-team payout math, team
 * rosters, "your team won/lost" framing.
 *
 * Polling + live notification refresh come from the same primitives the
 * 1v1 page uses (8s `router.reload` on Pending, `NotificationProvider`
 * broadcast bridge for match-scoped events).
 */
export function TeamMatchView({ match, messages }: TeamMatchViewProps) {
    const t = useT();
    const { auth } = usePage().props;

    // Wrap default-empty fallback in useMemo so the array identity is
    // stable across renders — without this, `viewerTeam`'s useMemo deps
    // change every render and React-Compiler flags it.
    const teamA: TeamMatchPlayer[] = useMemo(
        () => match.team_a ?? [],
        [match.team_a],
    );
    const teamB: TeamMatchPlayer[] = useMemo(
        () => match.team_b ?? [],
        [match.team_b],
    );
    const teamSize = match.listing.team_size;

    const viewerId = auth.user?.id ?? null;
    const viewerTeam: 'a' | 'b' | null = useMemo(() => {
        if (viewerId === null) {
            return null;
        }

        if (teamA.some((p) => p.user_id === viewerId)) {
            return 'a';
        }

        if (teamB.some((p) => p.user_id === viewerId)) {
            return 'b';
        }

        return null;
    }, [viewerId, teamA, teamB]);

    const chat = useMatchChat(match.id, messages.data, viewerId);
    const chatIsReadOnly = isMatchChatReadOnly(match.status);

    // M16 hand-off — auto-fetched card in chat means settlement is moments
    // away (the polling loop catches Settled on the next tick).
    const hasAutoFetchedCard = useMemo(
        () =>
            chat.messages.some((message) =>
                message.attachments.some(
                    (attachment) =>
                        attachment.type === 'game_card' &&
                        attachment.source === 'auto_fetch',
                ),
            ),
        [chat.messages],
    );

    const [initialStatus] = useState(match.status);
    const settledCardShouldAnimate = initialStatus !== 'settled';

    // Pot math — team payout splits the post-fee pot across the winning
    // side. Floats here are presentation only; the real ledger writes are
    // BCMath in `SettleTeamMatchAction`.
    const pot = match.listing.stake_amount * teamSize * 2;
    const isDraw = match.status === 'settled' && match.winning_team == null;
    const fee = isDraw ? 0 : pot * match.fee_rate;
    const perPlayerPayout = isDraw ? 0 : (pot - fee) / teamSize;
    const perPlayerRefund = match.listing.stake_amount;
    // Always-positive potential payout for the Rosters strip — the strip
    // shows "If you win +$X" pre- and post-settle, so draws (where
    // perPlayerPayout zeroes out) shouldn't collapse the headline number.
    const potentialWinnerPayout = (pot - pot * match.fee_rate) / teamSize;

    // 4h auto-fetch deadline mirrors 1v1 — `ResolveMatchTimeoutAction`
    // flips stuck Pending to ManualReview at this boundary.
    const matchDeadline = match.created_at
        ? new Date(new Date(match.created_at).getTime() + 4 * 60 * 60 * 1000)
        : null;

    // Polling: same 8s tick as 1v1 while Pending so the page picks up
    // auto-fetch settlements that don't emit a chat broadcast.
    useEffect(() => {
        if (match.status !== 'pending') {
            return;
        }

        const id = window.setInterval(() => {
            router.reload({ only: ['match'] });
        }, 8000);

        return () => window.clearInterval(id);
    }, [match.status]);

    // Plain function call, not useMemo — `Date.now()` is impure and
    // React-Compiler flags it inside useMemo (the cached value would
    // never refresh from clock drift). The 8s polling re-renders the
    // page often enough that this stays approximately correct; the
    // existing 1v1 page uses the same pattern.
    const cooldownRemaining = computeCooldownRemaining(match, viewerId);

    const viewerIsParticipant = viewerTeam !== null;
    const showActionButtons =
        match.status === 'pending' &&
        viewerIsParticipant &&
        match.cancellation.requested_at === null;

    return (
        <>
            <TeamMatchLiveUpdater matchId={match.id} />

            <PageMeta
                title={t('Match #:id', { id: match.id })}
                description={t(
                    'Match details and chat. Private to participants.',
                )}
                noindex
            />

            <div className="mx-auto max-w-7xl px-4 py-10 md:px-6 md:py-14">
                {/* Status notifications — full-width, above the back link.
                    Mutually exclusive by status. */}
                {match.status === 'pending' && auth.user && (
                    <CancellationRequestBanner
                        match={match}
                        viewerId={auth.user.id}
                    />
                )}

                {match.status === 'cancelled' && (
                    <div className="mb-6">
                        <CancellationSummary match={match} />
                    </div>
                )}

                <AdminReviewBanner match={match} viewerId={viewerId} />

                <div className="mb-6">
                    <BackLink
                        fallback={
                            listingShow({ listing: match.listing.id }).url
                        }
                    />
                </div>

                <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_460px] lg:gap-6">
                    <div className="min-w-0">
                        <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h1 className="font-display text-3xl font-bold tracking-tight text-foreground">
                                    {t('Match #:id', { id: match.id })}
                                </h1>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {viewerTeam === 'a' &&
                                        t("You're on :team.", {
                                            team: teamLabel(teamA, t('Team A')),
                                        })}
                                    {viewerTeam === 'b' &&
                                        t("You're on :team.", {
                                            team: teamLabel(teamB, t('Team B')),
                                        })}
                                    {viewerTeam === null &&
                                        t('Spectator view.')}
                                </p>
                                <MatchTimestamps
                                    startedAt={match.created_at}
                                    finishedAt={match.settled_at}
                                />
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <GameChip game={match.listing.game} />
                                <span className="inline-flex w-fit shrink-0 items-center rounded-full border border-accent/40 bg-accent/10 px-3 py-1.5 text-xs font-medium text-accent">
                                    {teamSize}v{teamSize}
                                </span>
                                <span
                                    className={`inline-flex w-fit shrink-0 items-center rounded-full border px-3 py-1.5 text-xs font-medium ${STATUS_TONE[match.status]}`}
                                >
                                    {t(STATUS_LABEL[match.status])}
                                </span>
                                {match.status === 'pending' &&
                                    matchDeadline && (
                                        <MatchTimer deadline={matchDeadline} />
                                    )}
                            </div>
                        </div>

                        {match.status === 'pending' && (
                            <div className="mb-6">
                                <WaitingForGameCard
                                    platform={match.listing.platform}
                                    snapshots={match.snapshots}
                                    hasAutoFetchedCard={hasAutoFetchedCard}
                                />

                                {showActionButtons && (
                                    <div className="mt-4 flex flex-col items-center justify-center gap-3 sm:flex-row sm:gap-6">
                                        <RequestCancellationButton
                                            matchId={match.id}
                                            cooldownMinutesRemaining={
                                                cooldownRemaining
                                            }
                                            teamSize={teamSize}
                                        />
                                        <OpenDisputeButton
                                            matchId={match.id}
                                            teamSize={teamSize}
                                        />
                                    </div>
                                )}
                            </div>
                        )}

                        {match.status === 'settled' && (
                            <div className="mb-6">
                                <TeamSettlementSummary
                                    pot={pot}
                                    fee={fee}
                                    perPlayerPayout={perPlayerPayout}
                                    perPlayerRefund={perPlayerRefund}
                                    winningTeam={match.winning_team ?? null}
                                    viewerTeam={viewerTeam}
                                    teamA={teamA}
                                    teamB={teamB}
                                    animateEntrance={settledCardShouldAnimate}
                                />
                            </div>
                        )}

                        <TeamRosters
                            teamA={teamA}
                            teamB={teamB}
                            winningTeam={match.winning_team ?? null}
                            viewerId={viewerId}
                            pot={pot}
                            stakeEach={match.listing.stake_amount}
                            winnerPayout={potentialWinnerPayout}
                            loserLoss={match.listing.stake_amount}
                            feeRate={match.fee_rate}
                            platform={match.listing.platform}
                        />

                        <div className="mt-6">
                            <MatchFaq />
                        </div>
                    </div>

                    {/* Right-rail chat — same dock as 1v1. Only renders for
                        participants (chat channel auth would 403 spectators). */}
                    {viewerIsParticipant && (
                        <aside className="hidden lg:sticky lg:top-28 lg:block lg:h-[750px]">
                            <ChatPanel
                                messages={chat.messages}
                                viewerId={viewerId ?? 0}
                                creator={match.creator}
                                taker={match.taker}
                                isReadOnly={chatIsReadOnly}
                                isPending={chat.isPending}
                                onSend={chat.send}
                                onRetry={chat.retry}
                                onDismiss={chat.dismiss}
                                uploadProgress={chat.uploadProgress}
                            />
                        </aside>
                    )}
                </div>

                {viewerIsParticipant && (
                    <MobileChatTrigger
                        messages={chat.messages}
                        viewerId={viewerId ?? 0}
                        creator={match.creator}
                        taker={match.taker}
                        isReadOnly={chatIsReadOnly}
                        isPending={chat.isPending}
                        onSend={chat.send}
                        onRetry={chat.retry}
                        onDismiss={chat.dismiss}
                        uploadProgress={chat.uploadProgress}
                    />
                )}
            </div>
        </>
    );
}

function computeCooldownRemaining(
    match: Match,
    viewerId: number | null,
): number {
    const { cancellation } = match;

    if (cancellation.requested_by_id !== viewerId) {
        return 0;
    }

    if (cancellation.rejected_at === null) {
        return 0;
    }

    const rejectedAtMs = new Date(cancellation.rejected_at).getTime();
    const cooldownEndMs = rejectedAtMs + CANCEL_COOLDOWN_MINUTES * 60 * 1000;
    const remainingMs = cooldownEndMs - Date.now();

    return remainingMs <= 0 ? 0 : Math.ceil(remainingMs / 60_000);
}

/**
 * Bridges match-scoped notifications to a router.reload(). Duplicates the
 * 1v1 `MatchLiveUpdater` in `pages/match/show.tsx` — extracting both
 * would be cleaner but lives in Slice F polish.
 */
function TeamMatchLiveUpdater({ matchId }: { matchId: number }) {
    const { lastBroadcast } = useNotificationContext();

    useEffect(() => {
        if (lastBroadcast?.related_id !== matchId) {
            return;
        }

        router.reload();
    }, [lastBroadcast, matchId]);

    return null;
}
