import { Coins, Info, ShieldCheck, Skull, Sparkles } from 'lucide-react';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ListingPlatform } from '@/types/listings';

interface MatchDetailsTriggerProps {
    pot: number;
    stakeEach: number;
    /** Per-player payout for the winning side (post-fee). */
    winnerPayout: number;
    /** Per-player loss for the losing side (= stake_amount). */
    loserLoss: number;
    /** Platform fee rate (0..1). Drives the breakdown caption. */
    feeRate: number;
    platform: ListingPlatform;
}

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
    faceit: 'FACEIT',
    steam: 'Steam',
};

/**
 * Compact "Money breakdown" pill that lives in the `TeamRosters` header
 * (right corner). Hover or keyboard focus opens a rich `HoverCard` with
 * Pot · Stake · If-you-win · If-you-lose split into two visual groups
 * (commitments above the divider, outcomes below) plus a compact
 * `Verified via :platform` chip in the card footer — settlement source
 * lives with the rest of the money detail, not in the main page chrome.
 */
export function MatchDetailsTrigger({
    pot,
    stakeEach,
    winnerPayout,
    loserLoss,
    feeRate,
    platform,
}: MatchDetailsTriggerProps) {
    const t = useT();
    const feePercent = Math.round(feeRate * 100);

    return (
        <HoverCard>
            <HoverCardTrigger asChild>
                <button
                    type="button"
                    aria-label={t('Show money breakdown')}
                    className="group inline-flex cursor-pointer items-center gap-2 rounded-full border border-border/60 bg-card/60 px-3 py-1.5 text-xs font-medium text-foreground transition-colors outline-none hover:border-primary/40 hover:bg-primary/10 hover:text-primary focus-visible:border-primary/40 focus-visible:ring-2 focus-visible:ring-primary/25"
                >
                    <Coins
                        className="size-3.5 text-primary"
                        aria-hidden="true"
                    />
                    <span>{t('Money breakdown')}</span>
                    <Info
                        className="size-3 text-muted-foreground transition-colors group-hover:text-primary"
                        aria-hidden="true"
                    />
                </button>
            </HoverCardTrigger>

            <HoverCardContent align="end" side="bottom">
                <BreakdownPanel
                    pot={pot}
                    stakeEach={stakeEach}
                    winnerPayout={winnerPayout}
                    loserLoss={loserLoss}
                    feePercent={feePercent}
                    platform={platform}
                />
            </HoverCardContent>
        </HoverCard>
    );
}

interface BreakdownPanelProps {
    pot: number;
    stakeEach: number;
    winnerPayout: number;
    loserLoss: number;
    feePercent: number;
    platform: ListingPlatform;
}

function BreakdownPanel({
    pot,
    stakeEach,
    winnerPayout,
    loserLoss,
    feePercent,
    platform,
}: BreakdownPanelProps) {
    const t = useT();

    return (
        <div className="flex flex-col gap-4">
            <header className="flex items-center justify-between gap-3">
                <h3 className="font-display text-sm font-semibold tracking-wide text-foreground">
                    {t('Money breakdown')}
                </h3>
                <span className="rounded-full border border-border/60 bg-card/80 px-2 py-0.5 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                    {t(':percent% fee', { percent: feePercent })}
                </span>
            </header>

            <dl className="flex flex-col gap-3">
                <BreakdownRow
                    label={t('Pot')}
                    value={`$${pot}`}
                    helper={t('Total stakes from every player')}
                />
                <BreakdownRow
                    label={t('Stake')}
                    value={`$${stakeEach}`}
                    helper={t('What each player committed at Ready up')}
                />
            </dl>

            <div className="h-px bg-border/40" />

            <dl className="flex flex-col gap-3">
                <OutcomeRow
                    icon={Sparkles}
                    label={t('If you win')}
                    value={`+$${winnerPayout.toFixed(2)}`}
                    tone="win"
                    helper={t('Your share of the pot, after the platform fee')}
                />
                <OutcomeRow
                    icon={Skull}
                    label={t('If you lose')}
                    value={`−$${loserLoss}`}
                    tone="lose"
                    helper={t('Your stake goes to the winning team')}
                />
            </dl>

            <div className="-mx-5 mt-1 -mb-5 flex items-center justify-center gap-1.5 rounded-b-xl border-t border-success/20 bg-success/5 px-5 py-2.5 text-[11px] font-medium text-success">
                <ShieldCheck className="size-3" aria-hidden="true" />
                {t('Verified via :platform', {
                    platform: PLATFORM_LABEL[platform],
                })}
            </div>
        </div>
    );
}

interface BreakdownRowProps {
    label: string;
    value: string;
    helper: string;
}

function BreakdownRow({ label, value, helper }: BreakdownRowProps) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <div className="min-w-0">
                <dt className="text-xs font-medium text-foreground">{label}</dt>
                <p className="text-[11px] text-muted-foreground">{helper}</p>
            </div>
            <dd className="shrink-0 font-display text-base font-bold text-foreground tabular-nums">
                {value}
            </dd>
        </div>
    );
}

interface OutcomeRowProps {
    icon: typeof Sparkles;
    label: string;
    value: string;
    helper: string;
    tone: 'win' | 'lose';
}

function OutcomeRow({
    icon: Icon,
    label,
    value,
    helper,
    tone,
}: OutcomeRowProps) {
    const accentBg =
        tone === 'win'
            ? 'bg-success/10 text-success'
            : 'bg-destructive/10 text-destructive';
    const valueClass =
        tone === 'win'
            ? 'text-gradient-primary font-display text-lg font-bold'
            : 'font-display text-lg font-bold text-destructive';

    return (
        <div className="flex items-baseline justify-between gap-3">
            <div className="flex min-w-0 items-start gap-2.5">
                <span
                    className={cn(
                        'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full',
                        accentBg,
                    )}
                >
                    <Icon className="size-3" aria-hidden="true" />
                </span>
                <div className="min-w-0">
                    <dt className="text-xs font-medium text-foreground">
                        {label}
                    </dt>
                    <p className="text-[11px] text-muted-foreground">
                        {helper}
                    </p>
                </div>
            </div>
            <dd className={cn(valueClass, 'shrink-0 tabular-nums')}>{value}</dd>
        </div>
    );
}
