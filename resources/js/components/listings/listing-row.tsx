import { Link } from '@inertiajs/react';
import { Clock, Globe, Languages, Trophy } from 'lucide-react';
import { SellerTrustMeta } from '@/components/listings/seller-trust-meta';
import { TakeButton } from '@/components/listings/take-button';
import { VerifiedPlatformChip } from '@/components/listings/verified-platform-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import {
    formatSkillRange,
    formatTimeRemaining,
    getTimeUrgency,
    timeControlLabels,
} from '@/lib/listings-format';
import { show as showListing } from '@/routes/listings';
import { show as userShow } from '@/routes/users';
import type { Listing } from '@/types';

interface Props {
    listing: Listing;
}

/**
 * Marketplace row on `/listings`. Whole row clickable via the absolute-overlay
 * Link pattern (matches `MineListingRow` + the wallet's `ActionCard`):
 *   - Outer `<article relative>` carries hover lift/glow.
 *   - Absolute `inset-0` Link covers the entire row → listing detail.
 *   - Creator zone is a *sibling* Link with `relative` positioning so it
 *     paints above the overlay and intercepts its own clicks → user profile.
 *   - Body content (badges, time, stake) is `pointer-events-none` so clicks
 *     fall through to the overlay.
 *   - Take pill is a visual CTA only, also `pointer-events-none` — the
 *     entire row already navigates to the detail page where the real Take
 *     dialog + balance check live.
 */
export function ListingRow({ listing }: Props) {
    const getInitials = useInitials();
    const timeRemaining = formatTimeRemaining(listing.expires_at);
    const urgency = getTimeUrgency(listing.expires_at);

    // M22 Phase 2 — tiered urgency tone on the time-remaining indicator.
    // Same brand tokens already used elsewhere for warning / destructive.
    const urgencyTone =
        urgency === 'critical'
            ? 'text-destructive'
            : urgency === 'warning'
              ? 'text-warning'
              : 'text-muted-foreground';

    return (
        <article className="group relative flex flex-col gap-4 rounded-2xl border border-border/60 bg-card/60 p-4 transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm md:flex-row md:items-center md:gap-6 md:p-5">
            {/* Overlay: entire row → listing detail */}
            <Link
                href={showListing(listing.id).url}
                aria-label={`View listing from ${listing.creator.name}`}
                className="absolute inset-0 rounded-2xl focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            />

            {/* Creator zone — relative, sits above the overlay → user profile */}
            <Link
                href={userShow(listing.creator.username).url}
                className="relative flex min-w-0 items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none md:w-48 md:shrink-0"
            >
                <Avatar className="size-11 shrink-0 overflow-hidden rounded-full">
                    <AvatarImage
                        src={listing.creator.avatar_thumb_url ?? undefined}
                        alt={listing.creator.name}
                    />
                    <AvatarFallback className="bg-gradient-primary text-sm font-semibold text-primary-foreground">
                        {getInitials(listing.creator.name)}
                    </AvatarFallback>
                </Avatar>

                <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="truncate text-sm font-semibold text-foreground transition-colors hover:text-primary">
                        {listing.creator.name}
                    </span>
                    {/* M22 Phase 1 — meta row under the name. Mirrors
                        Bybit's "503 Order(s) | 91% | 6m" pattern: small gray
                        text combining region + seller-trust inline so Bob's
                        eye lands on "should I trust this seller" right next
                        to the seller's name, not in the badge field. */}
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

            {/* Listing body — no Link wrapper; clicks bubble to overlay */}
            <div className="pointer-events-none relative flex flex-1 flex-wrap items-center gap-3 md:flex-nowrap md:gap-6">
                <div className="flex flex-wrap items-center gap-2 md:flex-1">
                    {/* M22 Phase 1 — platform chip leads the badges row.
                        Seller-trust meta moved up into the creator block
                        under the name (Bybit-style "503 Order(s) | 91%"
                        inline pattern). */}
                    <VerifiedPlatformChip platform={listing.platform} />

                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground">
                        <Trophy className="size-3" aria-hidden="true" />
                        {formatSkillRange(listing.skill_min, listing.skill_max)}
                    </span>

                    {listing.time_control.map((tc) => (
                        <span
                            key={tc}
                            className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground"
                        >
                            <Clock className="size-3" />
                            {timeControlLabels[tc]}
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

                {/* M22 Phase 2 — stake is the row's visual anchor. Bumped
                    from text-2xl → text-3xl so it competes properly with
                    the Take button (matches the featured-card weight). */}
                <div className="flex items-baseline gap-1 md:w-32 md:shrink-0 md:justify-end">
                    <span className="text-gradient-primary font-display text-3xl leading-none font-bold">
                        ${listing.stake_amount}
                    </span>
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>
            </div>

            {/* Take CTA — wrapped in a `relative` div so it sits above the
                row's absolute overlay Link and captures its own clicks.
                `TakeButton` (M22 Phase 3) branches on the viewer's state:
                gradient "Take" when eligible, gradient "Sign in to take"
                for guests (opens auth modal), outline "Link {platform} to
                take" when verified on the wrong provider, or a "Your
                listing" chip + Manage shortcut when the viewer is the
                creator. */}
            <div className="relative flex items-center gap-2 md:shrink-0">
                <TakeButton listing={listing} className="w-full md:w-auto" />
            </div>
        </article>
    );
}
