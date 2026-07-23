import { Coins, Info, ShieldCheck, Skull, Sparkles } from 'lucide-react';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useHasHover } from '@/hooks/use-has-hover';
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
 * Compact "Money breakdown" pill in the `TeamRosters` header (right corner).
 * Opens a panel with Pot · Stake · If-you-win · If-you-lose plus a
 * `Verified via :platform` footer.
 *
 * Device-aware so it feels native everywhere: hover-capable devices (desktop
 * mouse) get a `HoverCard` that opens on hover/focus; touch devices get a
 * `Popover` that opens on tap. A bare HoverCard is hover-only — it never
 * opened on a mobile tap and preventDefault'd `touchstart` in React's passive
 * listener; a bare Popover loses desktop hover. Branching gives both.
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
    const hasHover = useHasHover();
    const feePercent = Math.round(feeRate * 100);

    const trigger = (
        <button
            type="button"
            aria-label={t('Show money breakdown')}
            className="inline-flex cursor-pointer items-center gap-2 rounded-full border border-border/60 bg-card/60 px-3 py-1.5 text-xs font-medium text-foreground transition-colors outline-none hover:bg-primary/10 focus-visible:border-primary/40 focus-visible:ring-2 focus-visible:ring-primary/25"
        >
            <Coins className="size-3.5 text-primary" aria-hidden="true" />
            <span>{t('Money breakdown')}</span>
            <Info className="size-3 text-muted-foreground" aria-hidden="true" />
        </button>
    );

    const panel = (
        <BreakdownPanel
            pot={pot}
            stakeEach={stakeEach}
            winnerPayout={winnerPayout}
            loserLoss={loserLoss}
            feePercent={feePercent}
            platform={platform}
        />
    );

    // Desktop (hover-capable) → HoverCard opens on hover/focus. Touch → Popover
    // opens on tap (HoverCard is hover-only + trips the passive-listener
    // warning on touch). HoverCardContent already carries the w-80/rounded-xl/
    // p-5 skin the panel's edge-bleed footer needs; PopoverContent gets it via
    // className.
    if (hasHover) {
        return (
            <HoverCard openDelay={150} closeDelay={100}>
                <HoverCardTrigger asChild>{trigger}</HoverCardTrigger>
                <HoverCardContent align="end" side="bottom">
                    {panel}
                </HoverCardContent>
            </HoverCard>
        );
    }

    return (
        <Popover>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                align="end"
                side="bottom"
                className="w-80 rounded-xl border-border/60 bg-card p-5 text-foreground shadow-xl"
            >
                {panel}
            </PopoverContent>
        </Popover>
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
