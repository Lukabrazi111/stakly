import { CheckCircle2, LogOut, Skull, Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Lobby } from '@/types';

interface Props {
    lobby: Lobby;
    onToggleReady: () => void;
    onLeave: () => void;
    isProcessing: boolean;
}

/**
 * Money block — HERO of the center column. The pot total stamped at the
 * top in display font, then a split-tile breakdown ("If you win" success
 * vs "If you lose" destructive) with the per-player numbers. Fee underneath
 * as a muted line item.
 *
 * When the viewer is a participant, a Ready / Leave action row appears
 * under the fee. Placing the actions here ties the commit moment to the
 * financial stakes the viewer just read. The row is hidden once the lobby
 * locks — toggling Ready after lock is meaningless.
 */
export function MoneyBlock({
    lobby,
    onToggleReady,
    onLeave,
    isProcessing,
}: Props) {
    const t = useT();
    const aggregates = lobby.aggregates;
    const viewer = lobby.viewer;
    const showActions =
        viewer?.is_participant === true && lobby.lobby_state !== 'locked';

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

            {showActions && viewer !== null && (
                <ViewerActions
                    lobby={lobby}
                    viewer={viewer}
                    onToggleReady={onToggleReady}
                    onLeave={onLeave}
                    isProcessing={isProcessing}
                />
            )}
        </section>
    );
}

interface ViewerActionsProps {
    lobby: Lobby;
    viewer: NonNullable<Lobby['viewer']>;
    onToggleReady: () => void;
    onLeave: () => void;
    isProcessing: boolean;
}

/**
 * Two-button row under the platform-fee line. Ready button picks one of
 * three labels — "Un-Ready" (currently ready), "Top up to ready" (skint,
 * disabled), or "Ready up" — based on viewer state. Leave button reads
 * "Cancel lobby" when the viewer is the creator (the action cancels the
 * whole lobby, not just their participation).
 */
function ViewerActions({
    lobby,
    viewer,
    onToggleReady,
    onLeave,
    isProcessing,
}: ViewerActionsProps) {
    const t = useT();
    const insufficient =
        !viewer.is_ready && viewer.balance < lobby.stake_amount;
    const readyDisabled = isProcessing || (insufficient && !viewer.is_ready);

    let readyLabel: string;

    if (viewer.is_ready) {
        readyLabel = t('Un-Ready');
    } else if (insufficient) {
        readyLabel = t('Top up to ready');
    } else {
        readyLabel = t('Ready up');
    }

    const leaveLabel = viewer.is_owner ? t('Cancel lobby') : t('Leave');

    const readyClass = readyVariantClass({
        ready: viewer.is_ready,
        insufficient,
    });

    return (
        <div className="mt-4 grid grid-cols-2 gap-3">
            <Button
                type="button"
                variant="default"
                size="default"
                onClick={onToggleReady}
                disabled={readyDisabled}
                title={
                    insufficient
                        ? t('Top up your balance to ready up.')
                        : undefined
                }
                className={cn(
                    'rounded-full',
                    readyClass,
                    insufficient && 'cursor-not-allowed',
                )}
            >
                <CheckCircle2 className="size-4" aria-hidden="true" />
                {readyLabel}
            </Button>

            <Button
                type="button"
                variant="outline"
                size="default"
                onClick={onLeave}
                disabled={isProcessing}
                className="rounded-full border-destructive/40 bg-destructive/10 text-destructive hover:border-destructive/60 hover:bg-destructive/20 hover:text-destructive"
            >
                <LogOut className="size-4" aria-hidden="true" />
                {leaveLabel}
            </Button>
        </div>
    );
}

/**
 * Picks the visual treatment for the Ready button by viewer state:
 *   not ready + sufficient → solid emerald CTA (primary).
 *   already ready          → translucent emerald outline (secondary, click un-readies).
 *   insufficient balance   → translucent amber outline (disabled, "Top up to ready").
 */
function readyVariantClass({
    ready,
    insufficient,
}: {
    ready: boolean;
    insufficient: boolean;
}): string {
    if (insufficient && !ready) {
        return 'border border-warning/40 bg-warning/10 text-warning hover:bg-warning/10';
    }

    if (ready) {
        return 'border border-success/40 bg-success/10 text-success hover:border-success/60 hover:bg-success/20 hover:text-success';
    }

    return 'bg-success text-white hover:bg-success/90';
}

function formatMoney(value: number): string {
    return Number.isInteger(value) ? value.toString() : value.toFixed(2);
}
