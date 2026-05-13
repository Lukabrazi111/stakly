import { Link, router } from '@inertiajs/react';
import { LogOut, Settings, User as UserIcon, Wallet } from 'lucide-react';
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
        <span className="bg-background/80 text-muted-foreground ml-auto rounded-full px-2 py-0.5 text-[10px] tracking-wide uppercase backdrop-blur">
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
                    className="hover:shadow-glow-sm focus-visible:shadow-glow-sm size-10 rounded-full p-0 transition-shadow duration-200 ease-out"
                    aria-label="Open account menu"
                >
                    <Avatar className="size-9 overflow-hidden rounded-full">
                        <AvatarImage src={user.avatar} alt={user.name} />
                        <AvatarFallback className="bg-gradient-primary text-primary-foreground text-sm font-semibold">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="border-border/60 bg-card/95 w-60 rounded-xl p-1.5 shadow-[0_8px_32px_-12px_rgba(0,0,0,0.7),0_0_36px_-16px_var(--gradient-glow)] backdrop-blur-md"
                align="end"
                sideOffset={8}
            >
                <DropdownMenuLabel className="p-0 font-normal">
                    <div className="flex flex-col gap-0.5 px-2.5 py-2">
                        <span className="text-foreground truncate text-sm font-medium">
                            {user.name}
                        </span>
                        <span className="text-muted-foreground truncate text-xs">
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
                        className="text-muted-foreground hover:text-destructive focus:text-destructive hover:bg-destructive/10 focus:bg-destructive/10 hover:[&_svg]:!text-destructive focus:[&_svg]:!text-destructive flex w-full cursor-pointer items-center rounded-md px-2.5 py-2 text-sm transition-colors duration-150 ease-out"
                    >
                        <LogOut className="mr-2 size-4" />
                        Log out
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
