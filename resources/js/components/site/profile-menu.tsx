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
import type { User } from '@/types/auth';

interface Props {
    user: User;
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
                className="border-border/60 bg-card/95 w-56 rounded-xl shadow-[0_8px_32px_-12px_rgba(0,0,0,0.7),0_0_36px_-16px_var(--gradient-glow)] backdrop-blur-md"
                align="end"
                sideOffset={8}
            >
                <DropdownMenuLabel className="p-0 font-normal">
                    <div className="flex flex-col gap-0.5 px-2 py-2">
                        <span className="text-foreground truncate text-sm font-medium">
                            {user.name}
                        </span>
                        <span className="text-muted-foreground truncate text-xs">
                            {user.email}
                        </span>
                    </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuGroup>
                    <DropdownMenuItem asChild>
                        <Link
                            href="#"
                            className="cursor-pointer rounded-md text-sm"
                        >
                            <UserIcon className="mr-2 size-4" />
                            My profile
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link
                            href="#"
                            className="cursor-pointer rounded-md text-sm"
                        >
                            <Wallet className="mr-2 size-4" />
                            Wallet
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link
                            href={editProfile()}
                            prefetch
                            className="cursor-pointer rounded-md text-sm"
                        >
                            <Settings className="mr-2 size-4" />
                            Settings
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link
                        href={logout()}
                        method="post"
                        as="button"
                        onClick={handleLogout}
                        className="text-destructive focus:text-destructive cursor-pointer rounded-md text-sm"
                    >
                        <LogOut className="mr-2 size-4" />
                        Log out
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
