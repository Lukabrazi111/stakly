import { Head, Link, router, usePage } from '@inertiajs/react';
import { Clock, Globe, Languages, Trophy } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
import {
    formatSkillRange,
    formatTimeControls,
    formatTimeRemaining,
    isEndingSoon,
} from '@/lib/listings-format';
import { cancel as cancelRoute, index as listingsIndex, take as takeRoute } from '@/routes/listings';
import { show as userShow } from '@/routes/users';
import { deposit as walletDeposit } from '@/routes/wallet';
import type { ListingShowProps, ListingStatus } from '@/types';

const STATUS_LABEL: Record<ListingStatus, string> = {
    open: 'Open',
    taken: 'Taken',
    expired: 'Expired',
    cancelled: 'Cancelled',
};

const STATUS_TONE: Record<ListingStatus, string> = {
    open: 'border-success/40 bg-success/10 text-success',
    taken: 'border-primary/40 bg-primary/10 text-primary',
    expired: 'border-border/60 bg-muted text-muted-foreground',
    cancelled: 'border-destructive/40 bg-destructive/10 text-destructive',
};

export default function ListingShow({ listing }: ListingShowProps) {
    const getInitials = useInitials();
    const { auth } = usePage().props;
    const [cancelOpen, setCancelOpen] = useState(false);

    const isOwner = auth.user?.id === listing.creator.id;
    const isOpen = listing.status === 'open';
    const canCancel = isOwner && isOpen;
    const endingSoon = isEndingSoon(listing.expires_at);
    const hasEnoughBalance = (auth.user?.usdt_balance ?? 0) >= listing.stake_amount;

    const [takeOpen, setTakeOpen] = useState(false);
    const [takeProcessing, setTakeProcessing] = useState(false);

    const handleCancel = () => {
        router.delete(cancelRoute(listing.id).url, {
            preserveScroll: true,
            onSuccess: () => setCancelOpen(false),
        });
    };

    const handleTake = () => {
        setTakeProcessing(true);
        router.post(
            takeRoute(listing.id).url,
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

    return (
        <SiteLayout>
            <Head
                title={`${listing.creator.name} · $${listing.stake_amount} ${formatTimeControls(listing.time_control)}`}
            />

            <div className="mx-auto max-w-6xl px-4 py-10 md:px-6 md:py-14">
                <div className="mb-6">
                    <Link
                        href={listingsIndex().url}
                        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm transition-colors"
                    >
                        ← Back to listings
                    </Link>
                </div>

                <div className="grid gap-8 md:grid-cols-3">
                    {/* Left: profile + listing details */}
                    <div className="space-y-6 md:col-span-2">
                        {/* Creator card — avatar + name + meta link to user profile;
                            status badge stays outside the link as informational. */}
                        <section className="border-border/60 bg-card/60 rounded-2xl border p-6">
                            <div className="flex items-center gap-4">
                                <Link
                                    href={userShow(listing.creator.username).url}
                                    className="focus-visible:ring-primary focus-visible:ring-offset-background flex min-w-0 flex-1 items-center gap-4 rounded-lg focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                                >
                                    <Avatar className="size-16 overflow-hidden rounded-full">
                                        <AvatarFallback className="bg-gradient-primary text-primary-foreground text-xl font-semibold">
                                            {getInitials(listing.creator.name)}
                                        </AvatarFallback>
                                    </Avatar>

                                    <div className="min-w-0 flex-1">
                                        <h1 className="font-display text-foreground hover:text-primary truncate text-2xl font-bold tracking-tight transition-colors">
                                            {listing.creator.name}
                                        </h1>
                                        <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                            {listing.region && (
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Globe className="size-3.5" />
                                                    {listing.region}
                                                </span>
                                            )}
                                            {listing.language && listing.language.length > 0 && (
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Languages className="size-3.5" />
                                                    {listing.language.join(', ')}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </Link>

                                {!isOpen && (
                                    <span
                                        className={`inline-flex shrink-0 items-center rounded-full border px-3 py-1 text-xs font-medium ${STATUS_TONE[listing.status]}`}
                                    >
                                        {STATUS_LABEL[listing.status]}
                                    </span>
                                )}
                            </div>
                        </section>

                        {/* Listing details card */}
                        <section className="border-border/60 bg-card/60 rounded-2xl border p-6">
                            <h2 className="font-display text-foreground mb-5 text-lg font-semibold">
                                Match details
                            </h2>
                            <dl className="grid gap-5 sm:grid-cols-2">
                                <Detail
                                    label="Skill range"
                                    icon={<Trophy className="size-4" />}
                                    value={formatSkillRange(
                                        listing.skill_min,
                                        listing.skill_max,
                                    )}
                                />
                                <Detail
                                    label="Time control"
                                    icon={<Clock className="size-4" />}
                                    value={formatTimeControls(listing.time_control)}
                                />
                                <Detail
                                    label="Expires"
                                    icon={<Clock className="size-4" />}
                                    value={formatTimeRemaining(listing.expires_at)}
                                    valueClass={endingSoon ? 'text-warning' : undefined}
                                />
                                {listing.region && (
                                    <Detail
                                        label="Region"
                                        icon={<Globe className="size-4" />}
                                        value={listing.region}
                                    />
                                )}
                            </dl>
                        </section>
                    </div>

                    {/* Right: booking widget */}
                    <aside className="md:col-span-1">
                        <div className="border-border/60 bg-card/60 rounded-2xl border p-6 md:sticky md:top-24">
                            <div className="text-center">
                                <div className="text-muted-foreground text-[11px] uppercase tracking-widest">
                                    Stake
                                </div>
                                <div className="font-display text-gradient-primary mt-2 text-5xl font-bold leading-none">
                                    ${listing.stake_amount}
                                </div>
                                <div className="text-muted-foreground mt-1 text-xs">
                                    USDT
                                </div>
                            </div>

                            {/* Take area — hidden for owners (cancel area below
                                handles their case). Branches by auth + balance + status. */}
                            {!isOwner && (
                                <div className="mt-6 flex flex-col gap-3">
                                    {isOpen && auth.user && hasEnoughBalance && (
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
                                                    Take
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogHeader>
                                                    <DialogTitle>
                                                        Take this match?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        You&apos;re about to stake{' '}
                                                        <span className="text-foreground font-semibold">
                                                            ${listing.stake_amount} USDT
                                                        </span>{' '}
                                                        on this match. Once it
                                                        starts, your stake is locked
                                                        until the match settles, you
                                                        and your opponent open a
                                                        dispute, or the 4-hour
                                                        confirmation window expires.
                                                    </DialogDescription>
                                                </DialogHeader>
                                                <DialogFooter>
                                                    <Button
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setTakeOpen(false)
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                    <Button
                                                        variant="gradient"
                                                        onClick={handleTake}
                                                        disabled={takeProcessing}
                                                    >
                                                        {takeProcessing
                                                            ? 'Processing…'
                                                            : 'Confirm & take'}
                                                    </Button>
                                                </DialogFooter>
                                            </DialogContent>
                                        </Dialog>
                                    )}

                                    {isOpen &&
                                        auth.user &&
                                        !hasEnoughBalance && (
                                            <>
                                                <Button
                                                    variant="gradient"
                                                    size="pill"
                                                    disabled
                                                    className="w-full"
                                                >
                                                    Insufficient balance
                                                </Button>
                                                <Link
                                                    href={walletDeposit().url}
                                                    className="text-muted-foreground hover:text-foreground text-center text-xs transition-colors"
                                                >
                                                    Deposit USDT to take this match →
                                                </Link>
                                            </>
                                        )}

                                    {isOpen && !auth.user && (
                                        <Button
                                            variant="gradient"
                                            size="pill"
                                            className="w-full"
                                            asChild
                                        >
                                            <Link href="/?auth=login">
                                                Log in to take
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
                                            {STATUS_LABEL[listing.status]}
                                        </Button>
                                    )}
                                </div>
                            )}

                            {canCancel && (
                                <>
                                    <div className="border-border/60 my-6 border-t" />
                                    <Dialog
                                        open={cancelOpen}
                                        onOpenChange={setCancelOpen}
                                    >
                                        <DialogTrigger asChild>
                                            <Button
                                                variant="outline"
                                                size="default"
                                                className="border-destructive/30 bg-transparent text-destructive hover:bg-destructive/10 hover:text-destructive hover:border-destructive/50 w-full shadow-none rounded-full"
                                            >
                                                Cancel listing
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Cancel this listing?
                                                </DialogTitle>
                                                <DialogDescription>
                                                    Your{' '}
                                                    <span className="text-foreground font-semibold">
                                                        ${listing.stake_amount} USDT
                                                    </span>{' '}
                                                    stake will be refunded
                                                    immediately. This can&apos;t
                                                    be undone.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <DialogFooter>
                                                <Button
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setCancelOpen(false)
                                                    }
                                                >
                                                    Keep listing
                                                </Button>
                                                <Button
                                                    variant="destructive"
                                                    onClick={handleCancel}
                                                >
                                                    Cancel &amp; refund
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
            <dt className="text-muted-foreground text-xs uppercase tracking-wide">
                {label}
            </dt>
            <dd
                className={`text-foreground mt-1.5 inline-flex items-center gap-2 text-sm font-medium ${valueClass ?? ''}`}
            >
                {icon}
                {value}
            </dd>
        </div>
    );
}
