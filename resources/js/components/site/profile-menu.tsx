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
import { show as userShow } from '@/routes/users';
import { index as walletIndex } from '@/routes/wallet';
import type { User } from '@/types/auth';

interface Props {
    user: User;
}

const menuItemClass =
    'text-muted-foreground hover:text-foreground focus:text-foreground hover:bg-primary/10 focus:bg-primary/10 hover:[&_svg]:!text-primary focus:[&_svg]:!text-primary flex w-full cursor-pointer items-center rounded-md px-2.5 py-2 text-sm transition-colors duration-150 ease-out';

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
                    // No `focus-visible:` on the Avatar ring — Radix returns
                    // focus to the trigger after close, which would keep
                    // the ring stuck on.
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
                className="w-60 rounded-xl border-border/60 bg-card/95 p-1.5 backdrop-blur-md"
                align="end"
                sideOffset={8}
            >
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
                    <DropdownMenuItem asChild>
                        <Link
                            href={userShow({ user: user.username }).url}
                            prefetch
                            className={menuItemClass}
                        >
                            <UserIcon className="mr-2 size-4" />
                            My profile
                        </Link>
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
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
