import { usePage } from '@inertiajs/react';
import { OwnerAccountSection } from '@/components/profile/owner-account-section';
import { ProfileHeader } from '@/components/profile/profile-header';
import { ProfileTabs } from '@/components/profile/profile-tabs';
import { StatsCard } from '@/components/profile/stats-card';
import { TrustStrip } from '@/components/profile/trust-strip';
import { PageMeta } from '@/components/site/page-meta';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import SiteLayout from '@/layouts/site-layout';
import type { ProfileShowProps } from '@/types';

export default function UserShow({
    user,
    stats,
    trust,
    repeat_pair_count,
    openListings,
    matchHistory,
    og,
}: ProfileShowProps) {
    const { auth } = usePage().props;
    const isOwnProfile = auth.user?.id === user.id;

    // Owner viewing their own profile gets the player-hub layout (sidebar +
    // "My profile" active). Visitors viewing someone else's profile get the
    // plain SiteLayout — the sidebar is owner management context and would
    // feel out of place on a public read-only profile.
    const Layout = isOwnProfile ? PlayerHubLayout : SiteLayout;

    return (
        <Layout>
            <PageMeta
                title={og.title}
                description={og.description}
                type="profile"
            />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                <ProfileHeader user={user} />

                <div className="mt-6 flex flex-col gap-6">
                    <TrustStrip
                        trust={trust}
                        repeatPairCount={repeat_pair_count}
                    />
                    <StatsCard stats={stats} isOwnProfile={isOwnProfile} />
                    <ProfileTabs
                        matches={matchHistory.data}
                        listings={openListings.data}
                        profileUserId={user.id}
                        isOwnProfile={isOwnProfile}
                    />

                    {isOwnProfile && (
                        <OwnerAccountSection
                            profileUrl={og.url}
                            username={user.username}
                        />
                    )}
                </div>
            </div>
        </Layout>
    );
}
