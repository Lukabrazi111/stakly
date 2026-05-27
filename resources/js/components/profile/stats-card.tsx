import { Link } from '@inertiajs/react';
import { index as listingsIndex } from '@/routes/listings';
import type { ProfileStats } from '@/types';

interface Props {
    stats: ProfileStats;
    isOwnProfile: boolean;
}

// `maximumFractionDigits: 0` matches the rest of the app's user-facing
// dollar convention (whole-dollar display). Sufficient resolution for stake
// volumes; precise BCMath values stay server-side.
const currencyFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

const numberFormatter = new Intl.NumberFormat('en-US');

export function StatsCard({ stats, isOwnProfile }: Props) {
    const hasMatches = stats.total_matches > 0;
    const showWinRate = stats.win_rate !== null;

    // Empty state — single card across the row, owner gets a CTA. The
    // previous shape rendered the same "No matches yet" twice across two
    // dashed tiles (and three when win rate was shown); the single card
    // reads cleaner and gives the owner one clear nudge instead of
    // repeating it.
    if (!hasMatches) {
        return (
            <section>
                <h2 className="mb-3 font-display text-lg font-semibold text-foreground">
                    Stats
                </h2>
                <div className="rounded-xl border border-dashed border-border/60 bg-card/40 p-6 text-center">
                    <p className="text-sm font-medium text-foreground">
                        {isOwnProfile
                            ? 'No matches yet'
                            : 'No matches played yet'}
                    </p>
                    <p className="mx-auto mt-1 max-w-prose text-xs text-muted-foreground">
                        {isOwnProfile
                            ? 'Your match count, volume staked, and win rate appear here once you play.'
                            : 'Match count, volume staked, and win rate appear here once they play.'}
                    </p>
                    {isOwnProfile && (
                        <Link
                            href={listingsIndex().url}
                            className="mt-4 inline-flex text-sm font-medium text-primary transition-colors hover:text-primary/80"
                        >
                            Browse the marketplace →
                        </Link>
                    )}
                </div>
            </section>
        );
    }

    // 2 tiles for the public view, 3 when the owner viewing their own
    // profile gets the extra Win Rate tile.
    const gridCols = showWinRate
        ? 'grid-cols-1 sm:grid-cols-2 md:grid-cols-3'
        : 'grid-cols-1 sm:grid-cols-2';

    return (
        <section>
            <h2 className="mb-3 font-display text-lg font-semibold text-foreground">
                Stats
            </h2>
            <div className={`grid gap-3 ${gridCols}`}>
                <StatTile
                    label="Total matches"
                    value={numberFormatter.format(stats.total_matches)}
                />
                <StatTile
                    label="Total volume staked"
                    value={currencyFormatter.format(stats.total_volume)}
                />
                {showWinRate && <WinRateTile winRate={stats.win_rate!} />}
            </div>
        </section>
    );
}

interface StatTileProps {
    label: string;
    value: string;
}

function StatTile({ label, value }: StatTileProps) {
    return (
        <div className="rounded-xl border border-border/60 bg-card p-4">
            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div className="mt-2 text-2xl font-semibold text-foreground">
                {value}
            </div>
        </div>
    );
}

interface WinRateTileProps {
    winRate: NonNullable<ProfileStats['win_rate']>;
}

// Owner-only tile. Renders the empty-state variant when there are 0 settled
// matches (the parent already gates on `total_matches > 0` to include
// `win_rate` in the payload, so this matches the same empty story when
// somehow rendered with zero values). Percentage falls back to "—" when
// every settled match was a draw (decided denominator is 0).
//
// M19 Phase 3 — adds a width-proportional gradient bar beneath the W/D/L
// breakdown so the win rate has a visual weight, not just a number.
function WinRateTile({ winRate }: WinRateTileProps) {
    const { wins, draws, losses, percentage } = winRate;
    const display = percentage === null ? '—' : `${percentage}%`;

    return (
        <div className="rounded-xl border border-border/60 bg-card p-4">
            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                Win rate
            </div>
            <div className="mt-2 text-2xl font-semibold text-foreground tabular-nums">
                {display}
            </div>
            <div className="mt-1 text-xs text-muted-foreground tabular-nums">
                {wins}W · {draws}D · {losses}L
            </div>
            {percentage !== null && (
                <div
                    className="mt-3 h-1 overflow-hidden rounded-full bg-muted"
                    role="presentation"
                >
                    <div
                        className="h-full rounded-full bg-gradient-primary transition-[width] duration-500 ease-out"
                        style={{ width: `${percentage}%` }}
                    />
                </div>
            )}
        </div>
    );
}
