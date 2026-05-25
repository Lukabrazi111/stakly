import { Link } from '@inertiajs/react';
import { ChevronRight, ShieldCheck } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { show as userShow } from '@/routes/users';
import type { ListingPlatform, MatchPlayer, TimeControl } from '@/types';

interface MatchInfoCardProps {
    opponent: MatchPlayer;
    stakeEach: number;
    pot: number;
    timeControl: TimeControl[];
    // Match's bound chess provider — drives the capability-indicator row at
    // the bottom of the card. Tells players where the outcome auto-verifies
    // if a dispute is opened.
    platform: ListingPlatform;
    // Pre-computed `pot * (1 - fee_rate)`. Omitted (undefined) when the
    // match is Settled — the SettlementSummary card already breaks down
    // pot / fee / payout for resolved matches, so repeating the payout
    // here would be a third surface for the same number. For Pending /
    // Disputed / ManualReview the row gives players a concrete "what
    // you'd take home if you win" figure rather than forcing pot × 0.9
    // mental math.
    winnerPayout?: number;
}

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    lichess: 'Lichess',
    chess_com: 'chess.com',
};

/**
 * Compact match-parameters card. Bybit-style key:value rows: opponent
 * (clickable to profile), stake-per-player, pot, optional winner payout
 * (during gameplay), time control. Replaces the old separate "Your
 * opponent" + "Match details" cards.
 */
export function MatchInfoCard({
    opponent,
    stakeEach,
    pot,
    timeControl,
    platform,
    winnerPayout,
}: MatchInfoCardProps) {
    const getInitials = useInitials();

    return (
        <section className="rounded-2xl border border-border/60 bg-card/60">
            <header className="border-b border-border/60 px-6 py-4">
                <h2 className="text-sm font-semibold text-foreground">
                    Match info
                </h2>
            </header>
            <dl className="divide-y divide-border/60">
                <Link
                    href={userShow(opponent.username).url}
                    className="group flex items-center justify-between gap-3 px-6 py-4 transition-colors hover:bg-primary/5 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                >
                    <dt className="text-sm text-muted-foreground">Opponent</dt>
                    <dd className="flex items-center gap-2.5">
                        <Avatar className="size-7">
                            <AvatarFallback className="bg-gradient-primary text-[10px] font-semibold text-primary-foreground">
                                {getInitials(opponent.name)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="text-sm font-medium text-foreground">
                            {opponent.name}
                        </span>
                        <ChevronRight className="size-4 text-muted-foreground transition-colors group-hover:text-primary" />
                    </dd>
                </Link>

                <Row label="Stake (each)" value={`$${stakeEach}`} />
                <Row label="Pot" value={`$${pot}`} />
                {winnerPayout !== undefined && (
                    <Row
                        label="Winner payout"
                        value={`$${winnerPayout.toFixed(2)}`}
                        accent
                    />
                )}
                <Row label="Time control" value={timeControl.join(', ')} />

                {/* Capability indicator — tells players where the match
                    auto-verifies if a dispute is opened. Mirrors the
                    `listings.platform` binding from M8 Phase 5 Slice B,
                    so the copy is always accurate (we only auto-verify
                    on the platform the listing was created for). */}
                <div className="flex items-center justify-between gap-3 px-6 py-4">
                    <dt className="text-sm text-muted-foreground">
                        Verification
                    </dt>
                    <dd className="inline-flex items-center gap-1.5 text-sm font-medium text-foreground">
                        <ShieldCheck
                            className="size-4 text-success"
                            strokeWidth={2}
                            aria-hidden="true"
                        />
                        Auto via {PLATFORM_LABEL[platform]}
                    </dd>
                </div>
            </dl>
        </section>
    );
}

function Row({
    label,
    value,
    accent,
}: {
    label: string;
    value: string;
    // `accent` reserved for the winner-payout row — pink gradient brings
    // attention to the "you'd take home this much" figure without
    // shouting (it's still informational, not a CTA).
    accent?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={
                    accent
                        ? 'text-gradient-primary text-sm font-semibold'
                        : 'text-sm font-medium text-foreground'
                }
            >
                {value}
            </dd>
        </div>
    );
}
