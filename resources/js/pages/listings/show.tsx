import { Link, router, usePage } from '@inertiajs/react';
import { Clock, Globe, Languages, Trophy } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { SellerTrustMeta } from '@/components/listings/seller-trust-meta';
import { VerifiedPlatformChip } from '@/components/listings/verified-platform-chip';
import { TeamPlayLobbyView } from '@/components/lobby/team-play-lobby-view';
import { VerificationChip } from '@/components/profile/verification-chip';
import { BackLink } from '@/components/site/back-link';
import { PageMeta } from '@/components/site/page-meta';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useInitials } from '@/hooks/use-initials';
import SiteLayout from '@/layouts/site-layout';
import { useT } from '@/lib/i18n';
import {
    formatSkillRange,
    formatTimeRemaining,
    getTimeUrgency,
    timeControlLabel,
} from '@/lib/listings-format';
import { edit as linkedAccountsEdit } from '@/routes/linked-accounts';
import {
    cancel as cancelRoute,
    index as listingsIndex,
    take as takeRoute,
} from '@/routes/listings';
import { index as matchesIndex, show as matchShow } from '@/routes/matches';
import { show as userShow } from '@/routes/users';
import { deposit as walletDeposit } from '@/routes/wallet';
import type { ListingPlatform, ListingShowProps, ListingStatus } from '@/types';

const STATUS_LABEL: Record<ListingStatus, string> = {
    open: 'Open',
    taken: 'Taken',
    expired: 'Expired',
    cancelled: 'Cancelled',
};

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
    // M15 placeholders.
    faceit: 'FACEIT',
    steam: 'Steam',
};

const STATUS_TONE: Record<ListingStatus, string> = {
    open: 'border-success/40 bg-success/10 text-success',
    taken: 'border-primary/40 bg-primary/10 text-primary',
    expired: 'border-border/60 bg-muted text-muted-foreground',
    cancelled: 'border-destructive/40 bg-destructive/10 text-destructive',
};

export default function ListingShow({
    listing,
    match,
    lobby,
    messages,
}: ListingShowProps) {
    // M34 P3.1 Slice B.1 — team-play listings render the lobby UI on the
    // canonical listing URL. Controller sends `lobby` + `messages` only when
    // `team_size > 1`, so presence of `lobby` is the discriminator. The
    // chess branch below remains untouched (decoupled UI surfaces).
    if (lobby && messages) {
        return <TeamPlayBranch lobby={lobby} messages={messages} />;
    }

    return <ChessBranch listing={listing} match={match} />;
}

interface TeamPlayBranchProps {
    lobby: NonNullable<ListingShowProps['lobby']>;
    messages: NonNullable<ListingShowProps['messages']>;
}

function TeamPlayBranch({ lobby, messages }: TeamPlayBranchProps) {
    const t = useT();

    return (
        <SiteLayout>
            <PageMeta
                title={t(':teamSize v :teamSize lobby — :stake USDT', {
                    teamSize: lobby.team_size,
                    stake: lobby.stake_amount.toFixed(0),
                })}
                description={t('Team-play lobby for :stake USDT.', {
                    stake: lobby.stake_amount.toFixed(0),
                })}
                noindex
            />

            <div className="mx-auto max-w-7xl px-4 py-10 md:px-6 md:py-14">
                <div className="mb-6">
                    <BackLink fallback={listingsIndex().url} />
                </div>

                <TeamPlayLobbyView lobby={lobby} messages={messages} />
            </div>
        </SiteLayout>
    );
}

interface ChessBranchProps {
    listing: ListingShowProps['listing'];
    match: ListingShowProps['match'];
}

