import { Trophy } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LobbyAggregates, LobbyTeamSkill } from '@/types';

interface Props {
    skill: LobbyAggregates['skill'];
}

/**
 * Skill matchup block. Avg ELO per side with min/max + a neutral `VS` label
 * in the middle. Earlier iterations carried a colored delta tier chip
 * (Even / Mismatched / Stacked); dropped per design feedback in favor of a
 * cleaner separator. Backend still computes `delta` / `delta_tone` for
 * potential future re-introduction.
 */
export function SkillBlock({ skill }: Props) {
    const t = useT();

    return (
        <section className="rounded-2xl border border-border/60 bg-card/60 p-5">
            <header className="mb-4 flex items-center justify-center gap-1.5">
                <Trophy
                    className="size-3.5 text-muted-foreground"
                    aria-hidden="true"
                />
                <h3 className="text-[10px] font-medium tracking-[0.18em] text-muted-foreground uppercase">
                    {t('Skill matchup')}
                </h3>
            </header>

            <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                <TeamSkillCard label={t('Team A')} skill={skill.a} alignRight />
                <VsLabel />
                <TeamSkillCard label={t('Team B')} skill={skill.b} />
            </div>
        </section>
    );
}

interface TeamSkillCardProps {
    label: string;
    skill: LobbyTeamSkill;
    alignRight?: boolean;
}

function TeamSkillCard({ label, skill, alignRight }: TeamSkillCardProps) {
    const t = useT();

    return (
        <div className={cn('min-w-0', alignRight && 'text-right')}>
            <div className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                {label}
            </div>
            <div className="mt-1 font-display text-2xl leading-none font-bold text-foreground tabular-nums">
                {skill.avg ?? '—'}
            </div>
            <div className="mt-1.5 text-[11px] text-muted-foreground tabular-nums">
                {skill.min !== null && skill.max !== null
                    ? `${skill.min} – ${skill.max}`
                    : t('No ratings')}
            </div>
            <div className="mt-0.5 text-[10px] text-muted-foreground/70">
                {t(':count :players', {
                    count: skill.count,
                    players: skill.count === 1 ? t('player') : t('players'),
                })}
            </div>
        </div>
    );
}

function VsLabel() {
    const t = useT();

    return (
        <span
            className="inline-flex items-center rounded-full border border-border/60 bg-card/80 px-2.5 py-1 text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
            aria-hidden="true"
        >
            {t('vs')}
        </span>
    );
}
