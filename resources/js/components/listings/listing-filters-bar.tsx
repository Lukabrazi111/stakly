import { router } from '@inertiajs/react';
import { ChevronDown, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ListingFilters } from '@/components/listings/listing-filters';
import { ListingsMoreMenu } from '@/components/listings/listings-more-menu';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { CURRENCIES, DEFAULT_CURRENCY } from '@/config/currencies';
import { gameSupports } from '@/config/games';
import { useIsMobile } from '@/hooks/use-mobile';
import { buildListingsQuery } from '@/lib/listings-query';
import { index as listingsIndex } from '@/routes/listings';
import type {
    ListingFilters as ListingFiltersType,
    ListingSort,
    TimeControl,
} from '@/types';

interface Props {
    filters: ListingFiltersType;
    sorts: ListingSort[];
}

const SORT_LABELS: Record<ListingSort, string> = {
    newest: 'Newest',
    highest_stake: 'Highest stake',
    lowest_stake: 'Lowest stake',
    ending_soon: 'Ending soon',
};

const TIME_CONTROL_LABELS: Record<TimeControl, string> = {
    blitz: 'Blitz',
    rapid: 'Rapid',
    classical: 'Classical',
};

const TIME_CONTROL_OPTIONS: TimeControl[] = ['blitz', 'rapid', 'classical'];

const STAKE_INPUT_DEBOUNCE_MS = 400;

function activeFilterCount(filters: ListingFiltersType): number {
    let count = 0;

    if (filters.stake_min !== null) {
        count++;
    }

    if (filters.stake_max !== null) {
        count++;
    }

    if (filters.skill_min !== null) {
        count++;
    }

    if (filters.skill_max !== null) {
        count++;
    }

    if (filters.time_control.length > 0) {
        count++;
    }

    if (filters.region) {
        count++;
    }

    if (filters.language) {
        count++;
    }

    return count;
}

