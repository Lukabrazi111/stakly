import { Link } from '@inertiajs/react';
import { Clock, Globe, Languages, Trophy } from 'lucide-react';
import { FaceitRatingBadge } from '@/components/listings/faceit-rating-badge';
import { GameChip } from '@/components/listings/game-chip';
import { ReadyCheckBanner } from '@/components/listings/ready-check-banner';
import { SellerTrustMeta } from '@/components/listings/seller-trust-meta';
import { TakeButton } from '@/components/listings/take-button';
import { LobbyFillCounter } from '@/components/listings/team-play-meta';
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
 * Marketplace row on `/listings`. Outer `<article>` is `flex-col` so the
 * optional `ReadyCheckBanner` can sit flush at the top; the inner wrapper
 * handles the desktop `md:flex-row` layout (creator | match info | CTA).
 * Absolute-overlay `<Link>` covers the whole article → listing detail
 * (chess) or lobby (team-play); nested `<Link>` for the creator zone uses
 * `relative` to intercept its own clicks → user profile.
 */
export function ListingRow({ listing }: Props) {
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
        <article className="group relative flex flex-col overflow-hidden rounded-2xl border border-border/60 bg-card/60 p-4 transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm md:p-5">
            {/* Overlay: entire row → listing detail (chess) or lobby (team-play) */}
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

            <div className="flex flex-col gap-4 md:flex-row md:items-center md:gap-6">
                {/* Creator zone — relative, sits above the overlay → user profile */}
                <Link
                    href={userShow({ user: listing.creator.username }).url}
                    className="relative flex min-w-0 items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none md:w-48 md:shrink-0"
                >
                    <Avatar className="size-11 shrink-0 overflow-hidden rounded-full">
                        <AvatarImage
                            src={listing.creator.avatar_thumb_url ?? undefined}
                            alt={listing.creator.username}
                        />
                        <AvatarFallback className="bg-gradient-primary text-sm font-semibold text-primary-foreground">
                            {getInitials(listing.creator.name)}
                        </AvatarFallback>
                    </Avatar>

                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="truncate text-sm font-semibold text-foreground transition-colors hover:text-primary">
                            {listing.creator.username}
                        </span>
                        <div className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-muted-foreground">
                            {listing.region && (
                                <span className="inline-flex items-center gap-1">
                                    <Globe
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                    {listing.region}
                                </span>
                            )}
                            {listing.region &&
                                listing.creator.settled_lifetime > 0 && (
                                    <span
                                        aria-hidden="true"
                                        className="opacity-60"
                                    >
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

                <div className="pointer-events-none relative flex flex-1 flex-wrap items-center gap-3 md:flex-nowrap md:gap-6">
                    <div className="flex flex-wrap items-center gap-2 md:flex-1">
                        <GameChip
                            game={listing.game}
                            teamSize={
                                isTeamPlay ? listing.team_size : undefined
                            }
                        />

                        <VerifiedPlatformChip platform={listing.platform} />

                        {listing.game === 'cs2' ? (
                            <FaceitRatingBadge
                                rating={listing.creator.faceit_rating}
                                variant="compact"
                            />
                        ) : (
                            <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground">
                                <Trophy className="size-3" aria-hidden="true" />
                                {formatSkillRange(
                                    listing.skill_min,
                                    listing.skill_max,
                                    t,
                                )}
                            </span>
                        )}

                        {listing.time_control && (
                            <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground">
                                <Clock className="size-3" />
                                {timeControlLabel(listing.time_control, t)}
                            </span>
                        )}

                        {listing.language && listing.language.length > 0 && (
                            <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground">
                                <Languages className="size-3" />
                                {listing.language.slice(0, 3).join(', ')}
                                {listing.language.length > 3 &&
                                    ` +${listing.language.length - 3}`}
                            </span>
                        )}
                    </div>

                    {isTeamPlay && (
                        <div className="flex items-center md:w-20 md:shrink-0 md:justify-end">
                            <LobbyFillCounter listing={listing} />
                        </div>
                    )}

                    <div
                        className={`inline-flex items-center gap-1.5 text-xs font-medium md:w-28 md:shrink-0 md:justify-end ${urgencyTone}`}
                    >
                        <Clock className="size-3.5" aria-hidden="true" />
                        {timeRemaining}
                    </div>

                    <div className="flex items-baseline gap-1 md:w-32 md:shrink-0 md:justify-end">
                        <span className="text-gradient-primary font-display text-3xl leading-none font-bold">
                            ${listing.stake_amount}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            USDT
                        </span>
                    </div>
                </div>

                {/* Fixed `md:w-44` so the desktop column header strip in
                    `pages/listings/index.tsx` aligns — "Ends in" / "Stake" labels
                    stay over their data columns instead of drifting right. Width
                    accommodates the longest CTA text ("Link chess.com"). */}
                <div className="relative flex items-center justify-end gap-2 md:w-44 md:shrink-0">
                    <TakeButton
                        listing={listing}
                        className="w-full md:w-auto"
                    />
                </div>
            </div>
        </article>
    );
}
