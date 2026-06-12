import { Link } from '@inertiajs/react';
import { Clock, Globe, Languages, Trophy } from 'lucide-react';
import { GameChip } from '@/components/listings/game-chip';
import { SellerTrustMeta } from '@/components/listings/seller-trust-meta';
import { TakeButton } from '@/components/listings/take-button';
import {
    LobbyFillCounter,
    LobbyStateBadge,
    TeamSizeBadge,
} from '@/components/listings/team-play-meta';
import { VerifiedPlatformChip } from '@/components/listings/verified-platform-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import {
    formatSkillRange,
    formatTimeRemaining,
    getTimeUrgency,
    timeControlLabels,
} from '@/lib/listings-format';
import { show as showLobby } from '@/routes/lobbies';
import { show as showListing } from '@/routes/listings';
import { show as userShow } from '@/routes/users';
import type { Listing } from '@/types';

interface Props {
    listing: Listing;
}

/**
 * Marketplace row on `/listings`. Uses the absolute-overlay Link pattern:
 * outer `<article>` is relative, an `absolute inset-0` Link covers it →
 * detail page, creator zone is a sibling Link with `relative` to intercept
 * its own clicks → profile, body content uses `pointer-events-none` so
 * clicks fall through.
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
    const overlayHref = isTeamPlay
        ? showLobby({ listing: listing.id }).url
        : showListing({ listing: listing.id }).url;

    return (
        <article className="group relative flex flex-col gap-4 rounded-2xl border border-border/60 bg-card/60 p-4 transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm md:flex-row md:items-center md:gap-6 md:p-5">
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

            <div className="pointer-events-none relative flex flex-1 flex-wrap items-center gap-3 md:flex-nowrap md:gap-6">
                <div className="flex flex-wrap items-center gap-2 md:flex-1">
                    <GameChip game={listing.game} />

                    <TeamSizeBadge teamSize={listing.team_size} />

                    <VerifiedPlatformChip platform={listing.platform} />

                    <LobbyStateBadge state={listing.lobby_state} />

                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground">
                        <Trophy className="size-3" aria-hidden="true" />
                        {formatSkillRange(
                            listing.skill_min,
                            listing.skill_max,
                            t,
                        )}
                    </span>

                    {isTeamPlay && <LobbyFillCounter listing={listing} />}

                    {listing.time_control.map((tc) => (
                        <span
                            key={tc}
                            className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground"
                        >
                            <Clock className="size-3" />
                            {t(timeControlLabels[tc])}
                        </span>
                    ))}

                    {listing.language && listing.language.length > 0 && (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground">
                            <Languages className="size-3" />
                            {listing.language.slice(0, 3).join(', ')}
                            {listing.language.length > 3 &&
                                ` +${listing.language.length - 3}`}
                        </span>
                    )}
                </div>

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
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>
            </div>

            {/* Fixed `md:w-44` so the desktop column header strip in
                `pages/listings/index.tsx` aligns — "Ends in" / "Stake" labels
                stay over their data columns instead of drifting right. Width
                accommodates the longest CTA text ("Link chess.com"). */}
            <div className="relative flex items-center justify-end gap-2 md:w-44 md:shrink-0">
                <TakeButton listing={listing} className="w-full md:w-auto" />
            </div>
        </article>
    );
}
