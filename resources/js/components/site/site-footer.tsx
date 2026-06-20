import { Link, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';

interface FooterLink {
    labelKey: string;
    /** Static slug — the full URL is built with the current locale at render time. */
    slug: string;
}

// Privacy/Terms/Support are seeded as drafts and 404 publicly until admin
// publishes from `/admin/pages`. CMS routing is locale-prefixed (M26 P4),
// so links are built per-locale below.
const footerLinks: FooterLink[] = [
    { labelKey: 'About', slug: 'about' },
    { labelKey: 'Support', slug: 'support' },
    { labelKey: 'Terms', slug: 'terms' },
    { labelKey: 'Privacy', slug: 'privacy' },
];

export function SiteFooter() {
    const t = useT();
    const { locale } = usePage().props;

    return (
        <footer className="border-t border-border/50 bg-card/30">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-10 md:flex-row md:items-center md:justify-between">
                <div className="space-y-1">
                    <Link
                        href="/"
                        aria-label={t('Stakly home')}
                        className="rounded-md text-gradient-primary font-display text-2xl font-bold focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        stakly
                    </Link>
                    <p className="text-sm text-muted-foreground">
                        {t('Stake your skill. Find your match.')}
                    </p>
                </div>

                <nav className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted-foreground">
                    {footerLinks.map((link) => (
                        <Link
                            key={link.labelKey}
                            href={`/${locale}/${link.slug}`}
                            className="transition-colors hover:text-foreground"
                        >
                            {t(link.labelKey)}
                        </Link>
                    ))}
                </nav>

                <div className="text-xs text-muted-foreground">
                    {t('© :year Stakly. All rights reserved.', {
                        year: new Date().getFullYear(),
                    })}
                </div>
            </div>
        </footer>
    );
}
