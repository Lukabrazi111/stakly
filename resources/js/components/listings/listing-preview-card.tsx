import { usePage } from '@inertiajs/react';
import { BadgeCheck, Clock, Globe, Languages, Lock } from 'lucide-react';
import { ChessRatingBadge } from '@/components/listings/chess-rating-badge';
import { FaceitRatingBadge } from '@/components/listings/faceit-rating-badge';
import { GameChip } from '@/components/listings/game-chip';
import { VerifiedPlatformChip } from '@/components/listings/verified-platform-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { GameId } from '@/config/games';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { timeControlLabel } from '@/lib/listings-format';
import type { User } from '@/types/auth';
import type {
    ChessRating,
    FaceitRating,
    ListingPlatform,
    TimeControl,
} from '@/types/listings';

interface Props {
    game: GameId;
    platform: ListingPlatform;
    teamSize: number;
    stakeAmount: string;
    timeControl: TimeControl | null;
    region: string;
    language: string[];
    durationHours: number;
    isPublic: boolean;
    verified: boolean;
    // M41 P2 — the creator's own FACEIT rating, shown on the CS2 preview in
    // place of the skill chip. Null when they have no FACEIT link.
    faceitRating: FaceitRating | null;
    // M41 P4 — the creator's own chess rating for the selected platform + time
    // control, shown on the chess preview. Null / unrated → "Unrated".
    chessRating: ChessRating | null;
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
    timeControl,
    region,
    language,
    durationHours,
    isPublic,
    verified,
    faceitRating,
    chessRating,
}: Props) {
    const t = useT();
    const getInitials = useInitials();
    const user = usePage<{ auth: { user: User | null } }>().props.auth.user;

    const isTeamPlay = teamSize > 1;
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

            {/* Owner block — mirrors ListingGridCard so the preview matches the
                posted card: avatar + name with the verified rating pinned right
                (CS2 → ELO + dial, chess → bare ELO number), and the region +
                verified trust on its own row below. */}
            <div className="flex flex-col gap-2">
                <div className="flex items-center gap-3">
                    <Avatar className="size-12 shrink-0 overflow-hidden rounded-full">
                        <AvatarImage
                            src={user?.avatar_thumb_url ?? undefined}
                            alt={user?.username ?? ''}
                        />
                        <AvatarFallback className="bg-primary/15 text-sm font-semibold text-primary">
                            {getInitials(user?.name ?? '')}
                        </AvatarFallback>
                    </Avatar>
                    <span className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground">
                        {user?.username ?? t('You')}
                    </span>
                    {game === 'cs2' ? (
                        <span className="shrink-0">
                            <FaceitRatingBadge
                                rating={faceitRating}
                                variant="compact"
                            />
                        </span>
                    ) : game === 'chess' ? (
                        <span className="shrink-0">
                            <ChessRatingBadge
                                rating={chessRating}
                                variant="bare"
                            />
                        </span>
                    ) : null}
                </div>

                {(region || verified) && (
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
                )}
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                {!isTeamPlay && timeControl && (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Clock className="size-3" aria-hidden="true" />
                        {timeControlLabel(timeControl, t)}
                    </span>
                )}

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
