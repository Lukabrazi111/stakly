import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { useAuthModal } from '@/components/auth/auth-modal-provider';
import { BalanceChip } from '@/components/site/balance-chip';
import { MobileMenu } from '@/components/site/mobile-menu';
import { ProfileMenu } from '@/components/site/profile-menu';
import { UnverifiedChip } from '@/components/site/unverified-chip';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    create as listingsCreate,
    index as listingsIndex,
} from '@/routes/listings';

export function SiteHeader() {
    const { openLogin, openRegister } = useAuthModal();
    const { auth } = usePage().props;
    const user = auth.user;
    const isUnverified = Boolean(user && !user.email_verified_at);

    return (
        <header className="border-border/50 bg-background/80 sticky top-0 z-50 w-full border-b backdrop-blur-lg">
            <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-6 px-4">
                <Link
                    href="/"
                    aria-label="Stakly home"
                    className="text-gradient-primary font-display rounded-md text-2xl font-bold tracking-tight focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                >
                    stakly
                </Link>

                <div className="hidden max-w-md flex-1 md:block">
                    <div className="relative">
                        <Search className="text-muted-foreground absolute top-1/2 left-4 size-4 -translate-y-1/2" />
                        <input
                            type="search"
                            placeholder="Search listings, players, games..."
                            aria-label="Search"
                            className="border-border bg-card text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring focus-visible:shadow-glow-sm h-10 w-full rounded-full border pr-4 pl-11 text-sm transition-shadow duration-200 ease-out focus-visible:ring-1 focus-visible:outline-none"
                        />
                    </div>
                </div>

                <nav className="hidden items-center gap-1 md:flex">
                    <Link
                        href={listingsIndex()}
                        className="text-foreground hover:text-primary px-3 py-2 text-sm transition-colors"
                    >
                        Listings
                    </Link>
                    <Link
                        href="#"
                        className="text-foreground hover:text-primary px-3 py-2 text-sm transition-colors"
                    >
                        How it Works
                    </Link>
                    <Link
                        href="#"
                        className="text-foreground hover:text-primary px-3 py-2 text-sm transition-colors"
                    >
                        Support
                    </Link>

                    {user && (
                        <div className="ml-2">
                            <CreateListingCTA isUnverified={isUnverified} />
                        </div>
                    )}

                    <div className="ml-3 flex items-center gap-3">
                        {user ? (
                            <>
                                {isUnverified && <UnverifiedChip />}
                                <BalanceChip balance={user.usdt_balance} />
                                <ProfileMenu user={user} />
                            </>
                        ) : (
                            <>
                                <Button
                                    variant="ghost"
                                    size="default"
                                    onClick={openLogin}
                                >
                                    Sign in
                                </Button>
                                <Button
                                    variant="gradient"
                                    size="pill"
                                    onClick={openRegister}
                                >
                                    Sign up
                                </Button>
                            </>
                        )}
                    </div>
                </nav>

                <MobileMenu />
            </div>
        </header>
    );
}

interface CreateListingCTAProps {
    isUnverified: boolean;
}

/**
 * Two states (logged-out users don't see this — Sign up covers that funnel):
 *   - Unverified → disabled with a tooltip pointing at the verification chip.
 *   - Verified   → links to `/listings/create`.
 */
function CreateListingCTA({ isUnverified }: CreateListingCTAProps): ReactNode {
    if (isUnverified) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    {/* Span wrapper because disabled buttons don't fire pointer
                        events — the tooltip needs the parent to listen. */}
                    <span tabIndex={0}>
                        <Button
                            variant="gradient"
                            size="default"
                            disabled
                            className="pointer-events-none"
                        >
                            Create listing
                        </Button>
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    Verify your email to create listings.
                </TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Button variant="gradient" size="default" asChild>
            <Link href={listingsCreate().url}>Create listing</Link>
        </Button>
    );
}
