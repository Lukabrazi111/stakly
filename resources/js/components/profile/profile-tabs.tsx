import * as React from 'react';
import { ListingsSection } from '@/components/profile/listings-section';
import { MatchHistorySection } from '@/components/profile/match-history-section';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
 * Profile activity tabs (M19 Phase 4). Three tabs: Match History (default)
 * | Open Listings | Reviews (placeholder). State syncs to `?tab=` so
 * deep-link / refresh / browser-back navigate within a profile.
 *
 * URL sync uses pure client-side `history.pushState` rather than Inertia
 * `router.get()` — the underlying data (matches + listings) is already
 * loaded by `UserController::show`, so re-fetching on every tab click
 * would be wasted work. Mirrors the auth-modal-provider's URL handling
 * pattern (per CLAUDE.md).
 */
export function ProfileTabs({
    matches,
    listings,
    profileUserId,
    isOwnProfile,
}: Props) {
    // Default to the matches tab during SSR + first client paint, then sync
    // from `?tab=` after mount. Initializing via `readTabFromUrl` would
    // render a different tab on server vs client when the URL carries
    // `?tab=listings` or `?tab=reviews`.
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
            <TabsList variant="line" aria-label="Profile activity sections">
                <TabsTrigger value="matches">Match History</TabsTrigger>
                <TabsTrigger value="listings">Open Listings</TabsTrigger>
                <TabsTrigger value="reviews">Reviews</TabsTrigger>
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
    return (
        <div className="rounded-xl border border-dashed border-border/60 bg-card/40 p-8 text-center">
            <p className="text-sm font-medium text-foreground">
                Reviews coming soon
            </p>
            <p className="mx-auto mt-2 max-w-prose text-sm text-muted-foreground">
                Stakly is designing a coercion-resistant review system. Until
                then, reputation is shown via completion rate + match history.
            </p>
        </div>
    );
}
