import { Head, Link, router, usePage } from '@inertiajs/react';
import { Clock, Coins, Trophy } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { ConfirmButtons } from '@/components/match/confirm-buttons';
import { MatchTimer } from '@/components/match/match-timer';
import { OpenDisputeButton } from '@/components/match/open-dispute-button';
import { SettlementSummary } from '@/components/match/settlement-summary';
import { BackLink } from '@/components/site/back-link';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import SiteLayout from '@/layouts/site-layout';
import { show as listingShow } from '@/routes/listings';
import { show as userShow } from '@/routes/users';
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

export default function MatchShow({ match }: MatchShowProps) {
    const getInitials = useInitials();
    const { auth } = usePage().props;

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

            <div className="mx-auto max-w-3xl px-4 py-10 md:px-6 md:py-14">
                <div className="mb-6">
                    <BackLink fallback={listingShow(match.listing.id).url} />
                </div>

                {/* Stack on mobile, row on sm: so neither truncates at 375px */}
                <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="font-display text-3xl font-bold tracking-tight text-foreground">
                            Match #{match.id}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            You are the {youAre.toLowerCase()}.
                        </p>
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
                        {/* Escape hatch — only shown after the player has
                            made their own claim. Disputing without claiming
                            first is structurally weird and would clutter the
                            initial decision. */}
                        {myConfirmedOutcome !== null && (
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

                {/* Opponent card */}
                <section className="mb-6 rounded-2xl border border-border/60 bg-card/60 p-6">
                    <h2 className="mb-4 text-lg font-semibold text-foreground">
                        Your opponent
                    </h2>
                    <Link
                        href={userShow(opponent.username).url}
                        className="-mx-2 flex items-center gap-4 rounded-lg p-2 transition-colors hover:bg-primary/5 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <Avatar className="size-16">
                            <AvatarFallback className="bg-gradient-primary text-xl font-semibold text-primary-foreground">
                                {getInitials(opponent.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <div className="font-semibold text-foreground">
                                {opponent.name}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                @{opponent.username}
                            </div>
                        </div>
                    </Link>
                </section>

                {/* Match details — for reference */}
                <section className="rounded-2xl border border-border/60 bg-card/60 p-6">
                    <h2 className="mb-5 text-lg font-semibold text-foreground">
                        Match details
                    </h2>
                    <dl className="grid gap-5 sm:grid-cols-3">
                        <Stat
                            icon={<Coins className="size-3.5" />}
                            label="Stake (each)"
                            value={`$${match.listing.stake_amount}`}
                        />
                        <Stat
                            icon={<Trophy className="size-3.5" />}
                            label="Pot"
                            value={`$${pot}`}
                            accent
                        />
                        <Stat
                            icon={<Clock className="size-3.5" />}
                            label="Time control"
                            value={match.listing.time_control.join(', ')}
                        />
                    </dl>
                </section>

                {match.status === 'pending' && (
                    <p className="mt-8 text-center text-xs text-muted-foreground">
                        Play your game on chess.com or Lichess, then return here
                        and confirm the outcome.
                    </p>
                )}
            </div>
        </SiteLayout>
    );
}

interface StatProps {
    icon: ReactNode;
    label: string;
    value: string;
    accent?: boolean;
}

function Stat({ icon, label, value, accent }: StatProps): ReactNode {
    return (
        <div>
            <dt className="inline-flex items-center gap-1.5 text-xs tracking-wide text-muted-foreground uppercase">
                {icon}
                {label}
            </dt>
            <dd
                className={`mt-1.5 font-display text-xl font-bold ${accent ? 'text-gradient-primary' : 'text-foreground'}`}
            >
                {value}
            </dd>
        </div>
    );
}
