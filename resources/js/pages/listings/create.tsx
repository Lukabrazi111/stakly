import { Link, useForm } from '@inertiajs/react';
import { AlertCircle, Globe, Link2, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { ChessFormatFilter } from '@/components/listings/chess-format-filter';
import { ChessSkillRangeFilter } from '@/components/listings/chess-skill-range-filter';
import { Cs2SkillRangeFilter } from '@/components/listings/cs2-skill-range-filter';
import { DealSummary } from '@/components/listings/deal-summary';
import { GamePicker } from '@/components/listings/game-picker';
import { ListingPreviewCard } from '@/components/listings/listing-preview-card';
import { OptionRadioGroup } from '@/components/listings/option-radio-group';
import { SegmentedOption } from '@/components/listings/segmented-option';
import {
    ChessComLogo,
    FaceitLogo,
    LichessLogo,
} from '@/components/shared/platform-logos';
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
import type { GameId } from '@/config/games';
import SiteLayout from '@/layouts/site-layout';
import { useT } from '@/lib/i18n';
import { edit as linkedAccountsEdit } from '@/routes/linked-accounts';
import {
    index as listingsIndex,
    mine as listingsMine,
    store as storeListing,
} from '@/routes/listings';
import type { ListingCreateProps, ListingPlatform, TimeControl } from '@/types';
import type { GameTile } from '@/types/home';

const PROVIDER_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
    faceit: 'FACEIT',
    steam: 'Steam',
};

type ChessProvider = Extract<ListingPlatform, 'chess_com' | 'lichess'>;

function isChessProvider(platform: ListingPlatform): platform is ChessProvider {
    return platform === 'chess_com' || platform === 'lichess';
}

const PLATFORM_ICON: Record<ChessProvider, ReactNode> = {
    chess_com: <ChessComLogo className="size-5" />,
    lichess: <LichessLogo className="size-5" />,
};

/**
 * Picks a sensible default platform for a game: prefer one the user is already
 * verified on, else fall back to the first provider the game requires.
 */
function defaultPlatformForGame(
    game: GameId,
    requirementsByGame: ListingCreateProps['requirementsByGame'],
    linkedPlatforms: ListingPlatform[],
): ListingPlatform {
    const providers = requirementsByGame[game]?.providers ?? [];
    const linked = providers.find((p) => linkedPlatforms.includes(p));

    return linked ?? providers[0] ?? 'chess_com';
}

function defaultGameFor(
    games: readonly GameTile[],
    requirementsByGame: ListingCreateProps['requirementsByGame'],
): GameId {
    const active = games.filter((g) => g.status === 'active');

    const verified = active.find(
        (g) => requirementsByGame[g.slug as GameId]?.verified,
    );

    if (verified) {
        return verified.slug as GameId;
    }

    // Nothing linked yet — push toward CS2 (newer integration) when it's in
    // the active catalog. Falls back to the first active game otherwise.
    const cs2 = active.find((g) => g.slug === 'cs2');

    return (cs2?.slug ?? active[0]?.slug ?? 'chess') as GameId;
}

function formatTeamSizeLabel(teamSize: number): string {
    return `${teamSize}v${teamSize}`;
}

// Default to the largest = the game's "headline" mode (5v5 for CS2 once
// Wingman adds [2, 5]). Server contract guarantees a non-empty list, but
// the empty fallback keeps a partial Inertia hydration from crashing the
// form.
function defaultTeamSizeFor(allowedSizes: number[]): number {
    return allowedSizes.length > 0 ? Math.max(...allowedSizes) : 1;
}

