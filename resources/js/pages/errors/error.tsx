import { Link } from '@inertiajs/react';
import { ArrowLeft, Compass } from 'lucide-react';
import { PageMeta } from '@/components/site/page-meta';
import { Button } from '@/components/ui/button';
import SiteLayout from '@/layouts/site-layout';
import { useT } from '@/lib/i18n';
import { home } from '@/routes';
import { index as listingsIndex } from '@/routes/listings';

interface Props {
    status: 403 | 404 | 500 | 503;
}

interface Copy {
    title: string;
    heading: string;
    body: string;
}

const COPY: Record<Props['status'], Copy> = {
    403: {
        title: '403 — Forbidden',
        heading: "You can't access this page",
        body: "You're signed in, but this page isn't yours to view. Head back to the marketplace.",
    },
    404: {
        title: '404 — Page not found',
        heading: 'Page not found',
        body: "We couldn't find that page. The listing may have ended, the URL may be off, or the page never existed.",
    },
    500: {
        title: '500 — Something went wrong',
        heading: 'Something went wrong on our end',
        body: 'We logged the error and are looking into it. Try again in a minute, or head back to the marketplace.',
    },
    503: {
        title: '503 — Be right back',
        heading: 'Stakly is briefly offline for maintenance',
        body: "We're updating the platform. This usually takes a couple of minutes — try again shortly.",
    },
};

export default function ErrorPage({ status }: Props) {
    const t = useT();
    const copy = COPY[status] ?? COPY[500];

    return (
        <SiteLayout>
            <PageMeta title={t(copy.title)} description={t(copy.body)} noindex />

            <div className="mx-auto flex min-h-[60vh] max-w-2xl flex-col items-center justify-center px-4 py-16 text-center md:py-24">
                <p className="font-display text-7xl font-black tracking-tight text-gradient-primary md:text-8xl">
                    {status}
                </p>
                <h1 className="mt-4 font-display text-2xl font-bold tracking-tight text-foreground md:text-3xl">
                    {t(copy.heading)}
                </h1>
                <p className="mt-3 max-w-prose text-sm text-muted-foreground md:text-base">
                    {t(copy.body)}
                </p>

                <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <Button asChild variant="gradient" size="pill">
                        <Link href={listingsIndex().url}>
                            <Compass className="size-4" aria-hidden="true" />
                            {t('Browse listings')}
                        </Link>
                    </Button>
                    <Button asChild variant="outline" size="pill">
                        <Link href={home().url}>
                            <ArrowLeft className="size-4" aria-hidden="true" />
                            {t('Back to home')}
                        </Link>
                    </Button>
                </div>
            </div>
        </SiteLayout>
    );
}
