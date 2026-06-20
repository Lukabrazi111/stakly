import { Link, usePage } from '@inertiajs/react';
import { History, ListChecks, MoreHorizontal, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useT } from '@/lib/i18n';
import {
    create as listingsCreate,
    mine as listingsMine,
} from '@/routes/listings';
import { index as matchesIndex } from '@/routes/matches';

/** Page-local "More" dropdown for the /listings header, authed users only. */
export function ListingsMoreMenu() {
    const t = useT();
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
                    // Bordered-ghost matching the Filters button.
                    className="border border-border/60 hover:border-primary/40 hover:bg-primary/10 hover:[text-shadow:none] data-[state=open]:border-primary/40 data-[state=open]:bg-primary/10 max-md:px-4"
                    aria-label={t('Open more actions menu')}
                >
                    <MoreHorizontal className="size-4" />
                    <span className="hidden md:inline">{t('More')}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                sideOffset={6}
                className="w-52 rounded-xl border-border/60 bg-card/95 p-1.5 backdrop-blur-md"
            >
                <DropdownMenuItem asChild>
                    <Link href={listingsCreate().url} prefetch>
                        <Plus className="size-4" />
                        {t('Post listing')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={listingsMine().url} prefetch>
                        <ListChecks className="size-4" />
                        {t('My listings')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={matchesIndex().url} prefetch>
                        <History className="size-4" />
                        {t('Match history')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
