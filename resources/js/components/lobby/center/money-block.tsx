import { Skull, Sparkles } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { LobbyAggregates } from '@/types';

interface Props {
    aggregates: LobbyAggregates;
}

/**
 * Money block — HERO of the center column. The pot total stamped at the
 * top in display font, then a split-tile breakdown ("If you win" success
 * vs "If you lose" destructive) with the per-player numbers. Fee underneath
 * as a muted line item.
 *
 * Numbers come from the resource's `aggregates` block; rendering only.
 */
export function MoneyBlock({ aggregates }: Props) {
    const t = useT();

    return (
        <section className="rounded-2xl border border-border/60 bg-card/60 p-5">
            <div className="text-center">
                <div className="text-[10px] font-medium tracking-[0.18em] text-muted-foreground uppercase">
                    {t('Total pot')}
                </div>
                <div className="mt-1.5 text-gradient-primary font-display text-5xl leading-none font-bold tracking-tight">
                    ${formatMoney(aggregates.pot)}
                </div>
                <div className="mt-1 text-xs text-muted-foreground">USDT</div>
            </div>

            <div className="mt-5 grid grid-cols-2 gap-3">
                <div className="rounded-xl border border-success/40 bg-success/10 p-4 text-center">
                    <Sparkles
                        className="mx-auto size-4 text-success"
                        aria-hidden="true"
                    />
                    <div className="mt-1.5 text-[10px] font-semibold tracking-wider text-success uppercase">
                        {t('If you win')}
                    </div>
                    <div className="mt-2 font-display text-2xl leading-none font-bold text-success tabular-nums">
                        +${formatMoney(aggregates.winner_take_per_player)}
                    </div>
                    <div className="mt-1 text-[11px] text-muted-foreground">
                        {t('per player')}
                    </div>
                </div>

                <div className="rounded-xl border border-destructive/40 bg-destructive/10 p-4 text-center">
                    <Skull
                        className="mx-auto size-4 text-destructive"
                        aria-hidden="true"
                    />
                    <div className="mt-1.5 text-[10px] font-semibold tracking-wider text-destructive uppercase">
                        {t('If you lose')}
                    </div>
                    <div className="mt-2 font-display text-2xl leading-none font-bold text-destructive tabular-nums">
                        −${formatMoney(aggregates.loser_loss_per_player)}
                    </div>
                    <div className="mt-1 text-[11px] text-muted-foreground">
                        {t('your stake')}
                    </div>
                </div>
            </div>

            <div className="mt-4 flex items-center justify-between border-t border-border/40 pt-3 text-xs text-muted-foreground">
                <span>{t('Platform fee')}</span>
                <span className="tabular-nums">
                    ${formatMoney(aggregates.fee)}
                </span>
            </div>
        </section>
    );
}

function formatMoney(value: number): string {
    return Number.isInteger(value) ? value.toString() : value.toFixed(2);
}
