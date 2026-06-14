import { CoordinationPanel } from '@/components/lobby/center/coordination-panel';
import { MoneyBlock } from '@/components/lobby/center/money-block';
import { SkillBlock } from '@/components/lobby/center/skill-block';
import { TrustBlock } from '@/components/lobby/center/trust-block';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Lobby } from '@/types';
import type { MatchStatus } from '@/types/match';

interface Props {
    lobby: Lobby;
    onToggleReady: () => void;
    onLeave: () => void;
    isProcessing: boolean;
}

type CoordPingTone = 'warning' | 'success' | 'destructive' | 'muted';

/**
 * Status-aware ping color on the Coord tab — surfaces the current match
 * state at a glance without forcing the viewer to switch tabs. Always
 * visible while the Coord tab exists; the radar pulse keeps it as a live
 * indicator rather than a one-shot notification.
 */
const STATUS_PING_TONE: Record<MatchStatus, CoordPingTone | null> = {
    lobby_filling: null,
    pending: 'warning',
    settled: 'success',
    disputed: 'destructive',
    manual_review: 'destructive',
    cancelled: 'muted',
};

const PING_TONE_CLASS: Record<CoordPingTone, string> = {
    warning: 'bg-warning',
    success: 'bg-success',
    destructive: 'bg-destructive',
    muted: 'bg-muted-foreground/60',
};

/**
 * The center column on the team-play listing page. Money block (pot +
 * breakdown + Ready/Leave) is always visible at the top — the action
 * surface. Skill / Trust / Coordinate fold into an underlined tab strip
 * below so the column height stays constant.
 *
 * Coord tab visibility is gated on `isLocked`; while visible, a pulsing
 * notification dot at the tab's top-right corner reflects the current
 * match status (pending=amber, settled=green, dispute/review=red,
 * cancelled=muted gray).
 */
export function LobbyCenterColumn({
    lobby,
    onToggleReady,
    onLeave,
    isProcessing,
}: Props) {
    const t = useT();
    const isLocked = lobby.lobby_state === 'locked';
    const matchStatus = lobby.match_status;
    const pingTone =
        isLocked && matchStatus ? STATUS_PING_TONE[matchStatus] : null;

    return (
        <div className="space-y-4 lg:space-y-5">
            <MoneyBlock
                lobby={lobby}
                onToggleReady={onToggleReady}
                onLeave={onLeave}
                isProcessing={isProcessing}
            />

            <Tabs
                defaultValue={isLocked ? 'coord' : 'matchup'}
                className="gap-3"
            >
                <TabsList variant="line">
                    <TabsTrigger value="matchup">{t('Matchup')}</TabsTrigger>
                    <TabsTrigger value="trust">{t('Trust')}</TabsTrigger>
                    {isLocked && (
                        <TabsTrigger value="coord" className="relative">
                            {t('Coordinate')}
                            {pingTone && (
                                <NotificationPing
                                    tone={pingTone}
                                    className="absolute top-1 -right-1.5"
                                />
                            )}
                        </TabsTrigger>
                    )}
                </TabsList>

                <TabsContent value="matchup">
                    <SkillBlock skill={lobby.aggregates.skill} />
                </TabsContent>
                <TabsContent value="trust">
                    <TrustBlock trust={lobby.aggregates.trust} />
                </TabsContent>
                {isLocked && (
                    <TabsContent value="coord">
                        <CoordinationPanel lobby={lobby} />
                    </TabsContent>
                )}
            </Tabs>
        </div>
    );
}

interface NotificationPingProps {
    tone: CoordPingTone;
    className?: string;
}

function NotificationPing({ tone, className }: NotificationPingProps) {
    const colorClass = PING_TONE_CLASS[tone];

    return (
        <span
            className={cn('inline-flex size-2', className)}
            aria-hidden="true"
        >
            <span
                className={cn(
                    'absolute inline-flex size-2 animate-ping rounded-full opacity-90',
                    colorClass,
                )}
            />
            <span
                className={cn(
                    'relative inline-flex size-2 rounded-full',
                    colorClass,
                )}
            />
        </span>
    );
}
