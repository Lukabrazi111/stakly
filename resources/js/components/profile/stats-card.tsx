import { Link } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import { index as listingsIndex } from '@/routes/listings';
import type { ProfileStats } from '@/types';

interface Props {
    stats: ProfileStats;
    isOwnProfile: boolean;
}

const currencyFormatter = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

const numberFormatter = new Intl.NumberFormat('en-US');

export function StatsCard({ stats, isOwnProfile }: Props) {
    const t = useT();
    const hasMatches = stats.total_matches > 0;
    const showWinRate = stats.win_rate !== null;

    if (!hasMatches) {
        return (
            <section>
                <h2 className="mb-3 font-display text-lg font-semibold text-foreground">
                    {t('Stats')}
                </h2>
                <div className="rounded-xl border border-dashed border-border/60 bg-card/40 p-6 text-center">
                    <p className="text-sm font-medium text-foreground">
                        {isOwnProfile
                            ? t('No matches yet')
                            : t('No matches played yet')}
                    </p>
                    <p className="mx-auto mt-1 max-w-prose text-xs text-muted-foreground">
                        {isOwnProfile
                            ? t(
                                  'Your match count, volume staked, and win rate appear here once you play.',
                              )
                            : t(
                                  'Match count, volume staked, and win rate appear here once they play.',
                              )}
                    </p>
                    {isOwnProfile && (
                        <Link
                            href={listingsIndex().url}
                            className="mt-4 inline-flex text-sm font-medium text-primary transition-colors hover:text-primary/80"
                        >
                            {t('Browse the marketplace')}{' '}
                            <span aria-hidden="true">→</span>
                        </Link>
                    )}
                </div>
            </section>
        );
    }

    const gridCols = showWinRate
        ? 'grid-cols-1 sm:grid-cols-2 md:grid-cols-3'
        : 'grid-cols-1 sm:grid-cols-2';

    return (
        <section>
            <h2 className="mb-3 font-display text-lg font-semibold text-foreground">
                {t('Stats')}
            </h2>
            <div className={`grid gap-3 ${gridCols}`}>
                <StatTile
                    label={t('Total matches')}
                    value={numberFormatter.format(stats.total_matches)}
                />
                <StatTile
                    label={t('Total volume staked')}
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

/** Percentage falls back to "—" when every settled match was a draw
 *  (decided denominator is 0). */
function WinRateTile({ winRate }: WinRateTileProps) {
    const t = useT();
    const { wins, draws, losses, percentage } = winRate;
    const display = percentage === null ? '—' : `${percentage}%`;

    return (
        <div className="rounded-xl border border-border/60 bg-card p-4">
            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                {t('Win rate')}
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
