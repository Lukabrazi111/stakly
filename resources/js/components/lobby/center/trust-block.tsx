import { ShieldCheck } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LobbyAggregates, LobbyTeamTrust } from '@/types';

interface Props {
    trust: LobbyAggregates['trust'];
}

/**
 * Trust signals block. Per-team avg 30-day completion rate + total lifetime
 * settled matches. Mirrors the listing-row trust meta but aggregated across
 * each team's roster. Card border tints warning when the team has a player
 * below 70% completion — proxied here by checking the team avg falling under
 * 70 (cheap, no per-player payload).
 */
export function TrustBlock({ trust }: Props) {
    const t = useT();

    return (
        <section className="rounded-2xl border border-border/60 bg-card/60 p-5">
            <header className="mb-4 flex items-center justify-center gap-1.5">
                <ShieldCheck
                    className="size-3.5 text-muted-foreground"
                    aria-hidden="true"
                />
                <h3 className="text-[10px] font-medium tracking-[0.18em] text-muted-foreground uppercase">
                    {t('Trust signals')}
                </h3>
            </header>

            <div className="grid grid-cols-2 gap-3">
                <TeamTrustCard label={t('Team A')} trust={trust.a} />
                <TeamTrustCard label={t('Team B')} trust={trust.b} />
            </div>
        </section>
    );
}

interface TeamTrustCardProps {
    label: string;
    trust: LobbyTeamTrust;
}

function TeamTrustCard({ label, trust }: TeamTrustCardProps) {
    const t = useT();

    const isLowTrust =
        trust.avg_completion_rate !== null && trust.avg_completion_rate < 70;

    return (
        <div
            className={cn(
                'rounded-xl border bg-card/40 p-3 text-center',
                isLowTrust ? 'border-warning/40' : 'border-border/60',
            )}
        >
            <div className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                {label}
            </div>
            <div
                className={cn(
                    'mt-1.5 font-display text-xl leading-none font-bold tabular-nums',
                    isLowTrust ? 'text-warning' : 'text-foreground',
                )}
            >
                {trust.avg_completion_rate !== null
                    ? `${trust.avg_completion_rate}%`
                    : '—'}
            </div>
            <div className="mt-1 text-[10px] tracking-wide text-muted-foreground/80 uppercase">
                {t('Avg completion')}
            </div>
            <div className="mt-2 border-t border-border/40 pt-2 text-[11px] text-muted-foreground tabular-nums">
                {t(':n settled', { n: trust.settled_lifetime_sum })}
            </div>
        </div>
    );
}
