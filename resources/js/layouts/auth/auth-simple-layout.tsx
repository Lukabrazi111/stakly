import { Link } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background p-6 text-foreground md:p-10">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 -z-10"
            >
                <div className="absolute top-[-20%] left-[-15%] size-[40rem] rounded-full bg-primary/15 blur-3xl" />
                <div className="absolute right-[-15%] bottom-[-20%] size-[40rem] rounded-full bg-accent/15 blur-3xl" />
                <div className="absolute inset-0 bg-gradient-to-b from-background/0 via-background/30 to-background" />
            </div>

            <Link
                href={home()}
                aria-label="Stakly home"
                className="mb-8 rounded-md text-gradient-primary font-display text-3xl font-bold tracking-tight focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                stakly
            </Link>

            <div className="relative w-full max-w-md overflow-hidden rounded-2xl border border-border/60 bg-card shadow-[0_0_60px_-40px_var(--gradient-glow)]">
                <div className="flex flex-col gap-6 p-8">
                    {(title || description) && (
                        <div className="flex flex-col gap-2 text-center">
                            {title && (
                                <h1 className="font-display text-2xl font-bold tracking-tight text-foreground">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="text-sm text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}
                    {children}
                </div>
            </div>

            <Link
                href={home()}
                className="mt-6 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                ← Back to home
            </Link>
        </div>
    );
}
