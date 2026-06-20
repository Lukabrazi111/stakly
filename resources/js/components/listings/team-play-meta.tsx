import { Users } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Listing } from '@/types';

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
            className={cn(
                'inline-flex items-center gap-1 text-xs font-medium',
                tone,
            )}
            aria-label={t(':filled of :capacity players', { filled, capacity })}
        >
            <Users className="size-3" aria-hidden="true" />
            <span className="tabular-nums">
                {filled} / {capacity}
            </span>
        </span>
    );
}

interface RosterPreviewProps {
    listing: Listing;
}

/**
 * Avatar stack + fill counter + names line for team-play grid cards.
 * Renders nothing if no participants have joined yet. The whole block is
 * inert (`pointer-events-none`) so card clicks fall through to the
 * absolute-overlay link.
 */
export function RosterPreview({ listing }: RosterPreviewProps) {
    const t = useT();
    const previews = listing.participant_previews;

    if (previews.length === 0) {
        return null;
    }

    const extra = Math.max(0, listing.live_participant_count - previews.length);
    const namesText =
        extra > 0
            ? t(':names + :extra more', {
                  names: previews.map((p) => p.username).join(', '),
                  extra,
              })
            : previews.map((p) => p.username).join(', ');

    return (
        <div className="pointer-events-none relative flex flex-wrap items-center gap-2">
            <div className="flex -space-x-2">
                {previews.map((p) => (
                    <RosterAvatar
                        key={p.username}
                        username={p.username}
                        name={p.name}
                        avatarThumbUrl={p.avatar_thumb_url}
                    />
                ))}
            </div>
            <LobbyFillCounter listing={listing} />
            <span className="text-xs text-muted-foreground">·</span>
            <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                {namesText}
            </span>
        </div>
    );
}

function RosterAvatar({
    username,
    name,
    avatarThumbUrl,
}: {
    username: string;
    name: string;
    avatarThumbUrl: string | null;
}) {
    const getInitials = useInitials();

    return (
        <Avatar className="size-7 overflow-hidden rounded-full border-2 border-card">
            <AvatarImage src={avatarThumbUrl ?? undefined} alt={username} />
            <AvatarFallback className="bg-gradient-primary text-[10px] font-semibold text-primary-foreground">
                {getInitials(name)}
            </AvatarFallback>
        </Avatar>
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
