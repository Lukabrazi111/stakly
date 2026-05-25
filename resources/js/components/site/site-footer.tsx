import { Link } from '@inertiajs/react';

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
                    <Link
                        href="#"
                        className="transition-colors hover:text-foreground"
                    >
                        How it Works
                    </Link>
                    <Link
                        href="#"
                        className="transition-colors hover:text-foreground"
                    >
                        Support
                    </Link>
                    <Link
                        href="#"
                        className="transition-colors hover:text-foreground"
                    >
                        Terms
                    </Link>
                    <Link
                        href="#"
                        className="transition-colors hover:text-foreground"
                    >
                        Privacy
                    </Link>
                </nav>

                <div className="text-xs text-muted-foreground">
                    © {new Date().getFullYear()} Stakly. All rights reserved.
                </div>
            </div>
        </footer>
    );
}
