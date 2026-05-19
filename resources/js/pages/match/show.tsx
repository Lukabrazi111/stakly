import { Head, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { ChatPanel } from '@/components/match/chat-panel';
import { ConfirmButtons } from '@/components/match/confirm-buttons';
import { MatchFaq } from '@/components/match/match-faq';
import { MatchInfoCard } from '@/components/match/match-info-card';
import { MatchTimer } from '@/components/match/match-timer';
import { MatchTimestamps } from '@/components/match/match-timestamps';
import { MobileChatTrigger } from '@/components/match/mobile-chat-trigger';
import { OpenDisputeButton } from '@/components/match/open-dispute-button';
import { SettlementSummary } from '@/components/match/settlement-summary';
import { BackLink } from '@/components/site/back-link';
import { useMatchChat } from '@/hooks/use-match-chat';
import SiteLayout from '@/layouts/site-layout';
import { show as listingShow } from '@/routes/listings';
import type { MatchShowProps, MatchStatus } from '@/types';

const STATUS_LABEL: Record<MatchStatus, string> = {
    pending: 'Pending — confirm outcome',
    disputed: 'Disputed — under review',
    settled: 'Settled',
    manual_review: 'Manual review',
};

const STATUS_TONE: Record<MatchStatus, string> = {
    pending: 'border-warning/40 bg-warning/10 text-warning',
    disputed: 'border-destructive/40 bg-destructive/10 text-destructive',
    settled: 'border-success/40 bg-success/10 text-success',
    manual_review: 'border-muted-foreground/40 bg-muted text-muted-foreground',
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
        match.status === 'settled' || match.status === 'manual_review';

    const isCreator = auth.user?.id === match.creator.id;
    const opponent = isCreator ? match.taker : match.creator;
    const youAre = isCreator ? 'Listing creator' : 'Taker';

    const myConfirmedOutcome = isCreator
        ? match.creator_confirmed_outcome
        : match.taker_confirmed_outcome;
    const opponentConfirmedOutcome = isCreator
        ? match.taker_confirmed_outcome
        : match.creator_confirmed_outcome;

    // A Settled match with no winner is a draw — both stakes were refunded
    // via `MatchSettlement::settleDraw`, no platform fee charged. Backend
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

            <div className="mx-auto max-w-6xl px-4 py-10 md:px-6 md:py-14">
                <div className="mb-6">
                    <BackLink fallback={listingShow(match.listing.id).url} />
                </div>

                <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-6">
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

                {/* Action area — varies by status. Confirm UI / settlement
                    summary / dispute banner take the prominent slot. */}
                {match.status === 'pending' && (
                    <section className="mb-6 rounded-2xl border border-border/60 bg-card/60 p-6">
                        <h2 className="mb-4 text-lg font-semibold text-foreground">
                            Confirm outcome
                        </h2>
                        <ConfirmButtons
                            matchId={match.id}
                            myConfirmedOutcome={myConfirmedOutcome}
                            opponentConfirmedOutcome={opponentConfirmedOutcome}
                        />
                        {/* Escape hatch — visible once EITHER player has
                            claimed something. Originally this was gated on
                            the viewer having claimed first ("structurally
                            weird to dispute without your own claim"), but
                            that left the viewer trapped if the opponent
                            lied first: their only paths were to mirror the
                            lie (settles to liar) or claim Draw / mirror back
                            and indirectly trigger dispute. With the wider
                            gate, the viewer can challenge a bad-faith claim
                            directly. While neither player has claimed yet,
                            the button stays hidden — nothing to dispute. */}
                        {(myConfirmedOutcome !== null ||
                            opponentConfirmedOutcome !== null) && (
                            <div className="mt-5 flex justify-center border-t border-border/60 pt-5">
                                <OpenDisputeButton matchId={match.id} />
                            </div>
                        )}
                    </section>
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
                />

                <div className="mt-6">
                    <MatchFaq />
                </div>

                {match.status === 'pending' && (
                    <p className="mt-8 text-center text-xs text-muted-foreground">
                        Play your game on chess.com or Lichess, then return here
                        and confirm the outcome.
                    </p>
                )}
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
                        <aside className="hidden lg:sticky lg:top-28 lg:block lg:h-[600px]">
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
