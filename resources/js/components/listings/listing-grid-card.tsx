import { Link } from '@inertiajs/react';
import { Clock, Globe, Languages, Trophy } from 'lucide-react';
import { ChessRatingBadge } from '@/components/listings/chess-rating-badge';
import { FaceitRatingBadge } from '@/components/listings/faceit-rating-badge';
import { GameChip } from '@/components/listings/game-chip';
import { ReadyCheckBanner } from '@/components/listings/ready-check-banner';
import { RecentFormStrip } from '@/components/listings/recent-form-strip';
import { SellerTrustMeta } from '@/components/listings/seller-trust-meta';
import { TakeButton } from '@/components/listings/take-button';
import { RosterPreview } from '@/components/listings/team-play-meta';
import { VerifiedPlatformChip } from '@/components/listings/verified-platform-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import {
    formatSkillRange,
    formatTimeRemaining,
    getTimeUrgency,
    timeControlLabel,
} from '@/lib/listings-format';
import { show as showListing } from '@/routes/listings';
import { show as userShow } from '@/routes/users';
import type { Listing } from '@/types';

interface Props {
    listing: Listing;
}

/**
 * Grid card variant for `/listings` + `/listings/mine` when the user toggles
 * to grid layout. Vertical hierarchy: optional Ready-check banner → header
 * strip (game / team-size / platform + ends-in) → owner zone → match meta
 * (skill + languages) → roster preview row (avatars + fill counter + names)
 * → stake + CTA footer.
 *
 * Sibling to `ListingRow`. Both consume the same `Listing` shape; rows is
 * the comparison-first default, grid is the browse-first opt-in.
 */
export function ListingGridCard({ listing }: Props) {
    const t = useT();
    const getInitials = useInitials();
    const timeRemaining = formatTimeRemaining(listing.expires_at, t);
    const urgency = getTimeUrgency(listing.expires_at);

    const urgencyTone =
        urgency === 'critical'
            ? 'text-destructive'
            : urgency === 'warning'
              ? 'text-warning'
              : 'text-muted-foreground';

    const isTeamPlay = listing.team_size > 1;
    const overlayHref = showListing({ listing: listing.id }).url;

    const showReadyCheckBanner =
        listing.lobby_state === 'ready_checking' &&
        listing.lobby_ready_check_deadline !== null;

    return (
        <article className="group relative flex h-full flex-col gap-4 overflow-hidden rounded-2xl border border-border/60 bg-card/60 p-4 transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm md:p-5">
            {/* Overlay: whole card → lobby (team-play) or listing detail (chess) */}
            <Link
                href={overlayHref}
                aria-label={
                    isTeamPlay
                        ? t('Open :name’s lobby', {
                              name: listing.creator.username,
                          })
                        : t('View listing from :name', {
                              name: listing.creator.username,
                          })
                }
                className="absolute inset-0 rounded-2xl focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            />

            {showReadyCheckBanner && (
                <ReadyCheckBanner
                    deadlineIso={listing.lobby_ready_check_deadline as string}
                />
            )}

            {/* Header — game (compound chip when team-play) + platform on the
                left, ends-in on the right */}
            <header className="relative flex items-start justify-between gap-2">
                <div className="flex flex-wrap items-center gap-1.5">
                    <GameChip
                        game={listing.game}
                        teamSize={isTeamPlay ? listing.team_size : undefined}
                    />
                    <VerifiedPlatformChip platform={listing.platform} />
                </div>
                <span
                    className={`inline-flex shrink-0 items-center gap-1 text-xs font-medium ${urgencyTone}`}
                >
                    <Clock className="size-3" aria-hidden="true" />
                    {timeRemaining}
                </span>
            </header>

            {/* Owner zone — relative link sits above the overlay */}
            <Link
                href={userShow({ user: listing.creator.username }).url}
                className="relative flex items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                <Avatar className="size-12 shrink-0 overflow-hidden rounded-full">
                    <AvatarImage
                        src={listing.creator.avatar_thumb_url ?? undefined}
                        alt={listing.creator.username}
                    />
                    <AvatarFallback className="bg-gradient-primary text-sm font-semibold text-primary-foreground">
                        {getInitials(listing.creator.name)}
                    </AvatarFallback>
                </Avatar>
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="truncate text-sm font-semibold text-foreground transition-colors hover:text-primary">
                        {listing.creator.username}
                    </span>
                    <div className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-muted-foreground">
                        {listing.region && (
                            <span className="inline-flex items-center gap-1">
                                <Globe className="size-3" aria-hidden="true" />
                                {listing.region}
                            </span>
                        )}
                        {listing.region &&
                            listing.creator.settled_lifetime > 0 && (
                                <span aria-hidden="true" className="opacity-60">
                                    ·
                                </span>
                            )}
                        <SellerTrustMeta
                            rate={listing.creator.completion_rate_30d}
                            settled={listing.creator.settled_lifetime}
                            verifiedProviders={
                                listing.creator.verified_providers
                            }
                        />
                    </div>
                </div>
            </Link>

            {/* Match meta — skill + (time-controls | languages). Fill counter
                moved out of this row to sit beside the roster avatars. */}
            <div className="pointer-events-none relative flex flex-wrap items-center gap-1.5">
                {listing.game === 'cs2' ? (
                    <>
                        <FaceitRatingBadge
                            rating={listing.creator.faceit_rating}
                            variant="compact"
                        />
                        <RecentFormStrip form={listing.creator.recent_form} />
                    </>
                ) : listing.game === 'chess' ? (
                    <ChessRatingBadge rating={listing.creator.chess_rating} />
                ) : (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Trophy className="size-3" aria-hidden="true" />
                        {formatSkillRange(
                            listing.skill_min,
                            listing.skill_max,
                            t,
                        )}
                    </span>
                )}

                {!isTeamPlay && listing.time_control && (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Clock className="size-3" aria-hidden="true" />
                        {timeControlLabel(listing.time_control, t)}
                    </span>
                )}

                {listing.language && listing.language.length > 0 && (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Languages className="size-3" aria-hidden="true" />
                        {listing.language.slice(0, 2).join(', ')}
                        {listing.language.length > 2 &&
                            ` +${listing.language.length - 2}`}
                    </span>
                )}
            </div>

            {/* Roster preview — avatars + fill counter + names. Team-play only;
                inner guard renders null when no participants have joined. */}
            {isTeamPlay && <RosterPreview listing={listing} />}

            {/* Footer — stake + CTA */}
            <div className="relative mt-auto flex items-end justify-between gap-3 border-t border-border/40 pt-4">
                <div className="flex items-baseline gap-1">
                    <span className="text-gradient-primary font-display text-3xl leading-none font-bold">
                        ${listing.stake_amount}
                    </span>
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>
                <TakeButton listing={listing} />
            </div>
        </article>
    );
}
