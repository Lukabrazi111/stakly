import { Head, Link, useForm } from '@inertiajs/react';
import { Crown } from 'lucide-react';
import type { FormEventHandler, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import SiteLayout from '@/layouts/site-layout';
import { index as listingsIndex, store as storeListing } from '@/routes/listings';
import type { ListingCreateProps, TimeControl } from '@/types';

const TIME_CONTROL_OPTIONS: ReadonlyArray<[TimeControl, string]> = [
    ['blitz', 'Blitz'],
    ['rapid', 'Rapid'],
    ['classical', 'Classical'],
];

export default function ListingsCreate({
    balance,
    regions,
    languages,
    durations,
}: ListingCreateProps) {
    const { data, setData, post, processing, errors } = useForm<{
        game: string;
        stake_amount: string;
        skill_min: string;
        skill_max: string;
        time_control: TimeControl[];
        region: string;
        language: string[];
        duration_hours: number;
    }>({
        game: 'chess',
        stake_amount: '',
        skill_min: '',
        skill_max: '',
        time_control: ['blitz'],
        region: regions[0] ?? 'Global',
        language: [],
        duration_hours: durations.includes(24) ? 24 : (durations[0] ?? 24),
    });

    const stakeNumber = data.stake_amount === '' ? 0 : Number(data.stake_amount);
    const balanceNumber = Number(balance);
    const exceedsBalance = stakeNumber > balanceNumber;
    const hasTimeControl = data.time_control.length > 0;
    const canSubmit
        = !processing
        && !exceedsBalance
        && data.stake_amount !== ''
        && stakeNumber > 0
        && hasTimeControl;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(storeListing().url);
    };

    return (
        <SiteLayout>
            <Head title="Create a listing" />

            <div className="mx-auto max-w-2xl px-4 py-10 md:py-14">
                <header className="mb-8">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Create a listing
                    </h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Set your terms — opponents will pick yours from the marketplace.
                    </p>
                </header>

                <form onSubmit={submit} className="space-y-8">
                    {/* Game (chess only in v1) */}
                    <FormSection title="Game">
                        <div className="border-glow flex items-center gap-3 rounded-xl border p-4">
                            <div className="bg-gradient-primary inline-flex size-10 items-center justify-center rounded-lg">
                                <Crown className="text-primary-foreground size-5" />
                            </div>
                            <div>
                                <div className="text-foreground font-semibold">
                                    Chess
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    More games coming soon.
                                </div>
                            </div>
                        </div>
                    </FormSection>

                    {/* Stake */}
                    <FormSection title="Stake">
                        <div className="space-y-2">
                            <Label htmlFor="stake_amount">Amount in USDT</Label>
                            <div className="relative">
                                <Input
                                    id="stake_amount"
                                    name="stake_amount"
                                    type="number"
                                    inputMode="decimal"
                                    min={1}
                                    max={100000}
                                    placeholder="100"
                                    value={data.stake_amount}
                                    onChange={(e) =>
                                        setData('stake_amount', e.target.value)
                                    }
                                    className="pr-16"
                                    aria-invalid={exceedsBalance || undefined}
                                />
                                <span className="text-muted-foreground pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-medium">
                                    USDT
                                </span>
                            </div>
                            <div className="text-muted-foreground text-xs">
                                Available:{' '}
                                <span
                                    className={
                                        exceedsBalance
                                            ? 'text-destructive font-medium'
                                            : 'text-foreground font-medium'
                                    }
                                >
                                    ${balanceNumber.toFixed(2)} USDT
                                </span>
                            </div>
                            {exceedsBalance && (
                                <p className="text-destructive text-xs">
                                    Stake exceeds your available balance.
                                </p>
                            )}
                            <InputError message={errors.stake_amount} />
                        </div>
                    </FormSection>

                    {/* Match preferences */}
                    <FormSection title="Match preferences">
                        <div className="space-y-5">
                            <div className="space-y-2">
                                <Label>Time control</Label>
                                <ToggleGroup
                                    type="multiple"
                                    variant="outline"
                                    value={data.time_control}
                                    onValueChange={(value) =>
                                        setData('time_control', value as TimeControl[])
                                    }
                                    className="flex flex-wrap"
                                >
                                    {TIME_CONTROL_OPTIONS.map(([value, label]) => (
                                        <ToggleGroupItem
                                            key={value}
                                            value={value}
                                            className="rounded-full px-5 py-2"
                                        >
                                            {label}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                                <p className="text-muted-foreground text-xs">
                                    Pick at least one — the opponent picks which to play.
                                </p>
                                <InputError message={errors.time_control} />
                            </div>

                            <div className="space-y-2">
                                <Label>Skill range (Elo)</Label>
                                <div className="flex items-center gap-3">
                                    <Input
                                        type="number"
                                        min={0}
                                        max={3500}
                                        placeholder="Any min"
                                        value={data.skill_min}
                                        onChange={(e) =>
                                            setData('skill_min', e.target.value)
                                        }
                                        className="flex-1"
                                        aria-label="Minimum Elo"
                                    />
                                    <span className="text-muted-foreground text-xs">
                                        to
                                    </span>
                                    <Input
                                        type="number"
                                        min={0}
                                        max={3500}
                                        placeholder="Any max"
                                        value={data.skill_max}
                                        onChange={(e) =>
                                            setData('skill_max', e.target.value)
                                        }
                                        className="flex-1"
                                        aria-label="Maximum Elo"
                                    />
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    Leave blank to match any skill.
                                </p>
                                <InputError message={errors.skill_min} />
                                <InputError message={errors.skill_max} />
                            </div>
                        </div>
                    </FormSection>

                    {/* Audience */}
                    <FormSection title="Audience">
                        <div className="space-y-5">
                            <div className="space-y-2">
                                <Label htmlFor="region">Region</Label>
                                <Select
                                    value={data.region}
                                    onValueChange={(value) => setData('region', value)}
                                >
                                    <SelectTrigger id="region" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {regions.map((region) => (
                                            <SelectItem key={region} value={region}>
                                                {region}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.region} />
                            </div>
                            <div className="space-y-2">
                                <Label>Languages</Label>
                                <ToggleGroup
                                    type="multiple"
                                    variant="outline"
                                    value={data.language}
                                    onValueChange={(value) =>
                                        setData('language', value)
                                    }
                                    className="flex flex-wrap"
                                >
                                    {languages.map((lang) => (
                                        <ToggleGroupItem
                                            key={lang}
                                            value={lang}
                                            className="rounded-full px-4 py-2"
                                        >
                                            {lang}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                                <p className="text-muted-foreground text-xs">
                                    Pick one or more, or leave empty for any language.
                                </p>
                                <InputError message={errors.language} />
                            </div>
                        </div>
                    </FormSection>

                    {/* Duration */}
                    <FormSection title="Listing expires after">
                        <div className="space-y-2">
                            <Select
                                value={String(data.duration_hours)}
                                onValueChange={(value) =>
                                    setData('duration_hours', Number(value))
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {durations.map((hours) => (
                                        <SelectItem key={hours} value={String(hours)}>
                                            {hours === 1 ? '1 hour' : `${hours} hours`}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                The listing auto-expires if nobody takes it. Your stake
                                is refunded automatically.
                            </p>
                            <InputError message={errors.duration_hours} />
                        </div>
                    </FormSection>

                    <div className="flex items-center justify-between gap-3 pt-2">
                        <Button type="button" variant="ghost" asChild>
                            <Link href={listingsIndex().url}>Cancel</Link>
                        </Button>
                        <Button
                            type="submit"
                            variant="gradient"
                            size="pill"
                            disabled={!canSubmit}
                        >
                            {processing ? 'Creating…' : 'Create listing'}
                        </Button>
                    </div>
                </form>
            </div>
        </SiteLayout>
    );
}

interface FormSectionProps {
    title: string;
    children: ReactNode;
}

function FormSection({ title, children }: FormSectionProps) {
    return (
        <section>
            <h2 className="font-display text-foreground mb-3 text-base font-semibold">
                {title}
            </h2>
            {children}
        </section>
    );
}