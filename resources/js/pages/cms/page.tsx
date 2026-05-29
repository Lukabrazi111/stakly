import { Head } from '@inertiajs/react';
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

            <article className="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16">
                <header className="mb-10 border-b border-border pb-8">
                    <h1 className="font-display text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
                        {title}
                    </h1>
                    <p className="mt-3 text-sm text-muted-foreground">
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
