import { Users } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Listing } from '@/types';

interface TeamSizeBadgeProps {
    teamSize: number;
}

/**
 * Pill marker for team-play listings. Sits next to `GameChip`. Visually
 * distinct from generic info chips via the accent purple tone so a CS2 5v5
 * lobby reads differently from a 1v1 chess match at a glance.
 */
export function TeamSizeBadge({ teamSize }: TeamSizeBadgeProps) {
    if (teamSize <= 1) {
        return null;
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-full border border-accent/40 bg-accent/10 px-2.5 py-0.5 text-xs font-semibold text-accent">
            <Users className="size-3" aria-hidden="true" />
            {teamSize}v{teamSize}
        </span>
    );
}

interface LobbyFillCounterProps {
    listing: Listing;
}

/**
 * "3 / 10 players" indicator for team-play rows. Tone shifts as the lobby
 * fills so a near-full lobby reads urgent without extra ceremony.
 */
export function LobbyFillCounter({ listing }: LobbyFillCounterProps) {
    const t = useT();

    if (listing.team_size <= 1) {
        return null;
    }

    const capacity = listing.team_size * 2;
    const filled = listing.live_participant_count;
    const ratio = filled / capacity;

    const tone =
        ratio >= 1
            ? 'text-success'
            : ratio >= 0.7
              ? 'text-warning'
              : 'text-muted-foreground';

    return (
        <span
            className={cn('inline-flex items-center gap-1 text-xs font-medium', tone)}
            aria-label={t(':filled of :capacity players', { filled, capacity })}
        >
            <Users className="size-3" aria-hidden="true" />
            <span className="tabular-nums">
                {filled} / {capacity}
            </span>
        </span>
    );
}

interface LobbyStateBadgeProps {
    state: string | null;
}

/**
 * Surfaces `ready_checking` on the marketplace row so users know the slot
 * window is closing. `recruiting` / `locked` / null render nothing — the
 * Open status and row CTA already convey those.
 */
export function LobbyStateBadge({ state }: LobbyStateBadgeProps) {
    const t = useT();

    if (state !== 'ready_checking') {
        return null;
    }

    return (
        <span className="inline-flex animate-pulse items-center rounded-full border border-warning/40 bg-warning/10 px-2.5 py-0.5 text-xs font-medium text-warning">
            {t('Ready check')}
        </span>
    );
}
