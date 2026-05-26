import { Head } from '@inertiajs/react';
import { ListingsSection } from '@/components/profile/listings-section';
import { MatchHistorySection } from '@/components/profile/match-history-section';
import { ProfileHeader } from '@/components/profile/profile-header';
import { StatsCard } from '@/components/profile/stats-card';
import { TrustStrip } from '@/components/profile/trust-strip';
import SiteLayout from '@/layouts/site-layout';
import type { ProfileShowProps } from '@/types';

export default function UserShow({
    user,
    stats,
    trust,
    repeat_pair_count,
    openListings,
    matchHistory,
}: ProfileShowProps) {
    return (
        <SiteLayout>
            <Head title={`${user.name}'s profile`} />

            <div className="mx-auto max-w-4xl px-4 py-10 md:px-6 md:py-14">
                <ProfileHeader user={user} />

                <div className="mt-6 flex flex-col gap-6">
                    <TrustStrip
                        trust={trust}
                        repeatPairCount={repeat_pair_count}
                    />
                    <StatsCard stats={stats} />
                    <ListingsSection listings={openListings.data} />
                    <MatchHistorySection
                        matches={matchHistory.data}
                        profileUserId={user.id}
                    />
                </div>
            </div>
        </SiteLayout>
    );
}
