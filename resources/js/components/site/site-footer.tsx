import { Link } from '@inertiajs/react';

export function SiteFooter() {
    return (
        <footer className="border-border/50 bg-card/30 border-t">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-10 md:flex-row md:items-center md:justify-between">
                <div className="space-y-1">
                    <Link
                        href="/"
                        aria-label="Stakly home"
                        className="text-gradient-primary font-display rounded-md text-2xl font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                    >
                        stakly
                    </Link>
                    <p className="text-muted-foreground text-sm">
                        Stake your skill. Find your match.
                    </p>
                </div>

                <nav className="text-muted-foreground flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <Link href="#" className="hover:text-foreground transition-colors">
                        How it Works
                    </Link>
                    <Link href="#" className="hover:text-foreground transition-colors">
                        Support
                    </Link>
                    <Link href="#" className="hover:text-foreground transition-colors">
                        Terms
                    </Link>
                    <Link href="#" className="hover:text-foreground transition-colors">
                        Privacy
                    </Link>
                </nav>

                <div className="text-muted-foreground text-xs">
                    © {new Date().getFullYear()} Stakly. All rights reserved.
                </div>
            </div>
        </footer>
    );
}
