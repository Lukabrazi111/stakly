import { CoordinationPanel } from '@/components/lobby/center/coordination-panel';
import { MoneyBlock } from '@/components/lobby/center/money-block';
import { SkillBlock } from '@/components/lobby/center/skill-block';
import { TrustBlock } from '@/components/lobby/center/trust-block';
import type { Lobby } from '@/types';

interface Props {
    lobby: Lobby;
    onToggleReady: () => void;
    onLeave: () => void;
    isProcessing: boolean;
}

/**
 * The four-block center column on the team-play listing page. Vertical
 * stack: Money (HERO) → Skill matchup → Trust signals → State-dependent
 * coordination panel. Composition lives here so the parent layout can
 * place this between Team A and Team B without dictating block order.
 *
 * Ready / Leave actions live on the Money block — putting the commit
 * action immediately after the financial stakes ("$200 pot / +$36 win /
 * −$20 lose / [Ready up]") frames the moment correctly.
 */
export function LobbyCenterColumn({
    lobby,
    onToggleReady,
    onLeave,
    isProcessing,
}: Props) {
    return (
        <div className="space-y-4 lg:space-y-5">
            <MoneyBlock
                lobby={lobby}
                onToggleReady={onToggleReady}
                onLeave={onLeave}
                isProcessing={isProcessing}
            />
            <SkillBlock skill={lobby.aggregates.skill} />
            <TrustBlock trust={lobby.aggregates.trust} />
            <CoordinationPanel lobby={lobby} />
        </div>
    );
}
