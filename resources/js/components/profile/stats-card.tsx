import type { ProfileStats } from '@/types';

interface Props {
    stats: ProfileStats;
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

export function StatsCard({ stats }: Props) {
    const hasMatches = stats.total_matches > 0;
    const showWinRate = stats.win_rate !== null;

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
                    value={
                        hasMatches
                            ? numberFormatter.format(stats.total_matches)
                            : undefined
                    }
                />
                <StatTile
                    label="Total volume staked"
                    value={
                        hasMatches
                            ? currencyFormatter.format(stats.total_volume)
                            : undefined
                    }
                />
                {showWinRate && <WinRateTile winRate={stats.win_rate!} />}
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
                    ? 'border-dashed border-border/60 bg-card/40'
                    : 'border-border/60 bg-card'
            }`}
        >
            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            {isEmpty ? (
                <div className="mt-2 text-sm text-muted-foreground">
                    No matches yet
                </div>
            ) : (
                <div className="mt-2 text-2xl font-semibold text-foreground">
                    {value}
                </div>
            )}
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
