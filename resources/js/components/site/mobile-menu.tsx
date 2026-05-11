import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Search, Settings, User as UserIcon, Wallet, X } from 'lucide-react';
import { useState } from 'react';
import { useAuthModal } from '@/components/auth/auth-modal-provider';
import { UnverifiedChip } from '@/components/site/unverified-chip';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useInitials } from '@/hooks/use-initials';
import { home, logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

interface NavLink {
    label: string;
    href: string;
}

const navLinks: NavLink[] = [
    { label: 'Listings', href: '#' },
    { label: 'How it Works', href: '#how-it-works' },
    { label: 'Support', href: '#' },
];

export function MobileMenu() {
    const [open, setOpen] = useState(false);
    const { openLogin, openRegister } = useAuthModal();
    const { auth } = usePage().props;
    const user = auth.user;
    const isUnverified = Boolean(user && !user.email_verified_at);
    const getInitials = useInitials();

    const triggerAuth = (action: () => void) => {
        setOpen(false);
        window.setTimeout(action, 220);
    };

    const handleLogout = () => {
        router.flushAll();
    };

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <button
                    type="button"
                    aria-label="Open menu"
                    className="group focus-visible:ring-primary focus-visible:ring-offset-background relative inline-flex size-10 cursor-pointer items-center justify-center rounded-full transition-shadow duration-200 ease-out hover:shadow-glow-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none md:hidden"
                >
                    <span className="relative flex h-4 w-6 flex-col justify-between">
                        <span className="bg-foreground h-0.5 w-full origin-center rounded-full transition-transform duration-300 ease-out group-data-[state=open]:translate-y-[7px] group-data-[state=open]:rotate-45" />
                        <span className="bg-foreground h-0.5 w-full rounded-full transition-opacity duration-200 ease-out group-data-[state=open]:opacity-0" />
                        <span className="bg-foreground h-0.5 w-full origin-center rounded-full transition-transform duration-300 ease-out group-data-[state=open]:-translate-y-[7px] group-data-[state=open]:-rotate-45" />
                    </span>
                </button>
            </SheetTrigger>

            <SheetContent
                side="right"
                className="bg-background/95 border-border/50 flex w-full flex-col gap-0 border-l p-0 backdrop-blur-xl sm:max-w-none [&>button.rounded-xs]:hidden"
            >
                <SheetTitle className="sr-only">Stakly menu</SheetTitle>
                <SheetDescription className="sr-only">
                    Site navigation, search, and account actions.
                </SheetDescription>

                <div className="border-border/50 flex items-center justify-between border-b px-5 py-4">
                    <SheetClose asChild>
                        <Link
                            href={home()}
                            aria-label="Stakly home"
                            className="text-gradient-primary font-display text-2xl font-bold tracking-tight"
                        >
                            stakly
                        </Link>
                    </SheetClose>
                    <SheetClose asChild>
                        <button
                            type="button"
                            aria-label="Close menu"
                            className="text-muted-foreground hover:text-foreground hover:shadow-glow-sm focus-visible:ring-primary focus-visible:ring-offset-background inline-flex size-10 cursor-pointer items-center justify-center rounded-full transition-all duration-200 ease-out focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                        >
                            <X className="size-5" />
                        </button>
                    </SheetClose>
                </div>

                <div className="px-5 pt-5">
                    <div className="relative">
                        <Search className="text-muted-foreground absolute top-1/2 left-4 size-4 -translate-y-1/2" />
                        <input
                            type="search"
                            placeholder="Search listings, players, games..."
                            aria-label="Search"
                            className="border-border bg-card text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring focus-visible:shadow-glow-sm h-11 w-full rounded-full border pr-4 pl-11 text-sm transition-shadow duration-200 ease-out focus-visible:ring-1 focus-visible:outline-none"
                        />
                    </div>
                </div>

                <nav className="flex flex-col px-5 pt-8">
                    {navLinks.map((link) => (
                        <SheetClose key={link.label} asChild>
                            <Link
                                href={link.href}
                                className="font-display text-foreground hover:text-primary border-border/40 group flex items-center justify-between border-b py-4 text-2xl font-bold tracking-tight transition-colors"
                            >
                                <span>{link.label}</span>
                                <span
                                    aria-hidden
                                    className="text-muted-foreground/40 group-hover:text-primary translate-x-0 text-xl transition-all duration-200 ease-out group-hover:translate-x-1"
                                >
                                    →
                                </span>
                            </Link>
                        </SheetClose>
                    ))}
                </nav>

                <div className="mt-auto flex flex-col gap-3 p-5">
                    {user ? (
                        <>
                            <div className="border-border/40 flex items-center gap-3 border-t pt-4">
                                <Avatar className="size-10 overflow-hidden rounded-full">
                                    <AvatarImage
                                        src={user.avatar}
                                        alt={user.name}
                                    />
                                    <AvatarFallback className="bg-gradient-primary text-primary-foreground text-sm font-semibold">
                                        {getInitials(user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <span className="text-foreground truncate text-sm font-medium">
                                        {user.name}
                                    </span>
                                    <span className="text-muted-foreground truncate text-xs">
                                        {user.email}
                                    </span>
                                </div>
                            </div>

                            {isUnverified && (
                                <UnverifiedChip className="self-start" />
                            )}

                            <SheetClose asChild>
                                <Link
                                    href="#"
                                    className="text-foreground hover:text-primary flex items-center gap-2 text-sm transition-colors"
                                >
                                    <UserIcon className="size-4" />
                                    My profile
                                </Link>
                            </SheetClose>
                            <SheetClose asChild>
                                <Link
                                    href="#"
                                    className="text-foreground hover:text-primary flex items-center gap-2 text-sm transition-colors"
                                >
                                    <Wallet className="size-4" />
                                    Wallet
                                </Link>
                            </SheetClose>
                            <SheetClose asChild>
                                <Link
                                    href={editProfile()}
                                    prefetch
                                    className="text-foreground hover:text-primary flex items-center gap-2 text-sm transition-colors"
                                >
                                    <Settings className="size-4" />
                                    Settings
                                </Link>
                            </SheetClose>
                            <SheetClose asChild>
                                <Link
                                    href={logout()}
                                    method="post"
                                    as="button"
                                    onClick={handleLogout}
                                    className="text-destructive hover:text-destructive flex items-center gap-2 text-sm transition-colors"
                                >
                                    <LogOut className="size-4" />
                                    Log out
                                </Link>
                            </SheetClose>
                        </>
                    ) : (
                        <>
                            <Button
                                variant="ghost"
                                size="lg"
                                className="w-full"
                                onClick={() => triggerAuth(openLogin)}
                            >
                                Sign in
                            </Button>
                            <Button
                                variant="gradient"
                                size="pill"
                                className="w-full"
                                onClick={() => triggerAuth(openRegister)}
                            >
                                Sign up
                            </Button>
                        </>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
