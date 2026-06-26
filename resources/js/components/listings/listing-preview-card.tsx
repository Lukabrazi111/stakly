import { usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    Clock,
    Globe,
    Languages,
    Lock,
    Trophy,
} from 'lucide-react';
import { FaceitRatingBadge } from '@/components/listings/faceit-rating-badge';
import { GameChip } from '@/components/listings/game-chip';
import { VerifiedPlatformChip } from '@/components/listings/verified-platform-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { GameId } from '@/config/games';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { formatSkillRange, timeControlChipLabels } from '@/lib/listings-format';
import type { User } from '@/types/auth';
import type {
    FaceitRating,
    ListingPlatform,
    TimeControl,
} from '@/types/listings';

interface Props {
    game: GameId;
    platform: ListingPlatform;
    teamSize: number;
    stakeAmount: string;
    skillMin: string;
    skillMax: string;
    timeControl: TimeControl[];
    region: string;
    language: string[];
    durationHours: number;
    isPublic: boolean;
    verified: boolean;
    // M41 P2 — the creator's own FACEIT rating, shown on the CS2 preview in
    // place of the skill chip. Null when they have no FACEIT link.
    faceitRating: FaceitRating | null;
}

/**
 * Non-interactive twin of `ListingGridCard` that mirrors how the listing will
 * appear on the marketplace, driven live by the create-form state. No overlay
 * link or Take button — the listing doesn't exist yet, and the creator is the
 * signed-in user. Reuses the same chips + skill formatter as the real card so
 * the preview can't drift from the board. Time shows the chosen duration (a
 * stable "24h", not a live `Date.now()` countdown) — SSR-safe and avoids the
 * "23h 59m" rounding a real countdown would print the instant after posting.
 */
export function ListingPreviewCard({
    game,
    platform,
    teamSize,
    stakeAmount,
    skillMin,
    skillMax,
    timeControl,
    region,
    language,
    durationHours,
    isPublic,
    verified,
    faceitRating,
}: Props) {
    const t = useT();
    const getInitials = useInitials();
    const user = usePage<{ auth: { user: User | null } }>().props.auth.user;

    const isTeamPlay = teamSize > 1;
    const skill = formatSkillRange(
        skillMin === '' ? null : Number(skillMin),
        skillMax === '' ? null : Number(skillMax),
        t,
    );
    const stakeDisplay = stakeAmount === '' ? '0' : stakeAmount;

    return (
        <article className="flex flex-col gap-4 overflow-hidden rounded-2xl border border-border/60 bg-card p-4 md:p-5">
            <header className="flex items-start justify-between gap-2">
                <div className="flex flex-wrap items-center gap-1.5">
                    <GameChip
                        game={game}
                        teamSize={isTeamPlay ? teamSize : undefined}
                    />
                    <VerifiedPlatformChip platform={platform} />
                </div>
                <span className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-muted-foreground">
                    <Clock className="size-3" aria-hidden="true" />
                    {formatDuration(durationHours)}
                </span>
            </header>

            <div className="flex items-center gap-3">
                <Avatar className="size-12 shrink-0 overflow-hidden rounded-full">
                    <AvatarImage
                        src={user?.avatar_thumb_url ?? undefined}
                        alt={user?.username ?? ''}
                    />
                    <AvatarFallback className="bg-gradient-primary text-sm font-semibold text-primary-foreground">
                        {getInitials(user?.name ?? '')}
                    </AvatarFallback>
                </Avatar>
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="truncate text-sm font-semibold text-foreground">
                        {user?.username ?? t('You')}
                    </span>
                    <div className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-muted-foreground">
                        {region && (
                            <span className="inline-flex items-center gap-1">
                                <Globe className="size-3" aria-hidden="true" />
                                {region}
                            </span>
                        )}
                        {region && verified && (
                            <span aria-hidden="true" className="opacity-60">
                                ·
                            </span>
                        )}
                        {verified && (
                            <span className="inline-flex items-center gap-1 text-success">
                                <BadgeCheck
                                    className="size-3"
                                    aria-hidden="true"
                                />
                                {t('Verified')}
                            </span>
                        )}
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                {game === 'cs2' ? (
                    <FaceitRatingBadge
                        rating={faceitRating}
                        variant="compact"
                    />
                ) : (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Trophy className="size-3" aria-hidden="true" />
                        {skill}
                    </span>
                )}

                {!isTeamPlay &&
                    timeControlChipLabels(timeControl, t).map((label) => (
                        <span
                            key={label}
                            className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                        >
                            <Clock className="size-3" aria-hidden="true" />
                            {label}
                        </span>
                    ))}

                {language.length > 0 && (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Languages className="size-3" aria-hidden="true" />
                        {language.slice(0, 2).join(', ')}
                        {language.length > 2 && ` +${language.length - 2}`}
                    </span>
                )}

                {!isPublic && (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Lock className="size-3" aria-hidden="true" />
                        {t('Private')}
                    </span>
                )}
            </div>

            <div className="mt-auto flex items-end justify-between gap-3 border-t border-border/40 pt-4">
                <div className="flex items-baseline gap-1">
                    <span className="text-gradient-primary font-display text-3xl leading-none font-bold">
                        ${stakeDisplay}
                    </span>
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>
            </div>
        </article>
    );
}

function formatDuration(hours: number): string {
    if (hours < 24) {
        return `${hours}h`;
    }

    const days = Math.floor(hours / 24);
    const remainder = hours % 24;

    return remainder === 0 ? `${days}d` : `${days}d ${remainder}h`;
}