function ChessBranch({ listing, match }: ChessBranchProps) {
    const t = useT();
    const getInitials = useInitials();
    const { auth } = usePage().props;
    const [cancelOpen, setCancelOpen] = useState(false);

    const isOwner = auth.user?.id === listing.creator.id;
    const isOpen = listing.status === 'open';
    const canCancel = isOwner && isOpen;
    // M23 Phase 1 — escalate Expires tone in tiers (warning < 1h,
    // destructive < 15m / expired), matching the marketplace row from
    // M22 Phase 2. Only the match-details `Expires` cell uses this; the
    // stake-card branches still rely on `isOpen` / `match` for their copy.
    const urgency = getTimeUrgency(listing.expires_at);
    const expiresTone =
        urgency === 'expired' || urgency === 'critical'
            ? 'text-destructive'
            : urgency === 'warning'
              ? 'text-warning'
              : undefined;

    // Month-year pill mirrors the profile-page hero pattern (`profile-header`).
    const joinedDate = listing.creator.member_since
        ? new Intl.DateTimeFormat('en-US', {
              month: 'short',
              year: 'numeric',
          }).format(new Date(listing.creator.member_since))
        : null;

    // "Posted on {date}" foot line — absolute date with locale formatting.
    // `created_at` is non-null in practice (resource sends `?->toIso8601String()`
    // only as a defensive nullable cast), so the conditional is belt-and-braces.
    const postedDate = listing.created_at
        ? new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(
              new Date(listing.created_at),
          )
        : null;

    // M23 Phase 2 — pot / fee / payout math. Mirrors `match/show.tsx` so
    // a pre-take viewer sees the exact same breakdown they'd see on the
    // match page once they've committed. Fee is charged at settlement on
    // the *pot*, not per-player, so display the absolute number (Bob gets
    // surprised either way; better to tell him up front).
    const pot = listing.stake_amount * 2;
    const fee = pot * listing.fee_rate;
    const winnerPayout = pot - fee;

    const hasEnoughBalance =
        (auth.user?.usdt_balance ?? 0) >= listing.stake_amount;
    // M8 Phase 5 Slice B platform-specific take-gate: the viewer must have
    // verified the LISTING'S platform, not just "any chess provider." A user
    // with only chess.com linked can't take a Lichess listing because they
    // literally couldn't play the match. Server re-checks via TakeListingAction.
    // CS2 (FACEIT) and Dota 2 (Steam) listings are dev-seed only today —
    // nobody has those platforms linked, so the check correctly falls to false.
    // Widening cast satisfies TS — `linked_platforms` is narrowed to chess-
    // only, but runtime `.includes()` is identical: a chess-only array can
    // never contain `faceit`/`steam`, so CS2/Dota listings correctly
    // resolve to `hasMatchingPlatform = false`.
    const hasMatchingPlatform = Boolean(
        (
            auth.user?.linked_platforms as readonly (typeof listing.platform)[]
        )?.includes(listing.platform),
    );
    // Owner-inactive frontend gate (M6 Phase 6.5). Mirrors the server-side
    // check in `GameMatchController::take` — defense in depth, plus better
    // UX: the visitor sees up-front that this listing can't be taken right
    // now, instead of clicking Take and getting a redirect with a toast.
    // Server remains the authoritative enforcement.
    const isOwnerInactive = isOpen && !listing.creator.is_active_mode;
    // M37 — the viewer is already in an in-flight match for this listing's
    // game, so the server would reject a take (one active match per game).
    // Surface it up front; `GameMatchController::take` stays authoritative.
    const isBusyInGame = Boolean(
        auth.user?.in_flight_games?.includes(listing.game),
    );

    const [takeOpen, setTakeOpen] = useState(false);
    const [takeProcessing, setTakeProcessing] = useState(false);

    const handleCancel = () => {
        router.delete(cancelRoute({ listing: listing.id }).url, {
            preserveScroll: true,
            onSuccess: () => setCancelOpen(false),
        });
    };

    const handleTake = () => {
        setTakeProcessing(true);
        router.post(
            takeRoute({ listing: listing.id }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setTakeProcessing(false);
                    setTakeOpen(false);
                },
            },
        );
    };

    const timeControlText = timeControlLabel(listing.time_control, t);
    const platformLabel = PLATFORM_LABEL[listing.platform];
    const metaTitle = t(":creator's $:stake match — chess on :platform", {
        creator: listing.creator.name,
        stake: listing.stake_amount,
        platform: platformLabel,
    });
    const skillFragment =
        listing.skill_min !== null && listing.skill_max !== null
            ? ' ' +
              t(':min–:max Elo.', {
                  min: listing.skill_min,
                  max: listing.skill_max,
              })
            : '';
    const completionFragment =
        listing.creator.completion_rate_30d !== null &&
        listing.creator.settled_lifetime > 0
            ? ' ' +
              t(':rate% completion over :count matches.', {
                  rate: listing.creator.completion_rate_30d,
                  count: listing.creator.settled_lifetime,
              })
            : '';
    const metaDescription = `${t(
        "Take @:username's $:stake USDT :timeControl chess match on :platform.",
        {
            username: listing.creator.username,
            stake: listing.stake_amount,
            timeControl: timeControlText.toLowerCase(),
            platform: platformLabel,
        },
    )}${skillFragment}${completionFragment} ${t('Both stakes escrowed.')}`;

    return (
        <SiteLayout>
            <PageMeta title={metaTitle} description={metaDescription} />

            <div className="mx-auto max-w-6xl px-4 py-10 md:px-6 md:py-14">
                <div className="mb-6">
                    <BackLink fallback={listingsIndex().url} />
                </div>

                <div className="grid gap-8 md:grid-cols-3">
                    {/* Left: profile + listing details. `min-w-0` is the
                        important bit at mobile — grid items default to
                        `min-width: auto` (content-min-size), so any wide
                        child (chip strip, breakdown row) would grow the
                        cell past viewport. With `min-w-0` the cell can
                        shrink and inner `overflow-x-auto` / wrapping
                        actually works. */}
                    <div className="min-w-0 space-y-6 md:col-span-2">
                        {/* Creator card — identity + trust + verification chips + bio.
                            M23 Phase 1 lifted this from a sparse avatar+name+region
                            block to a profile-card analogue: SellerTrustMeta lives
                            inline under the name (Bybit-style, mirrors the listing
                            row from M22), the chip strip below carries the listing's
                            required platform alongside the creator's own linked
                            accounts + a Joined-{month-year} pill, and bio renders
                            when present. Region + Language moved out of here into
                            the match-details card — they describe the listing, not
                            the identity. The status badge stays outside the link
                            since it's informational, not navigational. */}
                        <section className="rounded-2xl border border-border/60 bg-card p-6">
                            <div className="flex items-start gap-4">
                                <Link
                                    href={
                                        userShow({
                                            user: listing.creator.username,
                                        }).url
                                    }
                                    className="flex min-w-0 flex-1 items-center gap-4 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                                >
                                    <Avatar className="size-16 shrink-0 overflow-hidden rounded-full">
                                        <AvatarImage
                                            src={
                                                listing.creator
                                                    .avatar_thumb_url ??
                                                undefined
                                            }
                                            alt={listing.creator.name}
                                        />
                                        <AvatarFallback className="bg-gradient-primary text-xl font-semibold text-primary-foreground">
                                            {getInitials(listing.creator.name)}
                                        </AvatarFallback>
                                    </Avatar>

                                    <div className="min-w-0 flex-1">
                                        <h1 className="truncate font-display text-2xl font-bold tracking-tight text-foreground transition-colors hover:text-primary">
                                            {listing.creator.name}
                                        </h1>
                                        <div className="mt-1">
                                            <SellerTrustMeta
                                                rate={
                                                    listing.creator
                                                        .completion_rate_30d
                                                }
                                                settled={
                                                    listing.creator
                                                        .settled_lifetime
                                                }
                                                verifiedProviders={
                                                    listing.creator
                                                        .verified_providers
                                                }
                                            />
                                        </div>
                                    </div>
                                </Link>

                                {!isOpen && (
                                    <span
                                        className={`inline-flex shrink-0 items-center rounded-full border px-3 py-1 text-xs font-medium ${STATUS_TONE[listing.status]}`}
                                    >
                                        {t(STATUS_LABEL[listing.status])}
                                    </span>
                                )}
                            </div>

                            {/* Chip strip — listing's required platform, then
                                the creator's linked accounts (click-out to
                                external profiles), then Joined pill. Wraps
                                on every viewport: the listing-detail page
                                shows up to 4 chips (platform + 2 linked
                                accounts + joined), and horizontal-scrolling
                                on mobile clipped chips mid-pill against the
                                card edge. Wrapping lets all chips read at a
                                glance at the cost of a slightly taller card
                                — fine for a detail page. */}
                            <div className="mt-5 flex flex-wrap items-center gap-2">
                                <VerifiedPlatformChip
                                    platform={listing.platform}
                                />
                                {listing.creator.linked_accounts.map(
                                    (account) => (
                                        <VerificationChip
                                            key={account.provider}
                                            provider={account.provider}
                                            username={account.username}
                                        />
                                    ),
                                )}
                                {joinedDate && (
                                    <span className="inline-flex shrink-0 items-center rounded-full border border-border/60 bg-card px-3 py-1 text-xs font-medium text-muted-foreground">
                                        {t('Joined :date', {
                                            date: joinedDate,
                                        })}
                                    </span>
                                )}
                            </div>

                            {listing.creator.bio && (
                                <p className="mt-5 max-w-prose text-sm leading-relaxed whitespace-pre-line text-foreground/90">
                                    {listing.creator.bio}
                                </p>
                            )}
                        </section>

                        {/* Listing details card */}
                        <section className="rounded-2xl border border-border/60 bg-card p-6">
                            <h2 className="mb-5 font-display text-lg font-semibold text-foreground">
                                {t('Match details')}
                            </h2>
                            <dl className="grid gap-5 sm:grid-cols-2">
                                <Detail
                                    label={t('Skill range')}
                                    icon={<Trophy className="size-4" />}
                                    value={formatSkillRange(
                                        listing.skill_min,
                                        listing.skill_max,
                                        t,
                                    )}
                                />
                                <Detail
                                    label={t('Time control')}
                                    icon={<Clock className="size-4" />}
                                    value={timeControlText}
                                />
                                <Detail
                                    label={t('Expires')}
                                    icon={<Clock className="size-4" />}
                                    value={formatTimeRemaining(
                                        listing.expires_at,
                                        t,
                                    )}
                                    valueClass={expiresTone}
                                />
                                {listing.region && (
                                    <Detail
                                        label={t('Region')}
                                        icon={<Globe className="size-4" />}
                                        value={listing.region}
                                    />
                                )}
                                {listing.language &&
                                    listing.language.length > 0 && (
                                        <Detail
                                            label={t('Language')}
                                            icon={
                                                <Languages className="size-4" />
                                            }
                                            value={listing.language.join(', ')}
                                        />
                                    )}
                            </dl>

                            {postedDate && (
                                <p className="mt-6 text-xs text-muted-foreground">
                                    {t('Posted :date', { date: postedDate })}
                                </p>
                            )}
                        </section>
                    </div>

                    {/* Right: booking widget. `min-w-0` mirrors the left
                        column — without it the stake card's breakdown rows
                        could grow the grid cell past viewport on mobile. */}
                    <aside className="min-w-0 md:col-span-1">
                        <div className="rounded-2xl border border-border/60 bg-card p-6 md:sticky md:top-16">
                            <div className="text-center">
                                <div className="text-[11px] tracking-widest text-muted-foreground uppercase">
                                    {t('Stake')}
                                </div>
                                <div className="mt-2 text-gradient-primary font-display text-5xl leading-none font-bold">
                                    ${listing.stake_amount}
                                </div>
                                <div className="mt-1 text-xs text-muted-foreground">
                                    USDT
                                </div>
                            </div>

                            {/* M23 Phase 2 — pot breakdown. Only shown to
                                non-owner viewers on Open listings; owners
                                already know the numbers (they set them),
                                and Taken/Expired/Cancelled hide the pre-take
                                math (the match page owns the post-take view).
                                Layout: dense label-value rows; payout uses
                                the gradient accent to climax the math. */}
                            {isOpen && !isOwner && (
                                <>
                                    <dl className="mt-6 space-y-2.5">
                                        <StakeRow
                                            label={t('Your stake')}
                                            value={`$${listing.stake_amount}`}
                                        />
                                        <StakeRow
                                            label={t('Opponent stake')}
                                            value={`$${listing.stake_amount}`}
                                        />
                                        <StakeRow
                                            label={t('Pot total')}
                                            value={`$${pot}`}
                                            bold
                                        />
                                        <StakeRow
                                            label={t('Platform fee')}
                                            value={`−$${fee.toFixed(2)}`}
                                            muted
                                        />
                                        <StakeRow
                                            label={t('Winner payout')}
                                            value={`$${winnerPayout.toFixed(2)}`}
                                            accent
                                        />
                                    </dl>
                                    <div className="my-6 border-t border-border/60" />
                                </>
                            )}

                            {/* Participant CTA — replaces Take/status block for the
                                two players (creator + taker) once the listing is
                                Taken and a match exists. `match` is only sent to
                                participants by the backend, so its presence is
                                the sole gate. */}
                            {match && (
                                <div className="mt-6">
                                    <Button
                                        variant="gradient"
                                        size="pill"
                                        className="w-full"
                                        asChild
                                    >
                                        <Link
                                            href={
                                                matchShow({ match: match.id })
                                                    .url
                                            }
                                        >
                                            {t('View match →')}
                                        </Link>
                                    </Button>
                                </div>
                            )}

                            {/* Take area — hidden for owners (cancel area below
                                handles their case) and for participants (View
                                match above replaces it). Branches by auth +
                                balance + status + owner active mode. `mt-6`
                                is conditional — when the M23 Phase 2 pot
                                breakdown is rendered above, its trailing
                                divider provides the separator and the
                                wrapper sits flush; otherwise the wrapper
                                needs its own top margin from the hero. */}
                            {!isOwner && !match && (
                                <div
                                    className={`flex flex-col gap-3 ${
                                        isOpen ? '' : 'mt-6'
                                    }`}
                                >
                                    {isOwnerInactive && (
                                        <>
                                            <Button
                                                variant="gradient"
                                                size="pill"
                                                disabled
                                                className="w-full"
                                            >
                                                {t('Player currently inactive')}
                                            </Button>
                                            <Link
                                                href={listingsIndex().url}
                                                className="text-center text-xs text-muted-foreground transition-colors hover:text-foreground"
                                            >
                                                {t('Browse other listings →')}
                                            </Link>
                                        </>
                                    )}

                                    {isOpen &&
                                        !isOwnerInactive &&
                                        auth.user &&
                                        isBusyInGame && (
                                            <>
                                                <Button
                                                    variant="gradient"
                                                    size="pill"
                                                    disabled
                                                    className="w-full"
                                                >
                                                    {t('Already in a match')}
                                                </Button>
                                                <Link
                                                    href={matchesIndex().url}
                                                    className="text-center text-xs text-muted-foreground transition-colors hover:text-foreground"
                                                >
                                                    {t('View your matches →')}
                                                </Link>
                                            </>
                                        )}

                                    {isOpen &&
                                        !isOwnerInactive &&
                                        auth.user &&
                                        !isBusyInGame &&
                                        !hasMatchingPlatform && (
                                            // M23 Phase 2 — single clickable
                                            // outline pill, matches the
                                            // listings-row TakeButton wrong-
                                            // platform branch. Replaces the
                                            // old disabled-gradient + tiny
                                            // separate link (two surfaces for
                                            // the same action).
                                            <Button
                                                variant="outline"
                                                size="pill"
                                                asChild
                                                className="w-full rounded-full"
                                            >
                                                <Link
                                                    href={
                                                        linkedAccountsEdit().url
                                                    }
                                                >
                                                    {t(
                                                        'Link :platform to take',
                                                        {
                                                            platform:
                                                                PLATFORM_LABEL[
                                                                    listing
                                                                        .platform
                                                                ],
                                                        },
                                                    )}
                                                </Link>
                                            </Button>
                                        )}

                                    {isOpen &&
                                        !isOwnerInactive &&
                                        auth.user &&
                                        !isBusyInGame &&
                                        hasMatchingPlatform &&
                                        hasEnoughBalance && (
                                            <Dialog
                                                open={takeOpen}
                                                onOpenChange={setTakeOpen}
                                            >
                                                <DialogTrigger asChild>
                                                    <Button
                                                        variant="gradient"
                                                        size="pill"
                                                        className="w-full"
                                                    >
                                                        {t('Take')}
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            {t(
                                                                'Take this match?',
                                                            )}
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            {t(
                                                                "You're about to stake :amount on this match. Once it starts, your stake is locked until the match settles, you and your opponent open a dispute, or the 4-hour confirmation window expires.",
                                                                {
                                                                    amount: `$${listing.stake_amount} USDT`,
                                                                },
                                                            )}
                                                        </DialogDescription>
                                                    </DialogHeader>
                                                    <DialogFooter>
                                                        <Button
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setTakeOpen(
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            {t('Cancel')}
                                                        </Button>
                                                        <Button
                                                            variant="gradient"
                                                            onClick={handleTake}
                                                            disabled={
                                                                takeProcessing
                                                            }
                                                        >
                                                            {takeProcessing
                                                                ? t(
                                                                      'Processing…',
                                                                  )
                                                                : t(
                                                                      'Confirm & take',
                                                                  )}
                                                        </Button>
                                                    </DialogFooter>
                                                </DialogContent>
                                            </Dialog>
                                        )}

                                    {isOpen &&
                                        !isOwnerInactive &&
                                        auth.user &&
                                        !isBusyInGame &&
                                        hasMatchingPlatform &&
                                        !hasEnoughBalance && (
                                            <>
                                                <Button
                                                    variant="gradient"
                                                    size="pill"
                                                    disabled
                                                    className="w-full"
                                                >
                                                    {t('Insufficient balance')}
                                                </Button>
                                                <Link
                                                    href={walletDeposit().url}
                                                    className="text-center text-xs text-muted-foreground transition-colors hover:text-foreground"
                                                >
                                                    {t(
                                                        'Deposit USDT to take this match →',
                                                    )}
                                                </Link>
                                            </>
                                        )}

                                    {isOpen &&
                                        !isOwnerInactive &&
                                        !auth.user && (
                                            <Button
                                                variant="gradient"
                                                size="pill"
                                                className="w-full"
                                                asChild
                                            >
                                                <Link href="/?auth=login">
                                                    {t('Log in to take')}
                                                </Link>
                                            </Button>
                                        )}

                                    {!isOpen && (
                                        <Button
                                            variant="gradient"
                                            size="pill"
                                            disabled
                                            className="w-full"
                                        >
                                            {t(STATUS_LABEL[listing.status])}
                                        </Button>
                                    )}
                                </div>
                            )}

                            {canCancel && (
                                <>
                                    <div className="my-6 border-t border-border/60" />
                                    <Dialog
                                        open={cancelOpen}
                                        onOpenChange={setCancelOpen}
                                    >
                                        <DialogTrigger asChild>
                                            <Button
                                                variant="outline"
                                                size="default"
                                                className="w-full rounded-full border-destructive/30 bg-transparent text-destructive shadow-none hover:border-destructive/50 hover:bg-destructive/10 hover:text-destructive"
                                            >
                                                {t('Cancel listing')}
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    {t('Cancel this listing?')}
                                                </DialogTitle>
                                                <DialogDescription>
                                                    {t(
                                                        "Your :amount stake will be refunded immediately. This can't be undone.",
                                                        {
                                                            amount: `$${listing.stake_amount} USDT`,
                                                        },
                                                    )}
                                                </DialogDescription>
                                            </DialogHeader>
                                            <DialogFooter>
                                                <Button
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setCancelOpen(false)
                                                    }
                                                >
                                                    {t('Keep listing')}
                                                </Button>
                                                <Button
                                                    variant="destructive"
                                                    onClick={handleCancel}
                                                >
                                                    {t('Cancel & refund')}
                                                </Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                </>
                            )}
                        </div>
                    </aside>
                </div>
            </div>
        </SiteLayout>
    );
}

