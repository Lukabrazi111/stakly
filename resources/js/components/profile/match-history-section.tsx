import { ProfileMatchRow } from '@/components/profile/profile-match-row';
import type { Match } from '@/types';

interface Props {
    matches: Match[];
    profileUserId: number;
}

export function MatchHistorySection({ matches, profileUserId }: Props) {
    if (matches.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-border/60 bg-card/40 p-8 text-center">
                <p className="text-sm text-muted-foreground">
                    No settled matches yet.
                </p>
                <p className="mt-1 text-xs text-muted-foreground/80">
                    Resolved matches will appear here as they complete.
                </p>
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
