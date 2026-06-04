import { Link, router, usePage } from '@inertiajs/react';
import {
    ListChecks,
    LogOut,
    Search,
    Settings,
    Swords,
    User as UserIcon,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { useAuthModal } from '@/components/auth/auth-modal-provider';
import { LocaleSwitcher } from '@/components/site/locale-switcher';
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
import { useT } from '@/lib/i18n';
import { formatUsdt } from '@/lib/wallet-format';
import { home, logout } from '@/routes';
import {
    create as listingsCreate,
    index as listingsIndex,
    mine as listingsMine,
} from '@/routes/listings';
import { index as matchesIndex } from '@/routes/matches';
import { edit as editProfile } from '@/routes/profile';
import { show as userShow } from '@/routes/users';
import { index as walletIndex } from '@/routes/wallet';

interface NavLink {
    label: string;
    href: ReturnType<typeof listingsIndex> | string;
}

const mobileMenuItemClass =
    'text-muted-foreground hover:text-foreground active:text-foreground hover:bg-primary/10 active:bg-primary/10 [&_svg]:text-muted-foreground hover:[&_svg]:text-primary active:[&_svg]:text-primary flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition-colors duration-150 ease-out';

export function MobileMenu() {
    const t = useT();
    const [open, setOpen] = useState(false);
    const { openLogin, openRegister } = useAuthModal();
    const { auth } = usePage().props;
    const user = auth.user;
    const isUnverified = Boolean(user && !user.email_verified_at);
    const getInitials = useInitials();

    // Built inside the component so Wayfinder generators see `URL::defaults`
    // (set after `setUrlDefaults` runs in app.tsx). Module-top-level
    // declaration would produce hrefs like `/$locale/listings` on first
    // import — same gotcha as `settings/layout.tsx` had.
    const navLinks: NavLink[] = [
        { label: t('Listings'), href: listingsIndex() },
        { label: t('How it works'), href: '/#how-it-works' },
    ];

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
                    aria-label={t('Open menu')}
                    className="group relative inline-flex size-10 cursor-pointer items-center justify-center rounded-full transition-shadow duration-200 ease-out hover:shadow-glow-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none md:hidden"
                >
                    <span className="relative flex h-4 w-6 flex-col justify-between">
                        <span className="h-0.5 w-full origin-center rounded-full bg-foreground transition-transform duration-300 ease-out group-data-[state=open]:translate-y-[7px] group-data-[state=open]:rotate-45" />
                        <span className="h-0.5 w-full rounded-full bg-foreground transition-opacity duration-200 ease-out group-data-[state=open]:opacity-0" />
                        <span className="h-0.5 w-full origin-center rounded-full bg-foreground transition-transform duration-300 ease-out group-data-[state=open]:-translate-y-[7px] group-data-[state=open]:-rotate-45" />
                    </span>
                </button>
            </SheetTrigger>

            <SheetContent
                side="right"
                className="flex w-full flex-col gap-0 border-l border-border/50 bg-background/95 p-0 backdrop-blur-xl sm:max-w-none"
            >
                <SheetTitle className="sr-only">{t('Stakly menu')}</SheetTitle>
                <SheetDescription className="sr-only">
                    {t('Site navigation, search, and account actions.')}
                </SheetDescription>

                <div className="flex items-center justify-between border-b border-border/50 px-5 py-4">
                    <SheetClose asChild>
                        <Link
                            href={home()}
                            aria-label={t('Stakly home')}
                            className="text-gradient-primary font-display text-2xl font-bold tracking-tight"
                        >
                            stakly
                        </Link>
                    </SheetClose>
                    <LocaleSwitcher align="end" />
                </div>

                <div className="px-5 pt-5">
                    <div className="relative">
                        <Search className="absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="search"
                            placeholder={t(
                                'Search listings, players, games...',
                            )}
                            aria-label={t('Search')}
                            className="h-11 w-full rounded-full border border-border bg-card pr-4 pl-11 text-sm text-foreground transition-shadow duration-200 ease-out placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-1 focus-visible:shadow-glow-sm focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>
                </div>

                <nav className="flex flex-col px-5 pt-8">
                    {navLinks.map((link) => (
                        <SheetClose key={link.label} asChild>
                            <Link
                                href={link.href}
                                className="group flex items-center justify-between border-b border-border/40 py-4 font-display text-2xl font-bold tracking-tight text-foreground transition-colors hover:text-primary"
                            >
                                <span>{link.label}</span>
                                <span
                                    aria-hidden
                                    className="translate-x-0 text-xl text-muted-foreground/40 transition-all duration-200 ease-out group-hover:translate-x-1 group-hover:text-primary"
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
                                    {t('Create listing')}
                                </Button>
                                <p className="text-center text-xs text-muted-foreground">
                                    {t('Verify your email to create listings.')}
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
                                        {t('Create listing')}
                                    </Link>
                                </Button>
                            </SheetClose>
                        )}
                    </div>
                )}

                <div className="mt-auto flex flex-col gap-3 p-5">
                    {user ? (
                        <div className="flex flex-col overflow-hidden rounded-2xl border border-border/60 bg-card/95 backdrop-blur-md">
                            <div className="flex items-center gap-3 p-4">
                                <Avatar className="size-12 overflow-hidden rounded-full">
                                    <AvatarImage
                                        src={user.avatar_thumb_url ?? undefined}
                                        alt={user.name}
                                    />
                                    <AvatarFallback className="bg-gradient-primary text-base font-semibold text-primary-foreground">
                                        {getInitials(user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate text-sm font-semibold text-foreground">
                                        {user.name}
                                    </span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {user.email}
                                    </span>
                                </div>
                            </div>

                            {isUnverified && (
                                <div className="px-4 pb-3">
                                    <UnverifiedChip />
                                </div>
                            )}

                            <SheetClose asChild>
                                <Link
                                    href={walletIndex().url}
                                    prefetch
                                    className="flex items-center justify-between gap-3 border-t border-border/60 px-4 py-3 transition-colors duration-150 ease-out hover:bg-primary/10 active:bg-primary/10"
                                >
                                    <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {t('Balance')}
                                    </span>
                                    <span className="font-display text-base font-semibold text-foreground">
                                        ${formatUsdt(user.usdt_balance)}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            USDT
                                        </span>
                                    </span>
                                </Link>
                            </SheetClose>

                            <div className="flex flex-col gap-0.5 border-t border-border/60 p-2">
                                <SheetClose asChild>
                                    <Link
                                        href={
                                            userShow({ user: user.username })
                                                .url
                                        }
                                        prefetch
                                        className={mobileMenuItemClass}
                                    >
                                        <UserIcon className="size-5" />
                                        {t('My profile')}
                                    </Link>
                                </SheetClose>
                                <SheetClose asChild>
                                    <Link
                                        href={listingsMine().url}
                                        prefetch
                                        className={mobileMenuItemClass}
                                    >
                                        <ListChecks className="size-5" />
                                        {t('My listings')}
                                    </Link>
                                </SheetClose>
                                <SheetClose asChild>
                                    <Link
                                        href={matchesIndex().url}
                                        prefetch
                                        className={mobileMenuItemClass}
                                    >
                                        <Swords className="size-5" />
                                        {t('Matches')}
                                    </Link>
                                </SheetClose>
                                <SheetClose asChild>
                                    <Link
                                        href={walletIndex().url}
                                        prefetch
                                        className={mobileMenuItemClass}
                                    >
                                        <Wallet className="size-5" />
                                        {t('Wallet')}
                                    </Link>
                                </SheetClose>
                                <SheetClose asChild>
                                    <Link
                                        href={editProfile()}
                                        prefetch
                                        className={mobileMenuItemClass}
                                    >
                                        <Settings className="size-5" />
                                        {t('Settings')}
                                    </Link>
                                </SheetClose>
                            </div>

                            <div className="border-t border-border/60 p-2">
                                <SheetClose asChild>
                                    <Link
                                        href={logout()}
                                        method="post"
                                        as="button"
                                        onClick={handleLogout}
                                        className="flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium text-muted-foreground transition-colors duration-150 ease-out hover:bg-destructive/10 hover:text-destructive active:bg-destructive/10 active:text-destructive [&_svg]:text-muted-foreground hover:[&_svg]:text-destructive active:[&_svg]:text-destructive"
                                    >
                                        <LogOut className="size-5" />
                                        {t('Log out')}
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
                                {t('Sign in')}
                            </Button>
                            <Button
                                variant="gradient"
                                size="pill"
                                className="w-full"
                                onClick={() => triggerAuth(openRegister)}
                            >
                                {t('Sign up')}
                            </Button>
                        </>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
