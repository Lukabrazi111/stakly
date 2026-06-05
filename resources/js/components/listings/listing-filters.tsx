import { router } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { gameSupports } from '@/config/games';
import { useIsMobile } from '@/hooks/use-mobile';
import { useT } from '@/lib/i18n';
import { buildListingsQuery } from '@/lib/listings-query';
import { index as listingsIndex } from '@/routes/listings';
import type {
    ListingFilters as ListingFiltersType,
    TimeControl,
} from '@/types';

interface Props {
    filters: ListingFiltersType;
    activeCount: number;
}

const TIME_CONTROL_OPTIONS: { value: TimeControl; label: string }[] = [
    { value: 'blitz', label: 'Blitz' },
    { value: 'rapid', label: 'Rapid' },
    { value: 'classical', label: 'Classical' },
];

const REGIONS = ['Global', 'EU', 'NA', 'Asia', 'CIS', 'LATAM'];
const LANGUAGES = ['English', 'Russian', 'Spanish', 'German', 'Portuguese'];

// Sentinel because Radix Select forbids empty-string `value` props.
const ANY_VALUE = '__any__';

interface DraftFilters {
    stake_min: string;
    stake_max: string;
    skill_min: string;
    skill_max: string;
    time_control: TimeControl[];
    region: string | null;
    language: string | null;
}

function filtersToDraft(filters: ListingFiltersType): DraftFilters {
    return {
        stake_min: filters.stake_min !== null ? String(filters.stake_min) : '',
        stake_max: filters.stake_max !== null ? String(filters.stake_max) : '',
        skill_min: filters.skill_min !== null ? String(filters.skill_min) : '',
        skill_max: filters.skill_max !== null ? String(filters.skill_max) : '',
        time_control: filters.time_control,
        region: filters.region,
        language: filters.language,
    };
}

/** Filter trigger + responsive content panel: Popover on desktop, Sheet on
 *  mobile. Form remounts on open to re-seed draft state from server filters. */
export function ListingFilters({ filters, activeCount }: Props) {
    const t = useT();
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);

    const trigger = (
        <Button
            variant="ghost"
            size="pill"
            // Bordered-ghost: ghost variant's white text-shadow glow looks busy
            // over a background + border, so override it here.
            className="border border-border/60 hover:border-primary/40 hover:bg-primary/10 hover:[text-shadow:none] data-[state=open]:border-primary/40 data-[state=open]:bg-primary/10"
        >
            <SlidersHorizontal className="size-4" />
            {t('Filters')}
            {activeCount > 0 && (
                <span className="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-xs font-semibold text-primary">
                    {activeCount}
                </span>
            )}
        </Button>
    );

    const form = open && (
        <FilterForm filters={filters} onClose={() => setOpen(false)} />
    );

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetTrigger asChild>{trigger}</SheetTrigger>
                <SheetContent
                    side="right"
                    className="flex w-full flex-col gap-0 border-l border-border/60 bg-card/95 p-0 backdrop-blur-xl sm:max-w-md"
                >
                    <SheetTitle className="sr-only">{t('Filters')}</SheetTitle>
                    <SheetDescription className="sr-only">
                        {t(
                            'Narrow down listings by stake, skill, format, and more.',
                        )}
                    </SheetDescription>
                    {form}
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                align="end"
                sideOffset={8}
                className="w-[420px] rounded-xl border border-border/60 bg-card/95 p-0 backdrop-blur-md"
            >
                {form}
            </PopoverContent>
        </Popover>
    );
}

interface FormProps {
    filters: ListingFiltersType;
    onClose: () => void;
}

