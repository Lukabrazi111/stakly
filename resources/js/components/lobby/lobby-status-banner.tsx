import { CheckCircle2, Info, ShieldCheck } from 'lucide-react';
import { ReadyCheckCountdown } from '@/components/lobby/ready-check-countdown';
import { useT } from '@/lib/i18n';
import type { Lobby } from '@/types';

interface Props {
    lobby: Lobby;
}

/**
 * State-driven hero banner above the team roster. The four lobby_state
 * branches are mutually exclusive — at most one block renders. Hides
 * itself entirely when the listing has no actionable state to convey.
 */
export function LobbyStatusBanner({ lobby }: Props) {
    const t = useT();
    const max = lobby.team_size * 2;
    const filled =
        lobby.roster.a.filter((s) => s !== null).length +
        lobby.roster.b.filter((s) => s !== null).length;

    if (lobby.lobby_state === 'recruiting') {
        return (
            <div className="flex items-center gap-3 rounded-xl border border-border/60 bg-card/60 p-4">
                <Info
                    className="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div className="flex-1">
                    <p className="text-sm font-medium text-foreground">
                        {t('Recruiting players')}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t(':filled of :max slots filled — chat to coordinate, click Ready when set.', {
                            filled,
                            max,
                        })}
                    </p>
                </div>
            </div>
        );
    }

    if (lobby.lobby_state === 'ready_checking') {
        return (
            <div className="flex items-center gap-3 rounded-xl border border-warning/40 bg-warning/10 p-4">
                <div className="flex-1">
                    <p className="text-sm font-medium text-foreground">
                        {t('Ready check')}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t('All slots filled — every player must click Ready before the timer runs out, or non-Ready slots reopen.')}
                    </p>
                </div>
                {lobby.lobby_ready_check_deadline && (
                    <ReadyCheckCountdown
                        deadlineIso={lobby.lobby_ready_check_deadline}
                    />
                )}
            </div>
        );
    }

    if (lobby.lobby_state === 'locked') {
        return (
            <div className="flex items-center gap-3 rounded-xl border border-success/40 bg-success/10 p-4">
                <ShieldCheck
                    className="size-5 shrink-0 text-success"
                    aria-hidden="true"
                />
                <div className="flex-1">
                    <p className="text-sm font-medium text-foreground">
                        {t('Match locked in')}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t('All stakes are escrowed. Coordinate on the platform and play your match — the result will settle automatically.')}
                    </p>
                </div>
                <CheckCircle2
                    className="size-5 shrink-0 text-success"
                    aria-hidden="true"
                />
            </div>
        );
    }

    return null;
}
