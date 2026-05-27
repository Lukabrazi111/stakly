import { Link } from '@inertiajs/react';
import { ProfileMatchRow } from '@/components/profile/profile-match-row';
import { index as listingsIndex } from '@/routes/listings';
import type { Match } from '@/types';

interface Props {
    matches: Match[];
    profileUserId: number;
    isOwnProfile: boolean;
}

export function MatchHistorySection({
    matches,
    profileUserId,
    isOwnProfile,
}: Props) {
    if (matches.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-border/60 bg-card/40 p-8 text-center">
                <p className="text-sm font-medium text-foreground">
                    {isOwnProfile ? 'No matches yet' : 'No settled matches yet'}
                </p>
                <p className="mx-auto mt-1 max-w-prose text-xs text-muted-foreground">
                    {isOwnProfile
                        ? 'Take a listing to record your first match.'
                        : 'Resolved matches will appear here as they complete.'}
                </p>
                {isOwnProfile && (
                    <Link
                        href={listingsIndex().url}
                        className="mt-4 inline-flex text-sm font-medium text-primary transition-colors hover:text-primary/80"
                    >
                        Browse the marketplace <span aria-hidden="true">→</span>
                    </Link>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            {matches.map((match) => (
                <ProfileMatchRow
                    key={match.id}
                    match={match}
                    profileUserId={profileUserId}
                />
            ))}
        </div>
    );
}
