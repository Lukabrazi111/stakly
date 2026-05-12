import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Search, Settings, User as UserIcon, Wallet } from 'lucide-react';
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
import {
    create as listingsCreate,
    index as listingsIndex,
} from '@/routes/listings';
import { edit as editProfile } from '@/routes/profile';

interface NavLink {
    label: string;
    href: ReturnType<typeof listingsIndex> | string;
}

const navLinks: NavLink[] = [
    { label: 'Listings', href: listingsIndex() },
    { label: 'How it Works', href: '#how-it-works' },
    { label: 'Support', href: '#' },
];

const mobileMenuItemClass =
    'text-muted-foreground hover:text-foreground active:text-foreground hover:bg-primary/10 active:bg-primary/10 [&_svg]:text-muted-foreground hover:[&_svg]:text-primary active:[&_svg]:text-primary flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition-colors duration-150 ease-out';

const mobileDisabledItemClass =
    'text-muted-foreground/60 [&_svg]:text-muted-foreground/60 flex w-full items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium';

function SoonBadge() {
    return (
        <span className="bg-background/80 text-muted-foreground ml-auto rounded-full px-2 py-0.5 text-[10px] tracking-wide uppercase backdrop-blur">
            Soon
        </span>
    );
}

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
                className="bg-background/95 border-border/50 flex w-full flex-col gap-0 border-l p-0 backdrop-blur-xl sm:max-w-none"
            >
                <SheetTitle className="sr-only">Stakly menu</SheetTitle>
                <SheetDescription className="sr-only">
                    Site navigation, search, and account actions.
                </SheetDescription>

                <div className="border-border/50 flex items-center border-b px-5 py-4">
                    <SheetClose asChild>
                        <Link
                            href={home()}
                            aria-label="Stakly home"
                            className="text-gradient-primary font-display text-2xl font-bold tracking-tight"
                        >
                            stakly
                        </Link>
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

                {user && (
                    <div className="px-5 pt-6">
                        {isUnverified ? (
                            <div className="space-y-2">
                                <Button
                                    variant="gradient"
                                    size="pill"
                                    className="w-full"
                                    disabled
                                >
                                    Create listing
                                </Button>
                                <p className="text-muted-foreground text-center text-xs">
                                    Verify your email to create listings.
                                </p>
                            </div>
                        ) : (
                            <SheetClose asChild>
                                <Button
                                    variant="gradient"
                                    size="pill"
                                    className="w-full"
                                    asChild
                                >
                                    <Link href={listingsCreate().url}>
                                        Create listing
                                    </Link>
                                </Button>
                            </SheetClose>
                        )}
                    </div>
                )}

                <div className="mt-auto flex flex-col gap-3 p-5">
                    {user ? (
                        <div className="border-border/60 bg-card/95 flex flex-col overflow-hidden rounded-2xl border backdrop-blur-md">
                            <div className="flex items-center gap-3 p-4">
                                <Avatar className="size-12 overflow-hidden rounded-full">
                                    <AvatarImage
                                        src={user.avatar}
                                        alt={user.name}
                                    />
                                    <AvatarFallback className="bg-gradient-primary text-primary-foreground text-base font-semibold">
                                        {getInitials(user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <span className="text-foreground truncate text-sm font-semibold">
                                        {user.name}
                                    </span>
                                    <span className="text-muted-foreground truncate text-xs">
                                        {user.email}
                                    </span>
                                </div>
                            </div>

                            {isUnverified && (
                                <div className="px-4 pb-3">
                                    <UnverifiedChip />
                                </div>
                            )}

                            <div className="border-border/60 flex flex-col gap-0.5 border-t p-2">
                                <div
                                    aria-disabled="true"
                                    className={mobileDisabledItemClass}
                                >
                                    <UserIcon className="size-5" />
                                    <span>My profile</span>
                                    <SoonBadge />
                                </div>
                                <div
                                    aria-disabled="true"
                                    className={mobileDisabledItemClass}
                                >
                                    <Wallet className="size-5" />
                                    <span>Wallet</span>
                                    <SoonBadge />
                                </div>
                                <SheetClose asChild>
                                    <Link
                                        href={editProfile()}
                                        prefetch
                                        className={mobileMenuItemClass}
                                    >
                                        <Settings className="size-5" />
                                        Settings
                                    </Link>
                                </SheetClose>
                            </div>

                            <div className="border-border/60 border-t p-2">
                                <SheetClose asChild>
                                    <Link
                                        href={logout()}
                                        method="post"
                                        as="button"
                                        onClick={handleLogout}
                                        className="text-muted-foreground hover:text-destructive active:text-destructive hover:bg-destructive/10 active:bg-destructive/10 [&_svg]:text-muted-foreground hover:[&_svg]:text-destructive active:[&_svg]:text-destructive flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition-colors duration-150 ease-out"
                                    >
                                        <LogOut className="size-5" />
                                        Log out
                                    </Link>
                                </SheetClose>
                            </div>
                        </div>
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
