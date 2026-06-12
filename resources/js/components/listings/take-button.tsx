import { Link, usePage } from '@inertiajs/react';
import { useAuthModal } from '@/components/auth/auth-modal-provider';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { edit as linkedAccountsEdit } from '@/routes/linked-accounts';
import { show as showLobby } from '@/routes/lobbies';
import { mine as listingsMine, show as showListing } from '@/routes/listings';
import type { Listing, ListingPlatform } from '@/types';

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
    // M15 placeholders — CS2/Dota listings are dev-seed only today; the
    // Link-X-to-take CTA they render isn't actionable (no FACEIT/Steam
    // verification flow exists), but it shouldn't crash the chip either.
    faceit: 'FACEIT',
    steam: 'Steam',
};

interface Props {
    listing: Listing;
    className?: string;
}

/**
 * Take CTA for listings marketplace surfaces. Branches: guest → "Sign in to
 * take" (opens modal); owner → "Manage"; authed wrong-platform → "Link
 * {platform}"; eligible → "Take". NOT used by `listings/show.tsx`, which
 * has additional eligibility branches.
 */
export function TakeButton({ listing, className = '' }: Props) {
    const t = useT();
    const { auth } = usePage().props;
    const { openLogin } = useAuthModal();
    const user = auth.user;
    const isTeamPlay = listing.team_size > 1;

    if (!user) {
        return (
            <Button
                type="button"
                variant="gradient"
                size="pill"
                onClick={openLogin}
                className={className}
            >
                {isTeamPlay ? t('Sign in to join') : t('Sign in to take')}
            </Button>
        );
    }

    // Owner of a team-play listing routes to the lobby (where coordination
    // + cancel-via-leave live) instead of `/listings/mine`. The chess-style
    // "Manage" affordance still applies to 1v1 listings.
    if (user.id === listing.creator.id) {
        if (isTeamPlay) {
            return (
                <Button variant="gradient" size="pill" asChild className={className}>
                    <Link href={showLobby({ listing: listing.id }).url}>
                        {t('View lobby')}
                    </Link>
                </Button>
            );
        }

        return (
            <Button
                variant="outline"
                size="pill"
                asChild
                className={`rounded-full ${className}`.trim()}
            >
                <Link href={listingsMine().url}>{t('Manage')}</Link>
            </Button>
        );
    }

    // `linked_platforms` is chess-only by design (FACEIT/Steam linking is
    // M15 work). For CS2/Dota listings the `.includes()` always returns
    // false → the chip renders the (non-actionable) "Link FACEIT to take"
    // CTA, which is correct: these listings aren't takeable today. The
    // widening cast is purely to satisfy TS — runtime semantics are
    // identical (a chess-only array can't contain `faceit`/`steam`).
    if (
        !(user.linked_platforms as readonly ListingPlatform[]).includes(
            listing.platform,
        )
    ) {
        return (
            <Button
                variant="outline"
                size="pill"
                asChild
                className={`rounded-full ${className}`.trim()}
            >
                <Link href={linkedAccountsEdit().url}>
                    {t('Link :platform', {
                        platform: PLATFORM_LABEL[listing.platform],
                    })}
                </Link>
            </Button>
        );
    }

    if (isTeamPlay) {
        return (
            <Button variant="gradient" size="pill" asChild className={className}>
                <Link href={showLobby({ listing: listing.id }).url}>
                    {t('View lobby')}
                </Link>
            </Button>
        );
    }

    return (
        <Button variant="gradient" size="pill" asChild className={className}>
            <Link href={showListing({ listing: listing.id }).url}>
                {t('Take')}
            </Link>
        </Button>
    );
}
