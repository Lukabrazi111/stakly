import { Link, useForm, usePage } from '@inertiajs/react';
import { AlertCircle, Crown, Link2 } from 'lucide-react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { PageMeta } from '@/components/site/page-meta';
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
import { useT } from '@/lib/i18n';
import { edit as linkedAccountsEdit } from '@/routes/linked-accounts';
import {
    index as listingsIndex,
    mine as listingsMine,
    store as storeListing,
} from '@/routes/listings';
import type { ChessProvider, ListingCreateProps, TimeControl } from '@/types';

// Create-listing is chess-only today (no FACEIT/Steam profile clients exist —
// that's M15). The form's platform field uses the narrow `ChessProvider`
// rather than the wider `ListingPlatform` to keep the picker honest.
const PLATFORM_LABEL: Record<ChessProvider, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
};

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
    activeListingsCount,
    maxActiveListings,
    linkedPlatforms,
}: ListingCreateProps) {
    const t = useT();
    const { auth } = usePage().props;
    const hasChessLink = Boolean(auth.user?.has_chess_link);
    const atCap = activeListingsCount >= maxActiveListings;
    // Default the picker to the user's first verified platform (chess.com
    // comes first because it's alphabetically lower; either is fine when
    // only one is linked). When both providers are linked, render the picker.
    const defaultPlatform: ChessProvider = linkedPlatforms[0] ?? 'chess_com';
    const showPlatformPicker = linkedPlatforms.length > 1;

    const { data, setData, post, processing, errors } = useForm<{
        game: string;
        platform: ChessProvider;
        stake_amount: string;
        skill_min: string;
        skill_max: string;
        time_control: TimeControl[];
        region: string;
        language: string[];
        duration_hours: number;
    }>({
        game: 'chess',
        platform: defaultPlatform,
        stake_amount: '',
        skill_min: '',
        skill_max: '',
        time_control: ['blitz'],
        region: regions[0] ?? 'Global',
        language: [],
        duration_hours: durations.includes(24) ? 24 : (durations[0] ?? 24),
    });

    const stakeNumber =
        data.stake_amount === '' ? 0 : Number(data.stake_amount);
    const balanceNumber = Number(balance);
    const exceedsBalance = stakeNumber > balanceNumber;
    const hasTimeControl = data.time_control.length > 0;
    const canSubmit =
        !processing &&
        !atCap &&
        !exceedsBalance &&
        data.stake_amount !== '' &&
        stakeNumber > 0 &&
        hasTimeControl;

    // Linked-account gate (M8 Phase 5). The viewer hit this page without a
    // verified chess provider → swap the form for a notice card. Server
    // re-checks in `CreateListingAction` so a hand-crafted POST also fails.
    if (!hasChessLink) {
        return (
            <SiteLayout>
                <PageMeta
                    title={t('Create a listing')}
                    description={t('Post a new chess staking listing.')}
                    noindex
                />

                <div className="mx-auto max-w-2xl px-4 py-10 md:py-14">
                    <header className="mb-8">
                        <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                            {t('Create a listing')}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {t(
                                'One more step before you can post on the marketplace.',
                            )}
                        </p>
                    </header>

                    <div className="flex flex-col items-start gap-4 rounded-2xl border border-border/60 bg-card p-6">
                        <div className="inline-flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Link2 className="size-5" aria-hidden="true" />
                        </div>
                        <div className="flex flex-col gap-1">
                            <h2 className="font-display text-xl font-bold tracking-tight">
                                {t('Link a chess account first')}
                            </h2>
                            <p className="text-sm leading-relaxed text-muted-foreground">
                                {t(
                                    'Stakly verifies match outcomes against your chess.com or Lichess account. Link one to post listings and take matches — it takes about a minute.',
                                )}
                            </p>
                        </div>
                        <Button variant="gradient" size="pill" asChild>
                            <Link href={linkedAccountsEdit().url}>
                                {t('Link chess.com or Lichess')}
                            </Link>
                        </Button>
                    </div>
                </div>
            </SiteLayout>
        );
    }

    return (
        <SiteLayout>
            <PageMeta
                title={t('Create a listing')}
                description={t('Post a new chess staking listing.')}
                noindex
            />

            <div className="mx-auto max-w-2xl px-4 py-10 md:py-14">
                <header className="mb-8">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        {t('Create a listing')}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t(
                            'Set your terms — opponents will pick yours from the marketplace.',
                        )}
                    </p>
                </header>

                {atCap && (
                    <div
                        role="status"
                        className="mb-8 flex items-start gap-3 rounded-xl border border-warning/40 bg-warning/10 p-4 text-warning"
                    >
                        <AlertCircle
                            className="size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <div className="flex-1">
                            <p className="text-sm font-medium text-foreground">
                                {t("You're at the :max-listing cap", {
                                    max: maxActiveListings,
                                })}
                            </p>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {t(
                                    'Cancel one of your active listings (Open or Paused) before creating another, or wait for one to settle.',
                                )}{' '}
                                <Link
                                    href={listingsMine().url}
                                    className="font-medium text-primary underline-offset-2 transition-colors hover:text-primary/80 hover:underline"
                                >
                                    {t('Go to My listings →')}
                                </Link>
                            </p>
                        </div>
                    </div>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(storeListing().url);
                    }}
                    className="space-y-8"
                >
                    {/* Game (chess only in v1) */}
                    <FormSection title={t('Game')}>
                        <div className="flex items-center gap-3 rounded-xl border border-glow p-4">
                            <div className="inline-flex size-10 items-center justify-center rounded-lg bg-gradient-primary">
                                <Crown className="size-5 text-primary-foreground" />
                            </div>
                            <div>
                                <div className="font-semibold text-foreground">
                                    {t('Chess')}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {t('More games coming soon.')}
                                </div>
                            </div>
                        </div>
                    </FormSection>

                    {/* Platform — shown only when the user has both providers
                        verified. Auto-selected to the only-linked one
                        otherwise (silent). */}
                    {showPlatformPicker && (
                        <FormSection title={t('Platform')}>
                            <ToggleGroup
                                type="single"
                                value={data.platform}
                                onValueChange={(value) => {
                                    if (
                                        value === 'chess_com' ||
                                        value === 'lichess'
                                    ) {
                                        setData('platform', value);
                                    }
                                }}
                                className="grid grid-cols-2 gap-2"
                            >
                                {linkedPlatforms.map((p) => (
                                    <ToggleGroupItem
                                        key={p}
                                        value={p}
                                        className="h-12 rounded-xl border border-border/60 data-[state=on]:border-primary data-[state=on]:bg-primary/10 data-[state=on]:text-foreground"
                                    >
                                        {PLATFORM_LABEL[p]}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {t(
                                    'Match outcome will be verified against :platform.',
                                    { platform: PLATFORM_LABEL[data.platform] },
                                )}
                            </p>
                            <InputError message={errors.platform} />
                        </FormSection>
                    )}

                    {/* Stake */}
                    <FormSection title={t('Stake')}>
                        <div className="space-y-2">
                            <Label htmlFor="stake_amount">
                                {t('Amount in USDT')}
                            </Label>
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
                                <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-medium text-muted-foreground">
                                    USDT
                                </span>
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {t('Available:')}{' '}
                                <span
                                    className={
                                        exceedsBalance
                                            ? 'font-medium text-destructive'
                                            : 'font-medium text-foreground'
                                    }
                                >
                                    ${balanceNumber.toFixed(2)} USDT
                                </span>
                            </div>
                            {exceedsBalance && (
                                <p className="text-xs text-destructive">
                                    {t('Stake exceeds your available balance.')}
                                </p>
                            )}
                            <InputError message={errors.stake_amount} />
                        </div>
                    </FormSection>

                    {/* Match preferences */}
                    <FormSection title={t('Match preferences')}>
                        <div className="space-y-5">
                            <div className="space-y-2">
                                <Label>{t('Time control')}</Label>
                                <ToggleGroup
                                    type="multiple"
                                    variant="outline"
                                    value={data.time_control}
                                    onValueChange={(value) =>
                                        setData(
                                            'time_control',
                                            value as TimeControl[],
                                        )
                                    }
                                    className="flex flex-wrap"
                                >
                                    {TIME_CONTROL_OPTIONS.map(
                                        ([value, label]) => (
                                            <ToggleGroupItem
                                                key={value}
                                                value={value}
                                                className="rounded-full px-5 py-2"
                                            >
                                                {t(label)}
                                            </ToggleGroupItem>
                                        ),
                                    )}
                                </ToggleGroup>
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'Pick at least one — the opponent picks which to play.',
                                    )}
                                </p>
                                <InputError message={errors.time_control} />
                            </div>

                            <div className="space-y-2">
                                <Label>{t('Skill range (Elo)')}</Label>
                                <div className="flex items-center gap-3">
                                    <Input
                                        type="number"
                                        min={0}
                                        max={3500}
                                        placeholder={t('Any min')}
                                        value={data.skill_min}
                                        onChange={(e) =>
                                            setData('skill_min', e.target.value)
                                        }
                                        className="flex-1"
                                        aria-label={t('Minimum Elo')}
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        {t('to')}
                                    </span>
                                    <Input
                                        type="number"
                                        min={0}
                                        max={3500}
                                        placeholder={t('Any max')}
                                        value={data.skill_max}
                                        onChange={(e) =>
                                            setData('skill_max', e.target.value)
                                        }
                                        className="flex-1"
                                        aria-label={t('Maximum Elo')}
                                    />
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {t('Leave blank to match any skill.')}
                                </p>
                                <InputError message={errors.skill_min} />
                                <InputError message={errors.skill_max} />
                            </div>
                        </div>
                    </FormSection>

                    {/* Audience */}
                    <FormSection title={t('Audience')}>
                        <div className="space-y-5">
                            <div className="space-y-2">
                                <Label htmlFor="region">{t('Region')}</Label>
                                <Select
                                    value={data.region}
                                    onValueChange={(value) =>
                                        setData('region', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="region"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {regions.map((region) => (
                                            <SelectItem
                                                key={region}
                                                value={region}
                                            >
                                                {region}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.region} />
                            </div>
                            <div className="space-y-2">
                                <Label>{t('Languages')}</Label>
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
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'Pick one or more, or leave empty for any language.',
                                    )}
                                </p>
                                <InputError message={errors.language} />
                            </div>
                        </div>
                    </FormSection>

                    {/* Duration */}
                    <FormSection title={t('Listing expires after')}>
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
                                        <SelectItem
                                            key={hours}
                                            value={String(hours)}
                                        >
                                            {hours === 1
                                                ? t('1 hour')
                                                : t(':hours hours', { hours })}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'The listing auto-expires if nobody takes it. Your stake is refunded automatically.',
                                )}
                            </p>
                            <InputError message={errors.duration_hours} />
                        </div>
                    </FormSection>

                    <div className="flex items-center justify-between gap-3 pt-2">
                        <Button type="button" variant="ghost" asChild>
                            <Link href={listingsIndex().url}>
                                {t('Cancel')}
                            </Link>
                        </Button>
                        <Button
                            type="submit"
                            variant="gradient"
                            size="pill"
                            disabled={!canSubmit}
                        >
                            {processing ? t('Creating…') : t('Create listing')}
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
            <h2 className="mb-3 font-display text-base font-semibold text-foreground">
                {title}
            </h2>
            {children}
        </section>
    );
}
