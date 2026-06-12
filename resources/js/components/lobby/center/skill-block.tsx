import { Trophy } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LobbyAggregates, LobbyTeamSkill } from '@/types';

interface Props {
    skill: LobbyAggregates['skill'];
}

const DELTA_TONE_CLASS: Record<
    NonNullable<LobbyAggregates['skill']['delta_tone']>,
    { border: string; bg: string; text: string; label: string }
> = {
    even: {
        border: 'border-success/40',
        bg: 'bg-success/10',
        text: 'text-success',
        label: 'Even',
    },
    mismatched: {
        border: 'border-warning/40',
        bg: 'bg-warning/10',
        text: 'text-warning',
        label: 'Mismatched',
    },
    stacked: {
        border: 'border-destructive/40',
        bg: 'bg-destructive/10',
        text: 'text-destructive',
        label: 'Stacked',
    },
};

/**
 * Skill matchup block. Avg ELO per side with min/max + a delta chip in
 * the middle. The chip tone tiers (≤50 even, ≤150 mismatched, >150 stacked)
 * are decided server-side in `LobbyResource::presentAggregates`, so the
 * frontend doesn't re-derive.
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
                <DeltaChip delta={skill.delta} tone={skill.delta_tone} t={t} />
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

interface DeltaChipProps {
    delta: number | null;
    tone: LobbyAggregates['skill']['delta_tone'];
    t: ReturnType<typeof useT>;
}

function DeltaChip({ delta, tone, t }: DeltaChipProps) {
    if (delta === null || tone === null) {
        return (
            <span
                className="inline-flex items-center rounded-full border border-border/60 bg-card/80 px-2.5 py-1 text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                aria-hidden="true"
            >
                {t('VS')}
            </span>
        );
    }

    const c = DELTA_TONE_CLASS[tone];

    return (
        <div
            className={cn(
                'flex flex-col items-center rounded-xl border px-3 py-2',
                c.border,
                c.bg,
            )}
        >
            <span
                className={cn(
                    'font-display text-base leading-none font-bold tabular-nums',
                    c.text,
                )}
            >
                {delta}
            </span>
            <span
                className={cn(
                    'mt-1 text-[9px] font-semibold tracking-wider uppercase',
                    c.text,
                )}
            >
                {t(c.label)}
            </span>
        </div>
    );
}