interface DetailProps {
    label: string;
    icon: ReactNode;
    value: string;
    valueClass?: string;
}

function Detail({ label, icon, value, valueClass }: DetailProps) {
    return (
        <div>
            <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd
                className={`mt-1.5 inline-flex items-center gap-2 text-sm font-medium text-foreground ${valueClass ?? ''}`}
            >
                {icon}
                {value}
            </dd>
        </div>
    );
}

/**
 * Single label-value row in the M23 Phase 2 pot breakdown. Variants:
 *   - default: plain text-foreground value (the per-player stake lines)
 *   - `bold`: heavier value for the pot total (the sum the breakdown adds to)
 *   - `muted`: faded value for the fee row (a deduction, not a player input)
 *   - `accent`: gradient text for the winner-payout climax — same idiom as
 *     `MatchInfoCard`'s `Row` so the math reads consistently with the
 *     match page once Bob commits.
 */
function StakeRow({
    label,
    value,
    bold,
    muted,
    accent,
}: {
    label: string;
    value: string;
    bold?: boolean;
    muted?: boolean;
    accent?: boolean;
}) {
    const valueClass = accent
        ? 'text-gradient-primary font-display text-base font-semibold'
        : bold
          ? 'text-base font-semibold text-foreground'
          : muted
            ? 'text-sm text-muted-foreground'
            : 'text-sm text-foreground';

    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className={valueClass}>{value}</dd>
        </div>
    );
}
