import { ShieldCheck } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { ListingPlatform } from '@/types/listings';

interface MatchDetailsStripProps {
    pot: number;
    stakeEach: number;
    /** Per-player payout for the winning side (post-fee). */
    winnerPayout: number;
    /** Per-player loss for the losing side (= stake_amount). */
    loserLoss: number;
    platform: ListingPlatform;
}

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
    faceit: 'FACEIT',
    steam: 'Steam',
};

/**
 * Compact economics + verification strip rendered as the first child of
 * `TeamRosters` on the team-aware match page. Fills the gap left when the
 * lobby's Money block disappears post-lock — the viewer needs to see Pot,
 * stake, potential payout, and the settlement source somewhere on the
 * match page.
 *
 * Layout: 4 money cells in a horizontal row on lg+, wraps to 2x2 on
 * mobile. Verification chip on the right edge (sm+) or its own row
 * below (mobile).
 */
export function MatchDetailsStrip({
    pot,
    stakeEach,
    winnerPayout,
    loserLoss,
    platform,
}: MatchDetailsStripProps) {
    const t = useT();

    return (
        <div className="mb-5 rounded-xl border border-border/40 bg-background/40 p-4">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <dl className="grid grid-cols-2 gap-4 sm:flex sm:flex-1 sm:gap-6">
                    <Cell label={t('Pot')} value={`$${pot}`} />
                    <Cell label={t('Stake')} value={`$${stakeEach}`} />
                    <Cell
                        label={t('If you win')}
                        value={`+$${winnerPayout.toFixed(2)}`}
                        tone="win"
                    />
                    <Cell
                        label={t('If you lose')}
                        value={`−$${loserLoss}`}
                        tone="lose"
                    />
                </dl>

                <div className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-success/30 bg-success/10 px-3 py-1 text-xs font-medium text-success">
                    <ShieldCheck className="size-3.5" aria-hidden="true" />
                    {t('Verified via :platform', {
                        platform: PLATFORM_LABEL[platform],
                    })}
                </div>
            </div>
        </div>
    );
}

interface CellProps {
    label: string;
    value: string;
    tone?: 'default' | 'win' | 'lose';
}

function Cell({ label, value, tone = 'default' }: CellProps) {
    const valueClass =
        tone === 'win'
            ? 'text-gradient-primary font-display text-base font-bold'
            : tone === 'lose'
              ? 'font-display text-base font-bold text-destructive'
              : 'font-display text-base font-bold text-foreground';

    return (
        <div className="flex flex-col">
            <dt className="text-[10px] tracking-wider text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className={`${valueClass} tabular-nums`}>{value}</dd>
        </div>
    );
}
