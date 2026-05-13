import type { ProfileStats } from '@/types';

interface Props {
    stats: ProfileStats;
}

export function StatsCard({ stats }: Props) {
    const joinedDate = new Intl.DateTimeFormat('en-US', {
        month: 'short',
        year: 'numeric',
    }).format(new Date(stats.member_since));

    return (
        <section>
            <h2 className="font-display text-foreground mb-3 text-lg font-semibold">
                Stats
            </h2>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3">
                <StatTile label="Open listings" value={String(stats.open_listings)} />
                <StatTile label="Total listings" value={String(stats.total_listings)} />
                <StatTile label="Member since" value={joinedDate} />
                {/* M6-dependent stats — placeholders until match flow lands. */}
                <StatTile label="Win rate" />
                <StatTile label="Total earnings" />
                <StatTile label="Avg opponent rating" />
            </div>
        </section>
    );
}

interface StatTileProps {
    label: string;
    /** Omit to render the empty-state ("No matches yet") variant. */
    value?: string;
}

function StatTile({ label, value }: StatTileProps) {
    const isEmpty = value === undefined;

    return (
        <div
            className={`rounded-xl border p-4 ${
                isEmpty
                    ? 'border-border/60 bg-card/40 border-dashed'
                    : 'border-border/60 bg-card/60'
            }`}
        >
            <div className="text-muted-foreground text-xs uppercase tracking-wide">
                {label}
            </div>
            {isEmpty ? (
                <div className="text-muted-foreground mt-2 text-sm">
                    No matches yet
                </div>
            ) : (
                <div className="text-foreground mt-2 text-2xl font-semibold">
                    {value}
                </div>
            )}
        </div>
    );
}
