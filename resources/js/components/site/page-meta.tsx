import { Head } from '@inertiajs/react';

interface PageMetaProps {
    title: string;
    description: string;
    /** Absolute URL of the page's social-share image (1200×630 recommended).
     *  When omitted, the blade-rendered default at `asset('og-image.png')`
     *  stays as the og:image. */
    image?: string;
    /** Marks the page as `robots: noindex,nofollow`. Use for private
     *  surfaces (matches, wallet, settings, auth) that shouldn't appear
     *  in search results. */
    noindex?: boolean;
    type?: 'website' | 'article' | 'profile';
}

/** Per-page head tags + Open Graph + Twitter Card. `head-key` dedupes
 *  against the blade-rendered defaults. */
export function PageMeta({
    title,
    description,
    image,
    noindex = false,
    type,
}: PageMetaProps) {
    return (
        <Head title={title}>
            <meta
                head-key="description"
                name="description"
                content={description}
            />
            {type && (
                <meta head-key="og:type" property="og:type" content={type} />
            )}
            <meta head-key="og:title" property="og:title" content={title} />
            <meta
                head-key="og:description"
                property="og:description"
                content={description}
            />
            {image && (
                <meta head-key="og:image" property="og:image" content={image} />
            )}
            <meta
                head-key="twitter:title"
                name="twitter:title"
                content={title}
            />
            <meta
                head-key="twitter:description"
                name="twitter:description"
                content={description}
            />
            {image && (
                <meta
                    head-key="twitter:image"
                    name="twitter:image"
                    content={image}
                />
            )}
            {noindex && (
                <meta
                    head-key="robots"
                    name="robots"
                    content="noindex,nofollow"
                />
            )}
        </Head>
    );
}
