import { Link, router, usePage } from '@inertiajs/react';
import {
    ListChecks,
    PanelLeftClose,
    PanelLeftOpen,
    Swords,
    User as UserIcon,
    Wallet as WalletIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { mine as listingsMine } from '@/routes/listings';
import { index as matchesIndex } from '@/routes/matches';
import { show as userShow } from '@/routes/users';
import { index as walletIndex } from '@/routes/wallet';

interface NavItem {
    href: string;
    label: string;
    icon: LucideIcon;
    /** URL prefix that activates this item. Sourced from the Wayfinder
     *  generator so the active locale prefix is included; `startsWith`
     *  then activates the parent across the whole subsection (eg. wallet
     *  stays lit on `/{locale}/wallet/deposit` + `/withdraw` + `/history`). */
    matchPrefix: string;
}

const COOKIE_NAME = 'player_sidebar_collapsed';
const COOKIE_MAX_AGE = 60 * 60 * 24 * 365;

/**
 * Side navigation for the player hub. Sticky at `top-28` (just below
 * SiteHeader + MarqueeStrip). Collapsible rail mode persists in a cookie
 * shared via Inertia (`playerSidebarCollapsed`) so SSR + first paint +
 * every subsequent navigation render the user's saved width — no
 * post-mount transition from default → saved state on nav clicks.
 */
export function PlayerSidebar() {
    const { url, props } = usePage();
    const username = props.auth.user?.username;
    const [collapsed, setCollapsed] = useState(props.playerSidebarCollapsed);

    const toggleCollapsed = () => {
        setCollapsed((current) => {
            const next = !current;
            document.cookie = `${COOKIE_NAME}=${next}; max-age=${COOKIE_MAX_AGE}; path=/; samesite=lax`;
            // Sidebar Links carry `prefetch`, which caches the full response
            // (incl. shared props) for 30s. Without this flush, a toggle
            // followed by a nav click within 30s would re-mount with the
            // stale `playerSidebarCollapsed` from before the toggle.
            router.flushAll();

            return next;
        });
    };

    // Profile is owner-specific so it's prepended only when authed.
    const items: NavItem[] = [
        ...(username
            ? [
                  {
                      href: userShow({ user: username }).url,
                      label: 'My profile',
                      icon: UserIcon,
                      matchPrefix: userShow({ user: username }).url,
                  },
              ]
            : []),
        {
            href: listingsMine().url,
            label: 'My listings',
            icon: ListChecks,
            matchPrefix: listingsMine().url,
        },
        {
            href: matchesIndex().url,
            label: 'Matches',
            icon: Swords,
            matchPrefix: matchesIndex().url,
        },
        {
            href: walletIndex().url,
            label: 'Wallet',
            icon: WalletIcon,
            matchPrefix: walletIndex().url,
        },
    ];

    return (
        <aside
            aria-label="Player management navigation"
            className={`sticky top-28 hidden h-[calc(100vh-7rem)] shrink-0 self-start border-r border-border/60 bg-card/40 transition-[width] duration-200 ease-out md:flex md:flex-col ${
                collapsed ? 'md:w-16' : 'md:w-60'
            }`}
        >
            <div
                className={`flex shrink-0 items-center p-3 ${collapsed ? 'justify-center' : 'justify-start'}`}
            >
                <button
                    type="button"
                    onClick={toggleCollapsed}
                    aria-label={
                        collapsed ? 'Expand sidebar' : 'Collapse sidebar'
                    }
                    aria-expanded={!collapsed}
                    className="inline-flex size-9 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 ease-out hover:bg-primary/10 hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    {collapsed ? (
                        <PanelLeftOpen className="size-4" />
                    ) : (
                        <PanelLeftClose className="size-4" />
                    )}
                </button>
            </div>

            <TooltipProvider delayDuration={300}>
                <nav className="flex flex-col gap-1 px-3">
                    {items.map((item) => {
                        const isActive = url.startsWith(item.matchPrefix);
                        const Icon = item.icon;

                        const link = (
                            <Link
                                href={item.href}
                                prefetch
                                aria-current={isActive ? 'page' : undefined}
                                className={`group relative flex items-center gap-3 rounded-md py-2.5 text-sm font-medium transition-colors duration-150 ease-out ${
                                    collapsed ? 'justify-center px-0' : 'px-3'
                                } ${
                                    isActive
                                        ? 'bg-primary/15 text-foreground'
                                        : 'text-muted-foreground hover:bg-primary/10 hover:text-foreground'
                                }`}
                            >
                                {isActive && (
                                    <span
                                        aria-hidden
                                        className="absolute top-1/2 left-0 h-6 w-1 -translate-y-1/2 rounded-r-full bg-primary shadow-[0_0_10px_-1px_var(--gradient-glow)]"
                                    />
                                )}
                                <Icon
                                    className={`size-4 shrink-0 transition-colors duration-150 ease-out ${
                                        isActive
                                            ? 'text-primary'
                                            : 'group-hover:text-primary'
                                    }`}
                                />
                                {!collapsed && <span>{item.label}</span>}
                            </Link>
                        );

                        if (collapsed) {
                            return (
                                <Tooltip key={item.href}>
                                    <TooltipTrigger asChild>
                                        {link}
                                    </TooltipTrigger>
                                    <TooltipContent side="right" sideOffset={8}>
                                        {item.label}
                                    </TooltipContent>
                                </Tooltip>
                            );
                        }

                        return <Fragment key={item.href}>{link}</Fragment>;
                    })}
                </nav>
            </TooltipProvider>
        </aside>
    );
}
