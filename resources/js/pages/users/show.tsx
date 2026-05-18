import { Head } from '@inertiajs/react';
import { LinkedAccountsSection } from '@/components/profile/linked-accounts-section';
import { ListingsSection } from '@/components/profile/listings-section';
import { MatchHistorySection } from '@/components/profile/match-history-section';
import { ProfileHeader } from '@/components/profile/profile-header';
import { StatsCard } from '@/components/profile/stats-card';
import SiteLayout from '@/layouts/site-layout';
import type { ProfileShowProps } from '@/types';

export default function UserShow({
    user,
    stats,
    openListings,
    matchHistory,
}: ProfileShowProps) {
    return (
        <SiteLayout>
            <Head title={`${user.name}'s profile`} />

            <div className="mx-auto max-w-4xl px-4 py-10 md:px-6 md:py-14">
                <ProfileHeader user={user} />

                <div className="mt-10 flex flex-col gap-10">
                    <StatsCard stats={stats} />
                    <ListingsSection listings={openListings.data} />
                    <MatchHistorySection
                        matches={matchHistory.data}
                        profileUserId={user.id}
                    />
                    <LinkedAccountsSection user={user} />
                </div>
            </div>
        </SiteLayout>
    );
}
