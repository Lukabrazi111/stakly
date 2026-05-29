import { Head } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import SiteLayout from '@/layouts/site-layout';

interface Props {
    title: string;
    html: string;
    updated_at: string;
}

export default function CmsPage({ title, html, updated_at }: Props) {
    // CommonMark on the server side strips raw HTML by default, so the
    // string handed to `dangerouslySetInnerHTML` only contains the tags
    // produced by the markdown grammar — no XSS surface from admin input.
    const updated = new Date(updated_at);

    return (
        <SiteLayout>
            <Head title={`${title} — Stakly`} />

            <article className="relative mx-auto max-w-3xl px-4 py-14 sm:px-6 sm:py-20">
                {/* Soft pink wash behind the header. Keeps the prose
                    template feeling like part of the Stakly site without
                    introducing animation or per-page chrome. */}
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-10 left-1/2 -z-10 -translate-x-1/2"
                >
                    <div className="size-[34rem] rounded-full bg-primary/[0.08] blur-3xl" />
                </div>

                <header className="mb-12 border-b border-border/60 pb-10">
                    <h1 className="font-display text-4xl font-extrabold tracking-tight text-balance text-foreground sm:text-5xl md:text-6xl">
                        {title}
                    </h1>
                    <p className="mt-4 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                        <CalendarDays className="size-3.5" aria-hidden />
                        Last updated{' '}
                        {updated.toLocaleDateString('en-US', {
                            dateStyle: 'long',
                        })}
                    </p>
                </header>

                <div
                    className="cms-prose"
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            </article>
        </SiteLayout>
    );
}
