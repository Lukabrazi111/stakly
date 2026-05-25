import { Link, router } from '@inertiajs/react';
import {
    ListChecks,
    LogOut,
    Settings,
    Swords,
    User as UserIcon,
    Wallet,
} from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import { logout } from '@/routes';
import { mine as listingsMine } from '@/routes/listings';
import { index as matchesIndex } from '@/routes/matches';
import { edit as editProfile } from '@/routes/profile';
import { index as walletIndex } from '@/routes/wallet';
import type { User } from '@/types/auth';

interface Props {
    user: User;
}

const menuItemClass =
    'text-muted-foreground hover:text-foreground focus:text-foreground hover:bg-primary/10 focus:bg-primary/10 hover:[&_svg]:!text-primary focus:[&_svg]:!text-primary flex w-full cursor-pointer items-center rounded-md px-2.5 py-2 text-sm transition-colors duration-150 ease-out';

const disabledMenuItemClass =
    'text-muted-foreground/60 flex w-full items-center rounded-md px-2.5 py-2 text-sm';

function SoonBadge() {
    return (
        <span className="ml-auto rounded-full bg-background/80 px-2 py-0.5 text-[10px] tracking-wide text-muted-foreground uppercase backdrop-blur">
            Soon
        </span>
    );
}

export function ProfileMenu({ user }: Props) {
    const getInitials = useInitials();

    const handleLogout = () => {
        router.flushAll();
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    // Trigger acts as `group` so the inner Avatar can react to
                    // hover/open states from the parent. The Button itself
                    // carries a soft pink halo (`shadow-glow`); the Avatar gets
                    // a 2px pink ring inside the button bounds — no layout shift
                    // since size-9 inside size-10 leaves 2px of breathing room.
                    // No `focus-visible:` on the Avatar ring on purpose: after
                    // closing the dropdown Radix returns focus to the trigger,
                    // and focus-visible would keep the ring stuck around. The
                    // Button source already carries a focus-visible ring at the
                    // wrapper level for keyboard a11y.
                    className="group size-10 rounded-full p-0 transition-shadow duration-200 ease-out hover:shadow-glow data-[state=open]:shadow-glow"
                    aria-label="Open account menu"
                >
                    <Avatar className="size-9 overflow-hidden rounded-full ring-0 transition-all duration-200 ease-out group-hover:ring-2 group-hover:ring-primary/50 group-data-[state=open]:ring-2 group-data-[state=open]:ring-primary/50">
                        <AvatarImage
                            src={user.avatar_thumb_url ?? undefined}
                            alt={user.name}
                        />
                        <AvatarFallback className="bg-gradient-primary text-sm font-semibold text-primary-foreground">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                // DropdownMenuContent acts as a transparent positioning wrapper
                // here (Radix handles its placement). The ACTUAL styled menu
                // box is the inner div below; the ambient layer sits as a
                // sibling BEFORE the styled box so painting order matches the
                // auth modal: ambient paints first, then the bg-card menu box
                // covers it where they overlap. Only the outer halo (beyond
                // the menu's bounds) is visible — true "behind" glow.
                className="relative !w-auto !overflow-visible !rounded-none !border-0 !bg-transparent !p-0 !shadow-none !backdrop-blur-none"
                align="end"
                sideOffset={8}
            >
                {/* Ambient pink atmosphere — same radial-gradient + blur
                    pattern as the auth modal, scaled down for a dropdown.
                    Centered on the menu, 460px wide (same as the modal),
                    100px blur. Painted before the menu box → only the halo
                    OUTSIDE the menu's edges is visible. */}
                <div
                    aria-hidden
                    className="pointer-events-none absolute top-1/2 left-1/2 size-[460px] -translate-x-1/2 -translate-y-1/2 rounded-full blur-[100px]"
                    style={{
                        background:
                            'radial-gradient(circle, color-mix(in srgb, var(--gradient-glow) 22%, transparent) 0%, transparent 65%)',
                    }}
                />

                {/* Actual styled menu box — sits on top of the ambient layer,
                    its bg-card covers the inner part of the glow, leaving only
                    the outer halo visible. Border-glow gives the pink border +
                    tight inner ring, same as the auth modal. */}
                <div className="relative w-60 rounded-xl border border-glow bg-card p-1.5 backdrop-blur-md">
                    <DropdownMenuLabel className="p-0 font-normal">
                        <div className="flex flex-col gap-0.5 px-2.5 py-2">
                            <span className="truncate text-sm font-medium text-foreground">
                                {user.name}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">
                                {user.email}
                            </span>
                        </div>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator className="bg-border/60" />
                    <DropdownMenuGroup className="py-1">
                        <DropdownMenuItem
                            disabled
                            className={disabledMenuItemClass}
                        >
                            <UserIcon className="mr-2 size-4" />
                            <span>My profile</span>
                            <SoonBadge />
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link
                                href={listingsMine().url}
                                prefetch
                                className={menuItemClass}
                            >
                                <ListChecks className="mr-2 size-4" />
                                My listings
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link
                                href={matchesIndex().url}
                                prefetch
                                className={menuItemClass}
                            >
                                <Swords className="mr-2 size-4" />
                                Matches
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link
                                href={walletIndex().url}
                                prefetch
                                className={menuItemClass}
                            >
                                <Wallet className="mr-2 size-4" />
                                Wallet
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link
                                href={editProfile()}
                                prefetch
                                className={menuItemClass}
                            >
                                <Settings className="mr-2 size-4" />
                                Settings
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                    <DropdownMenuSeparator className="bg-border/60" />
                    <DropdownMenuItem asChild>
                        <Link
                            href={logout()}
                            method="post"
                            as="button"
                            onClick={handleLogout}
                            className="flex w-full cursor-pointer items-center rounded-md px-2.5 py-2 text-sm text-muted-foreground transition-colors duration-150 ease-out hover:bg-destructive/10 hover:text-destructive focus:bg-destructive/10 focus:text-destructive hover:[&_svg]:!text-destructive focus:[&_svg]:!text-destructive"
                        >
                            <LogOut className="mr-2 size-4" />
                            Log out
                        </Link>
                    </DropdownMenuItem>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
