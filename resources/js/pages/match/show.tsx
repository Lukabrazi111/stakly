import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { CancellationRequestBanner } from '@/components/match/cancellation-request-banner';
import { CancellationSummary } from '@/components/match/cancellation-summary';
import { ChatPanel } from '@/components/match/chat-panel';
import { MatchFaq } from '@/components/match/match-faq';
import { MatchInfoCard } from '@/components/match/match-info-card';
import { MatchTimer } from '@/components/match/match-timer';
import { MatchTimestamps } from '@/components/match/match-timestamps';
import { MobileChatTrigger } from '@/components/match/mobile-chat-trigger';
import { OpenDisputeButton } from '@/components/match/open-dispute-button';
import { RequestCancellationButton } from '@/components/match/request-cancellation-button';
import { SettlementSummary } from '@/components/match/settlement-summary';
import { WaitingForGameCard } from '@/components/match/waiting-for-game-card';
import { BackLink } from '@/components/site/back-link';
import { useMatchChat } from '@/hooks/use-match-chat';
import SiteLayout from '@/layouts/site-layout';
import { show as listingShow } from '@/routes/listings';
import type { MatchShowProps, MatchStatus } from '@/types';

const CANCEL_COOLDOWN_MINUTES = 30;

/**
 * Returns the minutes remaining on the viewer's per-user cancellation
 * cooldown (0 if not in cooldown). Cooldown engages when the viewer was
 * the requester on a previously rejected request AND the 30-min window
 * since `rejected_at` hasn't elapsed. Mirrors `GameMatchPolicy::
 * requestCancellation`'s cooldown gate so the disabled button matches
 * the server's decision.
 */
function cooldownRemainingFor(
    match: import('@/types').Match,
    viewerId: number,
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
    if (remainingMs <= 0) {
        return 0;
    }
    return Math.ceil(remainingMs / 60_000);
}

const STATUS_LABEL: Record<MatchStatus, string> = {
    pending: 'Pending — waiting for game',
    disputed: 'Disputed — under review',
    settled: 'Settled',
    manual_review: 'Manual review',
    cancelled: 'Cancelled — stakes refunded',
};

const STATUS_TONE: Record<MatchStatus, string> = {
    pending: 'border-warning/40 bg-warning/10 text-warning',
    disputed: 'border-destructive/40 bg-destructive/10 text-destructive',
    settled: 'border-success/40 bg-success/10 text-success',
    manual_review: 'border-muted-foreground/40 bg-muted text-muted-foreground',
    cancelled: 'border-muted-foreground/40 bg-muted text-muted-foreground',
};

