import { ProfileMatchRow } from '@/components/profile/profile-match-row';
import type { Match } from '@/types';

interface Props {
    matches: Match[];
    profileUserId: number;
}

export function MatchHistorySection({ matches, profileUserId }: Props) {
    return (
        <section>
            <h2 className="font-display text-foreground mb-3 text-lg font-semibold">
                Match history{matches.length > 0 && ` · ${matches.length}`}
            </h2>

            {matches.length === 0 ? (
                <div className="border-border/60 bg-card/40 rounded-xl border border-dashed p-8 text-center">
                    <p className="text-muted-foreground text-sm">
                        No settled matches yet.
                    </p>
                    <p className="text-muted-foreground/80 mt-1 text-xs">
                        Resolved matches will appear here as they complete.
                    </p>
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    {matches.map((match) => (
                        <ProfileMatchRow
                            key={match.id}
                            match={match}
                            profileUserId={profileUserId}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}
