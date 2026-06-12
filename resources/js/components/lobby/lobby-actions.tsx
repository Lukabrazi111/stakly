import { CheckCircle2, LogOut, Wallet } from 'lucide-react';
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
 * Action bar shown below the team roster. Only renders when the viewer is
 * a live participant — non-participants Join via the empty-slot CTAs, not
 * here.
 *
 * Ready toggle:
 *   - Disabled when balance < stake_amount (hint to top up).
 *   - Disabled when lobby has locked.
 *   - Labels swap based on current is_ready state.
 */
export function LobbyActions({
    lobby,
    onToggleReady,
    onLeave,
    isProcessing,
}: Props) {
    const t = useT();
    const viewer = lobby.viewer;

    if (viewer === null || !viewer.is_participant) {
        return null;
    }

    const isLocked = lobby.lobby_state === 'locked';
    const insufficient =
        !viewer.is_ready && viewer.balance < lobby.stake_amount;

    return (
        <div className="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card/60 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-3 text-sm">
                <Wallet
                    className="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div>
                    <div className="text-xs text-muted-foreground">
                        {t('Stake per player')}
                    </div>
                    <div className="font-mono text-base font-semibold text-foreground tabular-nums">
                        ${lobby.stake_amount.toFixed(2)} USDT
                    </div>
                </div>
                {insufficient && (
                    <span className="ml-2 rounded-full border border-warning/40 bg-warning/10 px-2.5 py-1 text-xs font-medium text-warning">
                        {t('Top up to ready')}
                    </span>
                )}
            </div>

            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="pill"
                    onClick={onLeave}
                    disabled={isProcessing || isLocked}
                    className={cn(
                        'text-muted-foreground hover:text-destructive',
                    )}
                >
                    <LogOut className="size-4" aria-hidden="true" />
                    {viewer.is_owner ? t('Cancel lobby') : t('Leave')}
                </Button>

                <Button
                    type="button"
                    variant={viewer.is_ready ? 'outline' : 'gradient'}
                    size="pill"
                    onClick={onToggleReady}
                    disabled={isProcessing || isLocked || insufficient}
                >
                    <CheckCircle2 className="size-4" aria-hidden="true" />
                    {viewer.is_ready ? t('Un-Ready') : t('Ready up')}
                </Button>
            </div>
        </div>
    );
}
