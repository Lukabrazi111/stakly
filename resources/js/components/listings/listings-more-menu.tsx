import { Link, usePage } from '@inertiajs/react';
import { History, ListChecks, MoreHorizontal, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { create as listingsCreate, mine as listingsMine } from '@/routes/listings';
import { index as matchesIndex } from '@/routes/matches';

/**
 * Page-local "More" dropdown for the /listings index page header. Surfaces
 * three shortcuts that live elsewhere but are useful while browsing the
 * board: post a listing, jump to your management dashboard, jump to match
 * history.
 *
 * Visibility: authenticated users only. Logged-out visitors keep the page
 * uncluttered — the global SiteHeader's gradient "Create listing" CTA
 * already covers the conversion case for them.
 *
 * Not global: this dropdown is intentionally only on /listings, not on
 * the rest of the app — the audience here is the management audience.
 * Other pages (homepage, profile, wallet) don't need it.
 */
export function ListingsMoreMenu() {
    const { auth } = usePage().props;

    if (!auth.user) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="pill"
                    // Mobile = icon-only to keep the action row uncluttered next
                    // to Sort + Filters. Bordered-ghost hover matches Filters:
                    // pink wash + border highlight, no text-shadow noise.
                    // `data-[state=open]` keeps the highlight on while the
                    // dropdown is open so users see which trigger owns it.
                    className="border-border/60 hover:bg-primary/10 hover:border-primary/40 hover:[text-shadow:none] data-[state=open]:bg-primary/10 data-[state=open]:border-primary/40 border max-md:px-4"
                    aria-label="Open more actions menu"
                >
                    <MoreHorizontal className="size-4" />
                    <span className="hidden md:inline">More</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                sideOffset={6}
                className="border-border/60 bg-card/95 w-52 rounded-xl p-1.5 backdrop-blur-md"
            >
                <DropdownMenuItem asChild>
                    <Link href={listingsCreate().url} prefetch>
                        <Plus className="size-4" />
                        Post listing
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={listingsMine().url} prefetch>
                        <ListChecks className="size-4" />
                        My listings
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={matchesIndex().url} prefetch>
                        <History className="size-4" />
                        Match history
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
