import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    Crown,
    EllipsisVertical,
    LogIn,
    UserMinus,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { FaceitRatingBadge } from '@/components/listings/faceit-rating-badge';
import { RecentFormStrip } from '@/components/listings/recent-form-strip';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LobbyParticipantPayload, LobbySide } from '@/types';

// Team accent — Team A pink (primary), Team B purple (accent), the two ends of
// the brand gradient facing off. Applied as a subtle avatar ring so each player
// reads as their team down the column, without touching the card border (which
// already encodes ready / viewer state).
const TEAM_AVATAR_RING: Record<LobbySide, string> = {
    a: 'ring-2 ring-primary/50',
    b: 'ring-2 ring-accent/50',
};

interface FilledSlotProps {
    participant: LobbyParticipantPayload;
    side: LobbySide;
    isViewer: boolean;
    canKick: boolean;
    onKick: (username: string) => void;
}

/**
 * Filled lobby slot, modelled on the FACEIT roster card (M41 P8): a main column
 * (avatar + name + ELO level dial header → "OVERALL" stat block) and a flush
 * right-edge vertical W/L form column. Display-only — the viewer's Ready / Leave
 * actions live in the Money block on the center column, not here.
 */
export function FilledSlot({
    participant,
    side,
    isViewer,
    canKick,
    onKick,
}: FilledSlotProps) {
    const t = useT();
    const initials =
        participant.user.name
            ?.split(' ')
            .map((part) => part[0])
            .slice(0, 2)
            .join('')
            .toUpperCase() ?? '??';

    const showKick = !isViewer && canKick && !participant.is_creator;
    const teamRing = TEAM_AVATAR_RING[side];

    return (
        <div
            className={cn(
                'relative flex overflow-hidden rounded-xl border bg-card/60 transition-colors',
                participant.is_ready
                    ? 'border-success/40 bg-success/5'
                    : isViewer
                      ? 'border-primary/40 bg-primary/5'
                      : 'border-border/60',
            )}
        >
            <div className="relative flex min-w-0 flex-1 flex-col gap-2.5 px-3.5 py-3">
                {/* Header — avatar + name (left), ELO level dial + Ready (right) */}
                <div className="flex items-center gap-3">
                    <div className="relative size-10 shrink-0">
                        {participant.user.avatar_thumb_url ? (
                            <img
                                src={participant.user.avatar_thumb_url}
                                alt={participant.user.name}
                                className={cn(
                                    'size-10 rounded-full object-cover',
                                    teamRing,
                                )}
                            />
                        ) : (
                            <div
                                className={cn(
                                    'flex size-10 items-center justify-center rounded-full bg-gradient-primary text-sm font-semibold text-foreground',
                                    teamRing,
                                )}
                            >
                                {initials}
                            </div>
                        )}
                        {participant.is_creator && (
                            <span
                                title={t('Lobby owner')}
                                className="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full bg-accent text-background"
                            >
                                <Crown
                                    className="size-2.5"
                                    aria-hidden="true"
                                />
                            </span>
                        )}
                    </div>

                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span className="truncate text-sm font-semibold text-foreground">
                            {isViewer ? t('You') : participant.user.name}
                        </span>
                        {participant.platform_account && (
                            <span className="truncate font-mono text-[11px] text-muted-foreground">
                                {participant.platform_account.username}
                            </span>
                        )}
                    </div>

                    <div className="flex shrink-0 flex-col items-end gap-1.5">
                        <div className="flex items-center gap-1">
                            <FaceitRatingBadge
                                rating={participant.faceit_rating}
                                variant="compact"
                                dialSize={30}
                            />
                            {showKick && (
                                <SlotOwnerMenu
                                    playerName={participant.user.name}
                                    onConfirmRemove={() =>
                                        onKick(participant.user.username)
                                    }
                                />
                            )}
                        </div>
                        <ReadyPill ready={participant.is_ready} />
                    </div>
                </div>

                <StatsLine stats={participant.platform_stats} />
            </div>

            {/* Recent W/L form — flush, full-height right-edge column, always 5
                slots (FACEIT-roster style); empty slots show a faded "N". */}
            <RecentFormStrip
                form={participant.recent_form}
                orientation="vertical"
                slots={5}
            />
        </div>
    );
}

interface SlotOwnerMenuProps {
    playerName: string;
    onConfirmRemove: () => void;
}

/**
 * Owner-only kebab menu on an opponent slot card (M34 P5). A neutral `⋮`
 * trigger keeps the dense roster card calm and gives the destructive action
 * its own lane, clear of the ELO dial / W/L strip. "Remove player" opens a
 * confirm dialog before firing the kick so a stray tap can't drop a filled
 * slot in a money lobby. The kick mutation itself stays in the parent
 * (`team-play-lobby-view`) via `onConfirmRemove`.
 */
