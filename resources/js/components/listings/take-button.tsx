import { Link, usePage } from '@inertiajs/react';
import { useAuthModal } from '@/components/auth/auth-modal-provider';
import { Button } from '@/components/ui/button';
import { edit as linkedAccountsEdit } from '@/routes/linked-accounts';
import { mine as listingsMine, show as showListing } from '@/routes/listings';
import type { Listing, ListingPlatform } from '@/types';

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
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
    const { auth } = usePage().props;
    const { openLogin } = useAuthModal();
    const user = auth.user;

    if (!user) {
        return (
            <Button
                type="button"
                variant="gradient"
                size="pill"
                onClick={openLogin}
                className={className}
            >
                Sign in to take
            </Button>
        );
    }

    if (user.id === listing.creator.id) {
        return (
            <Button
                variant="outline"
                size="pill"
                asChild
                className={`rounded-full ${className}`.trim()}
            >
                <Link href={listingsMine().url}>Manage</Link>
            </Button>
        );
    }

    if (!user.linked_platforms.includes(listing.platform)) {
        return (
            <Button
                variant="outline"
                size="pill"
                asChild
                className={`rounded-full ${className}`.trim()}
            >
                <Link href={linkedAccountsEdit().url}>
                    Link {PLATFORM_LABEL[listing.platform]}
                </Link>
            </Button>
        );
    }

    return (
        <Button variant="gradient" size="pill" asChild className={className}>
            <Link href={showListing({ listing: listing.id }).url}>Take</Link>
        </Button>
    );
}