export default function ListingsCreate({
    balance,
    regions,
    languages,
    durations,
    activeListingsCount,
    maxActiveListings,
    linkedPlatforms,
    games,
    requirementsByGame,
    feeRate,
}: ListingCreateProps) {
    const t = useT();
    const atCap = activeListingsCount >= maxActiveListings;

    const initialGame = defaultGameFor(games.data, requirementsByGame);
    const initialPlatform = defaultPlatformForGame(
        initialGame,
        requirementsByGame,
        linkedPlatforms,
    );
    const initialAllowedTeamSizes = requirementsByGame[initialGame]
        ?.allowed_team_sizes ?? [1];
    const initialTeamSize = defaultTeamSizeFor(initialAllowedTeamSizes);
    const initialCreatorSide: 'a' | 'b' | null =
        initialTeamSize > 1 ? 'a' : null;

    const { data, setData, post, processing, errors } = useForm<{
        game: GameId;
        platform: ListingPlatform;
        stake_amount: string;
        skill_min: string;
        skill_max: string;
        time_control: TimeControl[];
        region: string;
        language: string[];
        duration_hours: number;
        team_size: number;
        creator_side: 'a' | 'b' | null;
        is_public: boolean;
    }>({
        game: initialGame,
        platform: initialPlatform,
        stake_amount: '',
        skill_min: '',
        skill_max: '',
        time_control: initialGame === 'chess' ? ['blitz'] : [],
        region: regions[0] ?? 'Global',
        language: [],
        duration_hours: durations.includes(24) ? 24 : (durations[0] ?? 24),
        team_size: initialTeamSize,
        creator_side: initialCreatorSide,
        is_public: true,
    });

    const requirements = requirementsByGame[data.game];
    const isGameVerified = requirements?.verified ?? false;
    const requiredProviders = requirements?.providers ?? [];
    const allowedTeamSizes = requirements?.allowed_team_sizes ?? [1];

    const linkedChessProviders = linkedPlatforms.filter(isChessProvider);
    const showChessPlatformPicker =
        data.game === 'chess' && linkedChessProviders.length > 1;

    const stakeNumber =
        data.stake_amount === '' ? 0 : Number(data.stake_amount);
    const balanceNumber = Number(balance);
    const exceedsBalance = stakeNumber > balanceNumber;
    const hasTimeControl =
        data.game !== 'chess' || data.time_control.length > 0;
    const canSubmit =
        !processing &&
        !atCap &&
        !exceedsBalance &&
        isGameVerified &&
        data.stake_amount !== '' &&
        stakeNumber > 0 &&
        hasTimeControl;

    const handleGameChange = (next: GameId) => {
        const platform = defaultPlatformForGame(
            next,
            requirementsByGame,
            linkedPlatforms,
        );
        const nextAllowedTeamSizes = requirementsByGame[next]
            ?.allowed_team_sizes ?? [1];
        const nextTeamSize = defaultTeamSizeFor(nextAllowedTeamSizes);
        const nextCreatorSide: 'a' | 'b' | null = nextTeamSize > 1 ? 'a' : null;

        setData((prev) => ({
            ...prev,
            game: next,
            platform,
            // Cross-game skill metric semantics differ (chess Elo vs FACEIT
            // ELO). Reset on switch so a value entered for one game doesn't
            // get reinterpreted for the other.
            skill_min: '',
            skill_max: '',
            // `time_control` is chess-only — clear when switching away so the
            // backend stores null instead of a stale `['blitz']` placeholder
            // on CS2 / future-game listings.
            time_control: next === 'chess' ? prev.time_control : [],
            // M34 — reset team_size + creator_side to the new game's defaults.
            // is_public is the creator's choice and persists across switches.
            team_size: nextTeamSize,
            creator_side: nextCreatorSide,
        }));
    };

    const handleTeamSizeChange = (next: number) => {
        setData((prev) => ({
            ...prev,
            team_size: next,
            creator_side: next > 1 ? (prev.creator_side ?? 'a') : null,
        }));
    };

    return (
        <SiteLayout>
            <PageMeta
                title={t('Create a listing')}
                description={t('Post a new staking listing.')}
                noindex
            />

            <div className="mx-auto max-w-5xl px-4 py-10 md:py-14">
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

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(storeListing().url);
                        }}
                        className="space-y-8 rounded-2xl border border-border/60 bg-card/40 p-5 md:p-6"
                    >
                        <FormSection title={t('Game')}>
                            <GamePicker
                                games={games.data}
                                selected={data.game}
                                onSelect={handleGameChange}
                                requirementsByGame={requirementsByGame}
                            />
                            <InputError message={errors.game} />
                        </FormSection>

                        {!isGameVerified ? (
                            <LinkGateNotice
                                requiredProviders={requiredProviders}
                            />
                        ) : (
                            <>
                                {showChessPlatformPicker && (
                                    <FormSection title={t('Platform')}>
                                        <ToggleGroup
                                            type="single"
                                            value={data.platform}
                                            onValueChange={(value) => {
                                                if (
                                                    isChessProvider(
                                                        value as ListingPlatform,
                                                    )
                                                ) {
                                                    setData(
                                                        'platform',
                                                        value as ListingPlatform,
                                                    );
                                                }
                                            }}
                                            className="grid grid-cols-2 gap-2"
                                        >
                                            {linkedChessProviders.map((p) => (
                                                <SegmentedOption
                                                    key={p}
                                                    value={p}
                                                    label={PROVIDER_LABEL[p]}
                                                    icon={PLATFORM_ICON[p]}
                                                />
                                            ))}
                                        </ToggleGroup>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {t(
                                                'Match outcome will be verified against :platform.',
                                                {
                                                    platform:
                                                        PROVIDER_LABEL[
                                                            data.platform
                                                        ],
                                                },
                                            )}
                                        </p>
                                        <InputError message={errors.platform} />
                                    </FormSection>
                                )}

                                {data.game === 'cs2' && (
                                    <FormSection title={t('Platform')}>
                                        <div className="flex items-center gap-3 rounded-xl border border-border/60 bg-card/60 p-4">
                                            <span className="inline-flex size-9 items-center justify-center rounded-lg bg-primary/15 text-primary">
                                                <FaceitLogo className="size-5" />
                                            </span>
                                            <div className="space-y-0.5">
                                                <div className="font-semibold text-foreground">
                                                    FACEIT
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {t(
                                                        'Match outcome will be verified against FACEIT.',
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    </FormSection>
                                )}

                                {allowedTeamSizes.length > 1 && (
                                    <FormSection title={t('Format')}>
                                        <OptionRadioGroup
                                            name="team_size"
                                            ariaLabel={t('Format')}
                                            value={String(data.team_size)}
                                            onChange={(value) =>
                                                handleTeamSizeChange(
                                                    Number(value),
                                                )
                                            }
                                            options={allowedTeamSizes.map(
                                                (size) => ({
                                                    value: String(size),
                                                    label: formatTeamSizeLabel(
                                                        size,
                                                    ),
                                                }),
                                            )}
                                        />
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {data.team_size === 1
                                                ? t(
                                                      'Direct 1v1 match — no lobby.',
                                                  )
                                                : t(
                                                      'Team lobby — pick your side, recruit teammates, ready up together.',
                                                  )}
                                        </p>
                                        <InputError
                                            message={errors.team_size}
                                        />
                                    </FormSection>
                                )}

                                {data.team_size > 1 && (
                                    <FormSection title={t('Your side')}>
                                        <OptionRadioGroup
                                            name="creator_side"
                                            ariaLabel={t('Your side')}
                                            value={data.creator_side ?? 'a'}
                                            onChange={(value) => {
                                                if (
                                                    value === 'a' ||
                                                    value === 'b'
                                                ) {
                                                    setData(
                                                        'creator_side',
                                                        value,
                                                    );
                                                }
                                            }}
                                            options={[
                                                {
                                                    value: 'a',
                                                    label: t('Team A'),
                                                },
                                                {
                                                    value: 'b',
                                                    label: t('Team B'),
                                                },
                                            ]}
                                        />
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {t(
                                                "You'll be auto-joined to slot 1 of this team. Teammates join the empty slots from the lobby page.",
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.creator_side}
                                        />
                                    </FormSection>
                                )}

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
                                                    setData(
                                                        'stake_amount',
                                                        e.target.value,
                                                    )
                                                }
                                                className="pr-16"
                                                aria-invalid={
                                                    exceedsBalance || undefined
                                                }
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
                                                {t(
                                                    'Stake exceeds your available balance.',
                                                )}
                                            </p>
                                        )}
                                        <InputError
                                            message={errors.stake_amount}
                                        />
                                    </div>
                                </FormSection>

                                <FormSection title={t('Match preferences')}>
                                    <div className="space-y-5">
                                        {data.game === 'chess' && (
                                            <>
                                                <ChessFormatFilter
                                                    value={data.time_control}
                                                    onChange={(next) =>
                                                        setData(
                                                            'time_control',
                                                            next,
                                                        )
                                                    }
                                                    error={errors.time_control}
                                                />
                                                <ChessSkillRangeFilter
                                                    min={data.skill_min}
                                                    max={data.skill_max}
                                                    onMinChange={(next) =>
                                                        setData(
                                                            'skill_min',
                                                            next,
                                                        )
                                                    }
                                                    onMaxChange={(next) =>
                                                        setData(
                                                            'skill_max',
                                                            next,
                                                        )
                                                    }
                                                    errors={{
                                                        min: errors.skill_min,
                                                        max: errors.skill_max,
                                                    }}
                                                />
                                            </>
                                        )}

                                        {data.game === 'cs2' && (
                                            <Cs2SkillRangeFilter
                                                min={data.skill_min}
                                                max={data.skill_max}
                                                onMinChange={(next) =>
                                                    setData('skill_min', next)
                                                }
                                                onMaxChange={(next) =>
                                                    setData('skill_max', next)
                                                }
                                                errors={{
                                                    min: errors.skill_min,
                                                    max: errors.skill_max,
                                                }}
                                            />
                                        )}
                                    </div>
                                </FormSection>

                                <FormSection title={t('Audience')}>
                                    <div className="space-y-5">
                                        <div className="space-y-2">
                                            <Label htmlFor="region">
                                                {t('Region')}
                                            </Label>
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
                                            <InputError
                                                message={errors.region}
                                            />
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
                                            <InputError
                                                message={errors.language}
                                            />
                                        </div>
                                    </div>
                                </FormSection>

                                <FormSection title={t('Visibility')}>
                                    <ToggleGroup
                                        type="single"
                                        value={
                                            data.is_public
                                                ? 'public'
                                                : 'private'
                                        }
                                        onValueChange={(value) => {
                                            if (value === 'public') {
                                                setData('is_public', true);
                                            } else if (value === 'private') {
                                                setData('is_public', false);
                                            }
                                        }}
                                        className="grid grid-cols-1 gap-2 sm:grid-cols-2"
                                    >
                                        <SegmentedOption
                                            value="public"
                                            icon={<Globe className="size-5" />}
                                            label={t('Public')}
                                            description={t(
                                                'Listed in the marketplace for anyone matching your skill range.',
                                            )}
                                        />
                                        <SegmentedOption
                                            value="private"
                                            icon={<Lock className="size-5" />}
                                            label={t('Private')}
                                            description={t(
                                                'Hidden — only people with your invite link can see it.',
                                            )}
                                        />
                                    </ToggleGroup>
                                    <InputError message={errors.is_public} />
                                </FormSection>

                                <FormSection title={t('Listing expires after')}>
                                    <div className="space-y-2">
                                        <Select
                                            value={String(data.duration_hours)}
                                            onValueChange={(value) =>
                                                setData(
                                                    'duration_hours',
                                                    Number(value),
                                                )
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
                                                            : t(
                                                                  ':hours hours',
                                                                  {
                                                                      hours,
                                                                  },
                                                              )}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                'The listing auto-expires if nobody takes it. Your stake is refunded automatically.',
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.duration_hours}
                                        />
                                    </div>
                                </FormSection>

                                <div className="flex items-center justify-between gap-3 pt-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        asChild
                                    >
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
                                        {processing
                                            ? t('Creating…')
                                            : data.team_size > 1
                                              ? t('Open lobby')
                                              : t('Create listing')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </form>

                    <aside className="space-y-4 lg:sticky lg:top-24">
                        <h2 className="font-display text-lg font-bold text-foreground">
                            {t('Listing preview')}
                        </h2>
                        <ListingPreviewCard
                            game={data.game}
                            platform={data.platform}
                            teamSize={data.team_size}
                            stakeAmount={data.stake_amount}
                            skillMin={data.skill_min}
                            skillMax={data.skill_max}
                            timeControl={data.time_control}
                            region={data.region}
                            language={data.language}
                            durationHours={data.duration_hours}
                            isPublic={data.is_public}
                            verified={isGameVerified}
                        />
                        <DealSummary
                            stake={stakeNumber}
                            teamSize={data.team_size}
                            feeRate={feeRate}
                        />
                    </aside>
                </div>
            </div>
        </SiteLayout>
    );
}

interface LinkGateNoticeProps {
    requiredProviders: ListingPlatform[];
}

function LinkGateNotice({ requiredProviders }: LinkGateNoticeProps) {
    const t = useT();
    const providerNames = requiredProviders
        .map((p) => PROVIDER_LABEL[p] ?? p)
        .join(t(' or '));

    return (
        <div className="flex flex-col items-start gap-4 rounded-2xl border border-border/60 bg-card p-6">
            <div className="inline-flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Link2 className="size-5" aria-hidden="true" />
            </div>
            <div className="flex flex-col gap-1">
                <h2 className="font-display text-xl font-bold tracking-tight">
                    {t('Link :provider to post', { provider: providerNames })}
                </h2>
                <p className="text-sm leading-relaxed text-muted-foreground">
                    {t(
                        'We verify match outcomes through your linked account. Linking :provider lets you post and take listings on this game — about a minute of setup.',
                        { provider: providerNames },
                    )}
                </p>
            </div>
            <Button variant="gradient" size="pill" asChild>
                <Link href={linkedAccountsEdit().url}>
                    {t('Link :provider', { provider: providerNames })}
                </Link>
            </Button>
        </div>
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
