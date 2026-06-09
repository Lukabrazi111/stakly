import { Crown, Swords, Target } from 'lucide-react';
import type { ElementType } from 'react';
import type { GameId } from '@/config/games';
import { useT } from '@/lib/i18n';

const GAME_META: Record<GameId, { icon: ElementType; label: string }> = {
    chess: { icon: Crown, label: 'Chess' },
    cs2: { icon: Target, label: 'CS2' },
    dota2: { icon: Swords, label: 'Dota 2' },
};

interface Props {
    game: GameId;
}

/**
 * Compact game indicator used on dense list surfaces (My listings, Match
 * history, Marketplace row). Same icon mapping as the create-form picker so
 * the visual association from create → manage → match stays consistent.
 */
export function GameChip({ game }: Props) {
    const t = useT();
    const meta = GAME_META[game];

    if (!meta) {
        return null;
    }

    const Icon = meta.icon;

    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-card/60 px-2.5 py-0.5 text-xs font-medium text-foreground">
            <Icon className="size-3 text-primary" aria-hidden="true" />
            {t(meta.label)}
        </span>
    );
}
