import { CoordinationPanel } from '@/components/lobby/center/coordination-panel';
import { MoneyBlock } from '@/components/lobby/center/money-block';
import { SkillBlock } from '@/components/lobby/center/skill-block';
import { TrustBlock } from '@/components/lobby/center/trust-block';
import type { Lobby } from '@/types';

interface Props {
    lobby: Lobby;
}

/**
 * The four-block center column on the team-play listing page. Vertical
 * stack: Money (HERO) → Skill matchup → Trust signals → State-dependent
 * coordination panel. Composition lives here so the parent layout can
 * place this between Team A and Team B without dictating block order.
 */
export function LobbyCenterColumn({ lobby }: Props) {
    return (
        <div className="space-y-4 lg:space-y-5">
            <MoneyBlock aggregates={lobby.aggregates} />
            <SkillBlock skill={lobby.aggregates.skill} />
            <TrustBlock trust={lobby.aggregates.trust} />
            <CoordinationPanel lobby={lobby} />
        </div>
    );
}