function FilterForm({ filters, onClose }: FormProps) {
    const t = useT();
    const [draft, setDraft] = useState<DraftFilters>(() =>
        filtersToDraft(filters),
    );
    const showTimeControl = gameSupports(filters.game, 'time_control');

    const apply = () => {
        const next: ListingFiltersType = {
            game: filters.game,
            stake_min: draft.stake_min === '' ? null : Number(draft.stake_min),
            stake_max: draft.stake_max === '' ? null : Number(draft.stake_max),
            skill_min: draft.skill_min === '' ? null : Number(draft.skill_min),
            skill_max: draft.skill_max === '' ? null : Number(draft.skill_max),
            time_control: draft.time_control,
            region: draft.region,
            language: draft.language,
            sort: filters.sort,
        };

        router.get(listingsIndex().url, buildListingsQuery(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: onClose,
        });
    };

    const reset = () => {
        setDraft({
            stake_min: '',
            stake_max: '',
            skill_min: '',
            skill_max: '',
            time_control: [],
            region: null,
            language: null,
        });
    };

    return (
        <>
            <header className="border-b border-border/60 px-5 py-3">
                <h2 className="font-display text-lg font-bold">
                    {t('Filters')}
                </h2>
            </header>

            <div className="max-h-[60vh] space-y-5 overflow-y-auto px-5 py-5">
                <Field label={t('Stake range (USDT)')}>
                    <RangePair
                        minValue={draft.stake_min}
                        maxValue={draft.stake_max}
                        onMinChange={(v) =>
                            setDraft({ ...draft, stake_min: v })
                        }
                        onMaxChange={(v) =>
                            setDraft({ ...draft, stake_max: v })
                        }
                        max={100000}
                        inputMode="decimal"
                    />
                </Field>

                <Field label={t('Skill range (Elo)')}>
                    <RangePair
                        minValue={draft.skill_min}
                        maxValue={draft.skill_max}
                        onMinChange={(v) =>
                            setDraft({ ...draft, skill_min: v })
                        }
                        onMaxChange={(v) =>
                            setDraft({ ...draft, skill_max: v })
                        }
                        max={3500}
                        inputMode="numeric"
                    />
                </Field>

                {/* Chess-specific. When a second game adapter ships (M15),
                    branch here per `filters.game` with a sibling component
                    (`Cs2FormatFilter`, etc.). Don't extract a generic
                    interface yet — the shape of "what's variable" emerges
                    from the second game, not the first. */}
                {showTimeControl && (
                    <Field label={t('Time control')}>
                        <ToggleGroup
                            type="multiple"
                            variant="outline"
                            value={draft.time_control}
                            onValueChange={(value: string[]) =>
                                setDraft({
                                    ...draft,
                                    time_control: value as TimeControl[],
                                })
                            }
                            className="flex w-full flex-wrap justify-start"
                        >
                            {TIME_CONTROL_OPTIONS.map((opt) => (
                                <ToggleGroupItem
                                    key={opt.value}
                                    value={opt.value}
                                    aria-label={t(opt.label)}
                                    className="rounded-full px-4 py-2"
                                >
                                    {t(opt.label)}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </Field>
                )}

                <Field label={t('Region')}>
                    <Select
                        value={draft.region ?? ANY_VALUE}
                        onValueChange={(value) =>
                            setDraft({
                                ...draft,
                                region: value === ANY_VALUE ? null : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder={t('Any region')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY_VALUE}>
                                {t('Any region')}
                            </SelectItem>
                            {REGIONS.map((region) => (
                                <SelectItem key={region} value={region}>
                                    {region}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field label={t('Language')}>
                    <Select
                        value={draft.language ?? ANY_VALUE}
                        onValueChange={(value) =>
                            setDraft({
                                ...draft,
                                language: value === ANY_VALUE ? null : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder={t('Any language')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY_VALUE}>
                                {t('Any language')}
                            </SelectItem>
                            {LANGUAGES.map((lang) => (
                                <SelectItem key={lang} value={lang}>
                                    {lang}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            </div>

            <footer className="flex gap-3 border-t border-border/60 px-5 py-3">
                <Button
                    variant="ghost"
                    size="pill"
                    onClick={reset}
                    className="flex-1"
                >
                    {t('Reset')}
                </Button>
                <Button
                    variant="gradient"
                    size="pill"
                    onClick={apply}
                    className="flex-1"
                >
                    {t('Apply')}
                </Button>
            </footer>
        </>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="space-y-2">
            <Label className="text-sm font-medium">{label}</Label>
            {children}
        </div>
    );
}

interface RangePairProps {
    minValue: string;
    maxValue: string;
    onMinChange: (value: string) => void;
    onMaxChange: (value: string) => void;
    max: number;
    inputMode: 'numeric' | 'decimal';
}

function RangePair({
    minValue,
    maxValue,
    onMinChange,
    onMaxChange,
    max,
    inputMode,
}: RangePairProps) {
    const t = useT();

    return (
        <div className="flex items-center gap-2">
            <Input
                type="number"
                inputMode={inputMode}
                min={0}
                max={max}
                placeholder={t('Min')}
                value={minValue}
                onChange={(e) => onMinChange(e.target.value)}
                className="flex-1"
            />
            <span className="text-xs text-muted-foreground">{t('to')}</span>
            <Input
                type="number"
                inputMode={inputMode}
                min={0}
                max={max}
                placeholder={t('Max')}
                value={maxValue}
                onChange={(e) => onMaxChange(e.target.value)}
                className="flex-1"
            />
        </div>
    );
}