function visit(filters: ListingFiltersType) {
    router.get(listingsIndex().url, buildListingsQuery(filters), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

export function ListingFiltersBar({ filters, sorts }: Props) {
    const isMobile = useIsMobile();
    const count = activeFilterCount(filters);
    const showTimeControlChips = gameSupports(filters.game, 'time_control');

    const updateSort = (sort: ListingSort) => {
        visit({ ...filters, sort });
    };

    const removeFilter = (key: keyof ListingFiltersType) => {
        const next = { ...filters };

        if (key === 'time_control') {
            next.time_control = [];
        } else if (key === 'sort' || key === 'game') {
            return;
        } else {
            (next[key] as unknown) = null;
        }

        visit(next);
    };

    const removeTimeControl = (value: TimeControl) => {
        visit({
            ...filters,
            time_control: filters.time_control.filter((tc) => tc !== value),
        });
    };

    const clearAll = () => {
        visit({
            game: filters.game,
            stake_min: null,
            stake_max: null,
            skill_min: null,
            skill_max: null,
            time_control: [],
            region: null,
            language: null,
            sort: filters.sort,
        });
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-3">
                <Select
                    value={filters.sort}
                    onValueChange={(value) => updateSort(value as ListingSort)}
                >
                    <SelectTrigger className="w-40 shrink-0">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {sorts.map((sort) => (
                            <SelectItem key={sort} value={sort}>
                                {SORT_LABELS[sort]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {!isMobile && (
                    <>
                        <StakeAmountInput filters={filters} />

                        {showTimeControlChips && (
                            <ToggleGroup
                                type="multiple"
                                variant="outline"
                                value={filters.time_control}
                                onValueChange={(value: string[]) =>
                                    visit({
                                        ...filters,
                                        time_control: value as TimeControl[],
                                    })
                                }
                                className="hidden flex-wrap md:flex"
                            >
                                {TIME_CONTROL_OPTIONS.map((tc) => (
                                    <ToggleGroupItem
                                        key={tc}
                                        value={tc}
                                        aria-label={TIME_CONTROL_LABELS[tc]}
                                        className="rounded-full px-4 py-2"
                                    >
                                        {TIME_CONTROL_LABELS[tc]}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        )}
                    </>
                )}

                <div className="ml-auto flex items-center gap-2">
                    <ListingsMoreMenu />
                    <ListingFilters filters={filters} activeCount={count} />
                </div>
            </div>

            {isMobile && <StakeAmountInput filters={filters} fullWidth />}

            {count > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                    {filters.stake_min !== null && (
                        <ActiveChip
                            label={`Min $${filters.stake_min}`}
                            onRemove={() => removeFilter('stake_min')}
                        />
                    )}
                    {filters.stake_max !== null && (
                        <ActiveChip
                            label={`Max $${filters.stake_max}`}
                            onRemove={() => removeFilter('stake_max')}
                        />
                    )}
                    {filters.skill_min !== null && (
                        <ActiveChip
                            label={`Skill ${filters.skill_min}+`}
                            onRemove={() => removeFilter('skill_min')}
                        />
                    )}
                    {filters.skill_max !== null && (
                        <ActiveChip
                            label={`Skill up to ${filters.skill_max}`}
                            onRemove={() => removeFilter('skill_max')}
                        />
                    )}
                    {filters.time_control.map((tc) => (
                        <ActiveChip
                            key={tc}
                            label={TIME_CONTROL_LABELS[tc]}
                            onRemove={() => removeTimeControl(tc)}
                        />
                    ))}
                    {filters.region && (
                        <ActiveChip
                            label={filters.region}
                            onRemove={() => removeFilter('region')}
                        />
                    )}
                    {filters.language && (
                        <ActiveChip
                            label={filters.language}
                            onRemove={() => removeFilter('language')}
                        />
                    )}
                    <button
                        type="button"
                        onClick={clearAll}
                        className="ml-1 cursor-pointer text-xs font-medium text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
                    >
                        Clear all
                    </button>
                </div>
            )}
        </div>
    );
}

interface StakeAmountInputProps {
    filters: ListingFiltersType;
    fullWidth?: boolean;
}

/** "Up to $X | USDT" compound input. Debounces `stake_max` updates so we
 *  don't fire a router.get on every keystroke. */
function StakeAmountInput({ filters, fullWidth }: StakeAmountInputProps) {
    const [value, setValue] = useState<string>(
        filters.stake_max !== null ? String(filters.stake_max) : '',
    );
    const lastCommitted = useRef<string>(value);

    useEffect(() => {
        if (value === lastCommitted.current) {
            return;
        }

        const handle = window.setTimeout(() => {
            lastCommitted.current = value;

            visit({
                ...filters,
                stake_max: value === '' ? null : Number(value),
            });
        }, STAKE_INPUT_DEBOUNCE_MS);

        return () => window.clearTimeout(handle);
    }, [value, filters]);

    return (
        <div
            className={`h-9 items-center rounded-md border border-border/60 bg-card/60 transition-[color,box-shadow] focus-within:border-primary/40 focus-within:ring-2 focus-within:ring-primary/25 hover:border-border ${
                fullWidth ? 'flex w-full' : 'inline-flex'
            }`}
        >
            <input
                type="number"
                inputMode="decimal"
                min={0}
                max={100000}
                placeholder="Up to $"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                aria-label="Maximum stake"
                className={`[appearance:textfield] bg-transparent px-3 text-sm outline-none placeholder:text-muted-foreground [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none ${
                    fullWidth ? 'min-w-0 flex-1' : 'w-32'
                }`}
            />
            <span className="h-5 w-px shrink-0 bg-border/60" aria-hidden />
            <CurrencyDropdown />
        </div>
    );
}

function CurrencyDropdown() {
    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Select currency"
                    className="inline-flex h-full cursor-pointer items-center gap-1.5 rounded-r-md px-3 text-sm font-medium text-foreground transition-colors outline-none hover:text-primary"
                >
                    <CurrencyBadge currency={DEFAULT_CURRENCY} />
                    USDT
                    <ChevronDown className="size-3.5 opacity-60" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                sideOffset={6}
                className="w-44 rounded-xl border-border/60 bg-card/95 backdrop-blur-md"
            >
                {CURRENCIES.map((currency) => (
                    <DropdownMenuItem
                        key={currency.id}
                        disabled={!currency.available}
                        className="flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-2 text-sm text-muted-foreground transition-colors duration-150 ease-out focus:bg-primary/10 focus:text-foreground"
                    >
                        <CurrencyBadge currency={currency.id} />
                        <span>{currency.id}</span>
                        {!currency.available && (
                            <span className="ml-auto rounded-full bg-background/80 px-2 py-0.5 text-[10px] tracking-wide text-muted-foreground uppercase">
                                Soon
                            </span>
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function CurrencyBadge({ currency }: { currency: string }) {
    const styles =
        currency === 'USDT'
            ? 'bg-success/15 text-success'
            : 'bg-muted text-muted-foreground';

    return (
        <span
            className={`inline-flex size-5 items-center justify-center rounded-full text-[11px] font-bold ${styles}`}
            aria-hidden
        >
            {CURRENCIES.find((c) => c.id === currency)?.symbol ?? '?'}
        </span>
    );
}

interface ActiveChipProps {
    label: string;
    onRemove: () => void;
}

function ActiveChip({ label, onRemove }: ActiveChipProps) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-3 py-1 text-xs font-medium text-foreground">
            {label}
            <button
                type="button"
                onClick={onRemove}
                aria-label={`Remove ${label} filter`}
                className="-mr-1 inline-flex size-4 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-primary"
            >
                <X className="size-3" />
            </button>
        </span>
    );
}
