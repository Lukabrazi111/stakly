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
    /**
     * Applied to the outer rendered element. The Take CTA's shape varies
     * by branch (gradient pill, outline pill, or owner chip + Manage row),
     * so the layout class lives at the call site (e.g. `w-full md:w-auto`
     * in the row, `relative mt-auto w-full` in the featured card).
     */
    className?: string;
}

/**
 * Take CTA for the listings marketplace surfaces — `listing-row.tsx` +
 * `listing-card.tsx` (M22 Phase 3). Telegraphs eligibility at scan time
 * so the viewer doesn't waste clicks discovering a gate on the detail page.
 *
 * Four branches:
 *   - **Guest viewer** — gradient pill "Sign in to take" that opens the
 *     auth modal in place (no full-page nav).
 *   - **Owner** of the listing — outline pill "Manage" linking to
 *     /listings/mine. The action label is enough; no explicit "Your
 *     listing" label needed since the owner already knows.
 *   - **Authed but wrong-platform-verified** — outline pill "Link
 *     {platform}" linking to /settings/linked-accounts.
 *   - **Eligible** — gradient pill "Take" linking to the listing detail
 *     page where the full Take dialog + balance check lives.
 *
 * NOT used by `listings/show.tsx` — the detail page has additional
 * eligibility branches (balance check, owner-inactive, confirm dialog)
 * that don't compress into a single shared component.
 */
export function TakeButton({ listing, className = '' }: Props) {
    const { auth } = usePage().props;
    const { openLogin } = useAuthModal();
    const user = auth.user;

    // Guest first — early-return narrows `user` to non-null for the rest.
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
        // Outline pill at the same size as the Take button so the owner's
        // row column matches the width + height of ordinary-Take rows.
        // The owner doesn't need a "Your listing" label — they already
        // know; the action button is enough.
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
        // Just "Link {platform}", not "Link {platform} to take" — the
        // platform chip already carries the "match plays on X" signal,
        // and the row + card right-column width reads cleaner when the
        // label is short. Goal is implicit (clicking this button takes
        // you to /settings/linked-accounts).
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
            <Link href={showListing(listing.id).url}>Take</Link>
        </Button>
    );
}
