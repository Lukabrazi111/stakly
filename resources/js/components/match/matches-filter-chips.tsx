import { router } from '@inertiajs/react';
import { buildMatchesQuery } from '@/lib/matches-query';
import { index as matchesIndex } from '@/routes/matches';
import type { MatchFilters, MatchStatus } from '@/types';

interface Chip {
    label: string;
    value: MatchStatus | null;
}

// `manual_review` omitted — rare and folds under "All"; still targetable
// via ?filter[status]=manual_review.
const CHIPS: Chip[] = [
    { label: 'All', value: null },
    { label: 'Pending', value: 'pending' },
    { label: 'Disputed', value: 'disputed' },
    { label: 'Settled', value: 'settled' },
    { label: 'Cancelled', value: 'cancelled' },
];

interface Props {
    filters: MatchFilters;
}

export function MatchesFilterChips({ filters }: Props) {
    const handleSelect = (value: MatchStatus | null) => {
        if (filters.status === value) {
            return;
        }

        router.get(matchesIndex().url, buildMatchesQuery({ status: value }), {
            preserveState: true,
            preserveScroll: false,
        });
    };

    return (
        <div
            className="flex flex-wrap gap-2"
            role="tablist"
            aria-label="Filter matches by status"
        >
            {CHIPS.map((chip) => {
                const isActive = filters.status === chip.value;

                return (
                    <button
                        key={chip.label}
                        type="button"
                        role="tab"
                        aria-selected={isActive}
                        onClick={() => handleSelect(chip.value)}
                        className={`inline-flex h-9 cursor-pointer items-center rounded-full border px-4 text-sm font-medium transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none ${
                            isActive
                                ? 'border-primary/40 bg-primary/15 text-foreground'
                                : 'border-border/60 bg-card/60 text-muted-foreground hover:bg-primary/10 hover:text-foreground'
                        }`}
                    >
                        {chip.label}
                    </button>
                );
            })}
        </div>
    );
}