export default function MatchShow({ match, messages }: MatchShowProps) {
    const { auth } = usePage().props;

    // Chat state lives in one hook so a single Echo subscription serves both
    // the desktop right-rail and the mobile bottom-sheet renders below. The
    // viewer is one of the two participants; the hook trusts that (the
    // backend rejects non-participants from both the POST endpoint and the
    // channel auth callback). viewerId feeds the hook's optimistic-UI
    // injection so the sender sees their own bubble immediately.
    const chat = useMatchChat(match.id, messages.data, auth.user?.id ?? null);
    const chatIsReadOnly =
        match.status === 'settled' ||
        match.status === 'manual_review' ||
        match.status === 'cancelled';

    const isCreator = auth.user?.id === match.creator.id;
    const opponent = isCreator ? match.taker : match.creator;
    const youAre = isCreator ? 'Listing creator' : 'Taker';

    // M16 — has an auto-fetched card landed in chat yet? Drives the
    // Pending action card's "found, settling…" hand-off state. The next
    // poll tick catches `status === 'settled'` and unmounts the whole
    // card in favor of `SettlementSummary`.
    const hasAutoFetchedCard = useMemo(
        () =>
            chat.messages.some((message) =>
                message.attachments.some(
                    (attachment) =>
                        attachment.type === 'game_card'
                        && attachment.source === 'auto_fetch',
                ),
            ),
        [chat.messages],
    );

    // Inertia partial reload returns a fresh `match` object reference on
    // every poll tick. The WaitingForGameCard's "Last checked Ns ago"
    // counter resets each time this bumps. Bumping is the signal that
    // the backend's page-visit auto-fetch trigger just fired.
    const [pollTick, setPollTick] = useState(0);
    useEffect(() => {
        setPollTick((n) => n + 1);
    }, [match]);

    // A Settled match with no winner is a draw — both stakes were refunded
    // via `SettleDrawMatchAction`, no platform fee charged. Backend
    // contract: `winner === null && status === 'settled'` ⇒ draw.
    const isDraw = match.status === 'settled' && match.winner === null;
    const pot = match.listing.stake_amount * 2;
    const fee = isDraw ? 0 : pot * match.fee_rate;
    // For draws, the "payout" stat is each player's refund (their original
    // stake). For wins, it's pot minus platform fee.
    const winnerPayout = isDraw ? match.listing.stake_amount : pot - fee;

    // 4-hour confirmation window from match creation. Backend Phase 7 will
    // enforce this with a scheduled job; the timer here is the player-facing
    // countdown so they know how long they have.
    const matchDeadline = match.created_at
        ? new Date(new Date(match.created_at).getTime() + 4 * 60 * 60 * 1000)
        : null;

    // Polling: refresh the match resource every 8s while Pending so the
    // "waiting for opponent" view updates without manual refresh. Stops
    // automatically when status flips to a terminal state. `only: ['match']`
    // is a partial reload — no navigation, scroll position is preserved
    // implicitly (Inertia v3 dropped the explicit `preserveScroll` option
    // for reload calls). WebSocket layer (Reverb / Pusher) deferred to M10.
    useEffect(() => {
        if (match.status !== 'pending') {
            return;
        }

        const id = window.setInterval(() => {
            router.reload({ only: ['match'] });
        }, 8000);

        return () => window.clearInterval(id);
    }, [match.status]);

    return (
        <SiteLayout>
            <Head title={`Match #${match.id}`} />

            <div className="mx-auto max-w-7xl px-4 py-10 md:px-6 md:py-14">
                {/* ─── Status notifications — full-width, above
                    Back + Match header. At any given time at most ONE
                    of these renders; mutually exclusive by status.
                    Promoted to the top so a player landing on the page
                    sees the most critical state-change first without
                    scrolling past header / chips / action card. ─── */}
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

                {(match.status === 'disputed' ||
                    match.status === 'manual_review') && (
                    <section className="mb-6 rounded-2xl border border-destructive/40 bg-destructive/5 p-6">
                        <h2 className="mb-2 font-display text-lg font-semibold text-foreground">
                            {match.status === 'disputed'
                                ? 'Resolving via game API'
                                : 'Manual review pending'}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {match.status === 'disputed'
                                ? 'This match is being resolved via the official game API. It usually completes in seconds — refresh the page if it doesn’t update shortly.'
                                : 'The game API could not determine a winner. An admin will review this match manually. Your stake stays in escrow until then.'}
                        </p>
                    </section>
                )}

                <div className="mb-6">
                    <BackLink fallback={listingShow(match.listing.id).url} />
                </div>

                <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_460px] lg:gap-6">
                    <div className="min-w-0">

                {/* Stack on mobile, row on sm: so neither truncates at 375px */}
                <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="font-display text-3xl font-bold tracking-tight text-foreground">
                            Match #{match.id}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            You are the {youAre.toLowerCase()}.
                        </p>
                        <MatchTimestamps
                            startedAt={match.created_at}
                            finishedAt={match.settled_at}
                        />
                    </div>
                    {/* Status + countdown live together on the right side
                        of the header. Wraps to a new line on narrow widths
                        so neither chip truncates. */}
                    <div className="flex flex-wrap items-center gap-2">
                        <span
                            className={`inline-flex w-fit shrink-0 items-center rounded-full border px-3 py-1.5 text-xs font-medium ${STATUS_TONE[match.status]}`}
                        >
                            {STATUS_LABEL[match.status]}
                        </span>
                        {match.status === 'pending' && matchDeadline && (
                            <MatchTimer deadline={matchDeadline} />
                        )}
                    </div>
                </div>

                {/* Action area — varies by status. For Pending we render
                    the WaitingForGameCard (M16 — no buttons; the auto-
                    fetch / SettleFromCard pipeline does the work) +
                    escape-hatch links (cancel, dispute). For Settled the
                    SettlementSummary (pot / fee / payout breakdown). The
                    Cancelled / Disputed / ManualReview states render
                    nothing here — their status notification is the full-
                    width banner at the top of the page, and the Match
                    info card below covers the historical details. */}
                {match.status === 'pending' && auth.user && (
                    <div className="mb-6">
                        <WaitingForGameCard
                            platform={match.listing.platform}
                            snapshots={match.snapshots}
                            hasAutoFetchedCard={hasAutoFetchedCard}
                            pollTick={pollTick}
                        />

                        {/* Escape hatches — Request cancellation
                            (mutual no-fault) + Report a problem
                            (one-sided escalation). Hidden when a
                            cancellation request is already open so we
                            don't show "Request cancellation" while a
                            request is in flight; the top banner carries
                            the relevant actions. */}
                        {match.cancellation.requested_at === null && (
                            <div className="mt-4 flex flex-col items-center justify-center gap-3 sm:flex-row sm:gap-6">
                                <RequestCancellationButton
                                    matchId={match.id}
                                    cooldownMinutesRemaining={cooldownRemainingFor(
                                        match,
                                        auth.user.id,
                                    )}
                                />
                                <OpenDisputeButton matchId={match.id} />
                            </div>
                        )}
                    </div>
                )}

                {match.status === 'settled' && (
                    <div className="mb-6">
                        <SettlementSummary
                            winner={match.winner}
                            pot={pot}
                            fee={fee}
                            payout={winnerPayout}
                            iAmWinner={
                                !isDraw && auth.user?.id === match.winner?.id
                            }
                        />
                    </div>
                )}

                {/* Compact match-info card: opponent + parameters in one
                    Bybit-style key:value list. Pot is always shown so
                    players don't have to mentally compute stake × 2.
                    Winner payout is shown only during gameplay (Pending /
                    Disputed / ManualReview) — Settled matches already
                    break down pot/fee/payout in the SettlementSummary
                    card, so repeating winner payout here would triple-up. */}
                <MatchInfoCard
                    opponent={opponent}
                    stakeEach={match.listing.stake_amount}
                    pot={pot}
                    winnerPayout={
                        match.status !== 'settled' ? winnerPayout : undefined
                    }
                    timeControl={match.listing.time_control}
                    platform={match.listing.platform}
                />

                <div className="mt-6">
                    <MatchFaq />
                </div>
                    </div>

                    {/* Desktop right-rail chat. Sticky at top-28 (112px) so
                        the panel docks immediately below the marquee strip
                        (which is sticky at top-16, ~46px tall, ending around
                        110px). Using top-24 like before would tuck the chat
                        UNDER the marquee's z-40 band, causing the marquee
                        text to overlap the chat header on scroll. Fixed
                        600px height keeps the panel compact rather than
                        dominating viewport; internal scroll handles message
                        overflow. */}
                    {auth.user && (
                        <aside className="hidden lg:sticky lg:top-28 lg:block lg:h-[750px]">
                            <ChatPanel
                                messages={chat.messages}
                                viewerId={auth.user.id}
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

                {/* Mobile floating "Chat" button + bottom-sheet drawer. */}
                {auth.user && (
                    <MobileChatTrigger
                        messages={chat.messages}
                        viewerId={auth.user.id}
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
        </SiteLayout>
    );
}
