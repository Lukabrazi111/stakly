import { Link } from '@inertiajs/react';
import { ChevronRight, ShieldCheck } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { formatTimeControls } from '@/lib/listings-format';
import { show as userShow } from '@/routes/users';
import type { ListingPlatform, MatchPlayer, TimeControl } from '@/types';

interface MatchInfoCardProps {
    opponent: MatchPlayer;
    stakeEach: number;
    pot: number;
    timeControl: TimeControl[];
    platform: ListingPlatform;
    /** Pre-computed `pot * (1 - fee_rate)`. Undefined when Settled — the
     *  SettlementSummary card owns the breakdown for resolved matches. */
    winnerPayout?: number;
}

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    lichess: 'Lichess',
    chess_com: 'chess.com',
    // M15 placeholders — only visible on dev-seeded CS2/Dota matches.
    faceit: 'FACEIT',
    steam: 'Steam',
};

/** Compact match-parameters card with key:value rows. */
export function MatchInfoCard({
    opponent,
    stakeEach,
    pot,
    timeControl,
    platform,
    winnerPayout,
}: MatchInfoCardProps) {
    const t = useT();
    const getInitials = useInitials();

    return (
        <section className="rounded-2xl border border-border/60 bg-card/60">
            <header className="border-b border-border/60 px-6 py-4">
                <h2 className="text-sm font-semibold text-foreground">
                    {t('Match info')}
                </h2>
            </header>
            <dl className="divide-y divide-border/60">
                <Link
                    href={userShow({ user: opponent.username }).url}
                    className="group flex items-center justify-between gap-3 px-6 py-4 transition-colors hover:bg-primary/5 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                >
                    <dt className="text-sm text-muted-foreground">
                        {t('Opponent')}
                    </dt>
                    <dd className="flex items-center gap-2.5">
                        <Avatar className="size-7">
                            <AvatarImage
                                src={opponent.avatar_thumb_url ?? undefined}
                                alt={opponent.name}
                            />
                            <AvatarFallback className="bg-gradient-primary text-[10px] font-semibold text-primary-foreground">
                                {getInitials(opponent.name)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="text-sm font-medium text-foreground">
                            {opponent.name}
                        </span>
                        <ChevronRight className="size-4 text-muted-foreground transition-colors group-hover:text-primary" />
                    </dd>
                </Link>

                <Row label={t('Stake (each)')} value={`$${stakeEach}`} />
                <Row label={t('Pot')} value={`$${pot}`} />
                {winnerPayout !== undefined && (
                    <Row
                        label={t('Winner payout')}
                        value={`$${winnerPayout.toFixed(2)}`}
                        accent
                    />
                )}
                <Row
                    label={t('Time control')}
                    value={formatTimeControls(timeControl, t)}
                />

                <div className="flex items-center justify-between gap-3 px-6 py-4">
                    <dt className="text-sm text-muted-foreground">
                        {t('Verification')}
                    </dt>
                    <dd className="inline-flex items-center gap-1.5 text-sm font-medium text-foreground">
                        <ShieldCheck
                            className="size-4 text-success"
                            strokeWidth={2}
                            aria-hidden="true"
                        />
                        {t('Auto via :platform', {
                            platform: PLATFORM_LABEL[platform],
                        })}
                    </dd>
                </div>
            </dl>
        </section>
    );
}

function Row({
    label,
    value,
    accent,
}: {
    label: string;
    value: string;
    accent?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={
                    accent
                        ? 'text-gradient-primary text-sm font-semibold'
                        : 'text-sm font-medium text-foreground'
                }
            >
                {value}
            </dd>
        </div>
    );
}
