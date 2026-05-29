import { Link } from '@inertiajs/react';

interface FooterLink {
    label: string;
    href: string;
}

const footerLinks: FooterLink[] = [
    // `/#how-it-works` jumps to the HowItWorks section on the homepage —
    // no separate CMS page for this one; the designed cards on `/` carry
    // the brand treatment.
    { label: 'How it Works', href: '/#how-it-works' },
    // The next four hit the CMS reader at `/en/{slug}`. Privacy, Terms,
    // and Support are seeded as drafts — they 404 publicly until admin
    // writes the copy and publishes from `/admin/pages`.
    { label: 'About', href: '/en/about' },
    { label: 'Support', href: '/en/support' },
    { label: 'Terms', href: '/en/terms' },
    { label: 'Privacy', href: '/en/privacy' },
];

export function SiteFooter() {
    return (
        <footer className="border-t border-border/50 bg-card/30">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-10 md:flex-row md:items-center md:justify-between">
                <div className="space-y-1">
                    <Link
                        href="/"
                        aria-label="Stakly home"
                        className="rounded-md text-gradient-primary font-display text-2xl font-bold focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        stakly
                    </Link>
                    <p className="text-sm text-muted-foreground">
                        Stake your skill. Find your match.
                    </p>
                </div>

                <nav className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted-foreground">
                    {footerLinks.map((link) => (
                        <Link
                            key={link.label}
                            href={link.href}
                            className="transition-colors hover:text-foreground"
                        >
                            {link.label}
                        </Link>
                    ))}
                </nav>

                <div className="text-xs text-muted-foreground">
                    © {new Date().getFullYear()} Stakly. All rights reserved.
                </div>
            </div>
        </footer>
    );
}
