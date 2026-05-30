import { Head } from '@inertiajs/react';

interface PageMetaProps {
    /**
     * The page's <title>. Inertia's title callback (in app.tsx + ssr.tsx)
     * prepends the app name automatically — pass the page-specific portion
     * here (e.g. `"Match #42"`, not `"Match #42 - Stakly"`). Also wired to
     * `og:title` and `twitter:title`.
     */
    title: string;
    /**
     * 1-2 sentence summary, capped around 160 chars for SEO + social card
     * truncation. Wired to `meta[name=description]`, `og:description`, and
     * `twitter:description`. Keep it punchy — search snippets and link
     * previews show this verbatim.
     */
    description: string;
    /**
     * Absolute URL of the page's social-share image (1200×630 recommended).
     * When omitted, the blade-rendered default at `asset('og-image.png')`
     * stays as the og:image — fine for most pages. Override when a page
     * has a more specific visual (game poster on listing detail, avatar
     * on profile, etc.).
     */
    image?: string;
    /**
     * Mark the page as `robots: noindex,nofollow`. Use for private
     * surfaces (matches, wallet, settings, auth flow pages) that shouldn't
     * appear in search results.
     */
    noindex?: boolean;
    /**
     * Overrides the default `og:type` (`website`). Set `"profile"` on
     * user profile pages and `"article"` on CMS pages for richer crawler
     * categorization.
     */
    type?: 'website' | 'article' | 'profile';
}

/**
 * Per-page head tags + Open Graph + Twitter Card meta. Wraps Inertia's
 * `<Head>` with a typed API + `head-key` dedupe so a page declaring
 * `<PageMeta>` replaces blade-rendered defaults instead of duplicating
 * them.
 *
 * Blade defaults (in `resources/views/app.blade.php`) handle the
 * structural tags every page shares (og:site_name, og:image fallback,
 * twitter:card, canonical URL, og:url). PageMeta handles the dynamic,
 * page-specific bits.
 */
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
                <meta
                    head-key="og:image"
                    property="og:image"
                    content={image}
                />
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
