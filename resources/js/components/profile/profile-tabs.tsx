import * as React from 'react';
import { ListingsSection } from '@/components/profile/listings-section';
import { MatchHistorySection } from '@/components/profile/match-history-section';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useT } from '@/lib/i18n';
import type { Listing, Match } from '@/types';

type ProfileTab = 'matches' | 'listings' | 'reviews';

const VALID_TABS: readonly ProfileTab[] = ['matches', 'listings', 'reviews'];
const DEFAULT_TAB: ProfileTab = 'matches';

function readTabFromUrl(): ProfileTab {
    if (typeof window === 'undefined') {
        return DEFAULT_TAB;
    }

    const param = new URLSearchParams(window.location.search).get('tab');

    return (VALID_TABS as readonly string[]).includes(param ?? '')
        ? (param as ProfileTab)
        : DEFAULT_TAB;
}

interface Props {
    matches: Match[];
    listings: Listing[];
    profileUserId: number;
    isOwnProfile: boolean;
}

/**
 * Profile activity tabs. State syncs to `?tab=` via `history.pushState` —
 * the data is already loaded, no need to re-fetch on tab click.
 */
export function ProfileTabs({
    matches,
    listings,
    profileUserId,
    isOwnProfile,
}: Props) {
    const t = useT();
    // Default during SSR + first paint, sync after mount — reading the URL
    // synchronously would cause a hydration mismatch when `?tab=*` is set.
    const [tab, setTab] = React.useState<ProfileTab>(DEFAULT_TAB);

    React.useEffect(() => {
        setTab(readTabFromUrl());

        const handler = () => setTab(readTabFromUrl());
        window.addEventListener('popstate', handler);

        return () => window.removeEventListener('popstate', handler);
    }, []);

    const handleChange = (next: string) => {
        const newTab = next as ProfileTab;

        if (newTab === tab) {
            return;
        }

        setTab(newTab);

        const url = new URL(window.location.href);

        if (newTab === DEFAULT_TAB) {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', newTab);
        }

        window.history.pushState({}, '', url);
    };

    return (
        <Tabs value={tab} onValueChange={handleChange} className="w-full">
            <TabsList
                variant="line"
                aria-label={t('Profile activity sections')}
            >
                <TabsTrigger value="matches">{t('Match History')}</TabsTrigger>
                <TabsTrigger value="listings">{t('Open Listings')}</TabsTrigger>
                <TabsTrigger value="reviews">{t('Reviews')}</TabsTrigger>
            </TabsList>

            <TabsContent value="matches" className="mt-5">
                <MatchHistorySection
                    matches={matches}
                    profileUserId={profileUserId}
                    isOwnProfile={isOwnProfile}
                />
            </TabsContent>

            <TabsContent value="listings" className="mt-5">
                <ListingsSection
                    listings={listings}
                    isOwnProfile={isOwnProfile}
                />
            </TabsContent>

            <TabsContent value="reviews" className="mt-5">
                <ReviewsPlaceholder />
            </TabsContent>
        </Tabs>
    );
}

function ReviewsPlaceholder() {
    const t = useT();

    return (
        <div className="rounded-xl border border-dashed border-border/60 bg-card/40 p-8 text-center">
            <p className="text-sm font-medium text-foreground">
                {t('Reviews coming soon')}
            </p>
            <p className="mx-auto mt-2 max-w-prose text-sm text-muted-foreground">
                {t(
                    'Stakly is designing a coercion-resistant review system. Until then, reputation is shown via completion rate + match history.',
                )}
            </p>
        </div>
    );
}
