import { Head, Link, usePage } from '@inertiajs/react';
import { Clock, Coins, Trophy } from 'lucide-react';
import type { ReactNode } from 'react';
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
    const pot = match.listing.stake_amount * 2;

    return (
        <SiteLayout>
            <Head title={`Match #${match.id}`} />

            <div className="mx-auto max-w-3xl px-4 py-10 md:px-6 md:py-14">
                <Link
                    href={listingShow(match.listing.id).url}
                    className="text-muted-foreground hover:text-foreground mb-6 inline-flex items-center gap-1.5 text-sm transition-colors"
                >
                    ← Back to listing
                </Link>

                {/* Stack on mobile (status pill below title) so neither
                    truncates at narrow viewports — flex-row from sm: up. */}
                <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="font-display text-foreground text-3xl font-bold tracking-tight">
                            Match #{match.id}
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            You are the {youAre.toLowerCase()}.
                        </p>
                    </div>
                    <span
                        className={`inline-flex w-fit shrink-0 items-center rounded-full border px-3 py-1.5 text-xs font-medium ${STATUS_TONE[match.status]}`}
                    >
                        {STATUS_LABEL[match.status]}
                    </span>
                </div>

                <section className="border-border/60 bg-card/60 mb-6 rounded-2xl border p-6">
                    <h2 className="text-foreground mb-4 text-lg font-semibold">
                        Your opponent
                    </h2>
                    <Link
                        href={userShow(opponent.username).url}
                        className="focus-visible:ring-primary focus-visible:ring-offset-background hover:bg-primary/5 -mx-2 flex items-center gap-4 rounded-lg p-2 transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <Avatar className="size-16">
                            <AvatarFallback className="bg-gradient-primary text-primary-foreground text-xl font-semibold">
                                {getInitials(opponent.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <div className="text-foreground font-semibold">
                                {opponent.name}
                            </div>
                            <div className="text-muted-foreground text-sm">
                                @{opponent.username}
                            </div>
                        </div>
                    </Link>
                </section>

                <section className="border-border/60 bg-card/60 rounded-2xl border p-6">
                    <h2 className="text-foreground mb-5 text-lg font-semibold">
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

                <p className="text-muted-foreground mt-8 text-center text-xs">
                    Both stakes are safely escrowed. Play your game on chess.com or
                    Lichess, then come back here — confirmation buttons land soon.
                </p>
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
            <dt className="text-muted-foreground inline-flex items-center gap-1.5 text-xs uppercase tracking-wide">
                {icon}
                {label}
            </dt>
            <dd
                className={`font-display mt-1.5 text-xl font-bold ${accent ? 'text-gradient-primary' : 'text-foreground'}`}
            >
                {value}
            </dd>
        </div>
    );
}
