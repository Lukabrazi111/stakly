import { Link } from '@inertiajs/react';
import { Bell, Link2, Shield, User as UserIcon } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { edit as editLinkedAccounts } from '@/routes/linked-accounts';
import { edit as editNotifications } from '@/routes/notification-preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    // Built inside the component so the Wayfinder calls run AFTER
    // `setUrlDefaults({ locale })` in app.tsx wires up the runtime
    // default. At module-load time `urlDefaults()` is still empty
    // and the generated URLs fall back to the literal `$locale`
    // placeholder, producing broken hrefs like `/$locale/settings/…`.
    const settingsNavItems = [
        { title: 'Profile', href: editProfile(), icon: UserIcon },
        { title: 'Security', href: editSecurity(), icon: Shield },
        { title: 'Linked accounts', href: editLinkedAccounts(), icon: Link2 },
        { title: 'Notifications', href: editNotifications(), icon: Bell },
    ];

    return (
        <div className="mx-auto w-full max-w-3xl px-4 py-8 md:px-6 md:py-12">
            <Heading
                title="Settings"
                description="Manage your profile and account settings"
            />

            <nav
                aria-label="Settings"
                className="-mx-1 mt-6 flex gap-2 overflow-x-auto px-1 pb-1"
            >
                {settingsNavItems.map((item) => {
                    const Icon = item.icon;
                    const isActive = isCurrentOrParentUrl(item.href);

                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            prefetch
                            className={cn(
                                'inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition-colors duration-150 ease-out',
                                isActive
                                    ? 'border-primary/40 bg-primary/15 text-foreground [&_svg]:!text-primary'
                                    : 'border-border/60 text-muted-foreground hover:bg-primary/10 hover:text-foreground [&_svg]:text-muted-foreground hover:[&_svg]:!text-primary',
                            )}
                        >
                            <Icon className="size-4" />
                            {item.title}
                        </Link>
                    );
                })}
            </nav>

            <section className="mt-8 space-y-12">{children}</section>
        </div>
    );
}
