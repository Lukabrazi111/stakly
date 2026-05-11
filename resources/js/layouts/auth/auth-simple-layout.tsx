import { Link } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="bg-background text-foreground relative flex min-h-svh flex-col items-center justify-center overflow-hidden p-6 md:p-10">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 -z-10"
            >
                <div className="bg-primary/15 absolute top-[-20%] left-[-15%] size-[40rem] rounded-full blur-3xl" />
                <div className="bg-accent/15 absolute right-[-15%] bottom-[-20%] size-[40rem] rounded-full blur-3xl" />
                <div className="from-background/0 via-background/30 to-background absolute inset-0 bg-gradient-to-b" />
            </div>

            <Link
                href={home()}
                aria-label="Stakly home"
                className="text-gradient-primary font-display focus-visible:ring-primary focus-visible:ring-offset-background mb-8 rounded-md text-3xl font-bold tracking-tight focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                stakly
            </Link>

            <div className="border-border/60 bg-card relative w-full max-w-md overflow-hidden rounded-2xl border shadow-[0_0_60px_-40px_var(--gradient-glow)]">
                <div className="flex flex-col gap-6 p-8">
                    {(title || description) && (
                        <div className="flex flex-col gap-2 text-center">
                            {title && (
                                <h1 className="font-display text-foreground text-2xl font-bold tracking-tight">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="text-muted-foreground text-sm">
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
                className="text-muted-foreground hover:text-foreground mt-6 text-sm transition-colors"
            >
                ← Back to home
            </Link>
        </div>
    );
}