function SlotOwnerMenu({ playerName, onConfirmRemove }: SlotOwnerMenuProps) {
    const t = useT();
    const [menuOpen, setMenuOpen] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    return (
        <>
            <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 text-muted-foreground hover:bg-transparent hover:text-primary"
                        aria-label={t('Manage :name', { name: playerName })}
                    >
                        <EllipsisVertical
                            className="size-4"
                            aria-hidden="true"
                        />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-[10rem]">
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={(event) => {
                            // preventDefault stops Radix from returning focus to
                            // the trigger as it auto-closes; close the menu
                            // explicitly, then open the confirm so focus lands
                            // in the dialog cleanly.
                            event.preventDefault();
                            setMenuOpen(false);
                            setConfirmOpen(true);
                        }}
                    >
                        <UserMinus aria-hidden="true" />
                        {t('Remove player')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('Remove :name from the lobby?', {
                                name: playerName,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t(
                                'Their slot reopens for another player to join.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setConfirmOpen(false)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                onConfirmRemove();
                                setConfirmOpen(false);
                            }}
                        >
                            {t('Remove')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function ReadyPill({ ready }: { ready: boolean }) {
    const t = useT();

    if (ready) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full border border-success/40 bg-success/10 px-1.5 py-0.5 text-[10px] font-medium text-success">
                <CheckCircle2 className="size-2.5" aria-hidden="true" />
                {t('Ready')}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center rounded-full border border-border/60 px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
            {t('Waiting')}
        </span>
    );
}

interface StatsLineProps {
    stats: LobbyParticipantPayload['platform_stats'];
}

/**
 * "OVERALL" stat block at the foot of the slot card: Matches / Win rate /
 * Completion-30d (the FACEIT-roster reference shows Avg HS / Avg K/D too, but
 * those need the FACEIT history API — out of scope; these are Stakly-DB stats).
 * Renders a muted placeholder for users with no settled matches yet.
 */
function StatsLine({ stats }: StatsLineProps) {
    const t = useT();

    if (stats === null || stats.total_matches === 0) {
        return (
            <div className="border-t border-border/50 pt-2.5 text-[10px] text-muted-foreground/60">
                {t('No matches yet')}
            </div>
        );
    }

    return (
        <div className="border-t border-border/50 pt-2.5">
            <div className="mb-1.5 text-[9px] font-medium tracking-wider text-muted-foreground/60 uppercase">
                {t('Overall')}
            </div>
            <div className="grid grid-cols-3 gap-3">
                <StatCell
                    label={t('Matches')}
                    value={String(stats.total_matches)}
                />
                <StatCell
                    label={t('Win rate')}
                    value={stats.win_rate === null ? '—' : `${stats.win_rate}%`}
                    align="center"
                />
                <StatCell
                    label={t('Completion 30d')}
                    value={
                        stats.completion_rate_30d === null
                            ? '—'
                            : `${stats.completion_rate_30d}%`
                    }
                    align="right"
                />
            </div>
        </div>
    );
}

interface StatCellProps {
    label: string;
    value: string;
    align?: 'left' | 'center' | 'right';
}

function StatCell({ label, value, align = 'left' }: StatCellProps) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col',
                align === 'center' && 'items-center text-center',
                align === 'right' && 'items-end text-right',
            )}
        >
            <span className="font-display text-base font-bold text-foreground tabular-nums">
                {value}
            </span>
            <span className="text-[9px] tracking-wider text-muted-foreground/70 uppercase">
                {label}
            </span>
        </div>
    );
}

interface EmptySlotProps {
    side: LobbySide;
    canJoin: boolean;
    /** Guest viewers get a sign-in link (opens the auth modal) instead of a
     *  dead-end "Open slot" — set only when the lobby is joinable-if-authed. */
    signInHref?: string;
    onJoin: (side: LobbySide) => void;
}

/**
 * Empty slot placeholder, three states by viewer:
 *   - can join (authed, eligible)  → the whole card is a solid Join CTA.
 *   - guest (lobby is joinable)     → a "Sign in to join" link.
 *   - otherwise                     → a passive "Open slot" indicator.
 * Height tracks the filled card so a partially-filled column reads as a clean
 * vertical stack.
 */
export function EmptySlot({
    side,
    canJoin,
    signInHref,
    onJoin,
}: EmptySlotProps) {
    const t = useT();

    if (canJoin) {
        return (
            <button
                type="button"
                onClick={() => onJoin(side)}
                className={cn(
                    'group flex h-[128px] w-full cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border text-sm font-semibold transition-colors',
                    'border-primary/50 bg-primary/10 text-primary',
                    'hover:border-primary hover:bg-primary/20',
                )}
            >
                <UserPlus className="size-5" aria-hidden="true" />
                {t('Join Team :side', { side: side.toUpperCase() })}
            </button>
        );
    }

    if (signInHref) {
        return (
            <Link
                href={signInHref}
                className={cn(
                    'group flex h-[128px] w-full flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed text-sm font-medium transition-colors',
                    'border-primary/40 bg-primary/5 text-primary',
                    'hover:border-primary/60 hover:bg-primary/10',
                )}
            >
                <LogIn className="size-5" aria-hidden="true" />
                {t('Sign in to join')}
            </Link>
        );
    }

    return (
        <div className="flex h-[128px] w-full items-center justify-center rounded-xl border border-dashed border-border/40 bg-card/30 text-xs text-muted-foreground">
            {t('Open slot')}
        </div>
    );
}
