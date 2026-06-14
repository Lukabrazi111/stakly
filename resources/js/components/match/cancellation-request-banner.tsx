import { router } from '@inertiajs/react';
import { Clock, Handshake } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import {
    accept as acceptCancellationRoute,
    reject as rejectCancellationRoute,
} from '@/routes/matches/cancellation';
import type { Match } from '@/types';

interface CancellationRequestBannerProps {
    match: Match;
    viewerId: number;
}

/**
 * Light-weight player shape — covers both `MatchPlayer` (1v1 creator /
 * taker) and the team roster entries (which carry `user_id` instead of
 * `id`). Only the requester's display name lands in the banner, so we
 * coerce both shapes to this minimal interface internally.
 */
interface RequesterDisplay {
    id: number;
    name: string;
}

/**
 * Inline banner while a cancellation request is open. Two variants: viewer
 * is the requester (informational waiting state) or someone else (the
 * Accept/Decline-capable opposing-team member, or a non-decision team-mate
 * who sees a waiting state). Reason renders here, not in the chat system
 * message — the system message stays neutral so chat anti-abuse can't be
 * bypassed.
 *
 * Team matches (M34 P6): requester is looked up across `team_a` / `team_b`
 * rosters; viewers on the requester's team see the waiting state but can't
 * accept/reject (policy enforces this server-side too).
 */
export function CancellationRequestBanner({
    match,
    viewerId,
}: CancellationRequestBannerProps) {
    const t = useT();
    const { cancellation, listing } = match;

    if (cancellation.requested_at === null) {
        return null;
    }

    const requesterId = cancellation.requested_by_id;

    if (requesterId === null) {
        return null;
    }

    const isTeam = listing.team_size > 1;
    const requester = resolveRequester(match, requesterId);

    if (requester === null) {
        return null;
    }

    const viewerIsRequester = requesterId === viewerId;

    if (viewerIsRequester) {
        return (
            <RequesterWaitingBanner
                opponentLabel={resolveOpponentLabel(match, requesterId, t)}
                reason={cancellation.reason}
            />
        );
    }

    // Team-match: only opposing-team members can accept/reject. Same-team
    // viewers see a passive "your team-mate proposed cancellation" banner.
    if (isTeam) {
        const viewerSide = sideOfUser(match, viewerId);
        const requesterSide = sideOfUser(match, requesterId);

        if (
            viewerSide !== null &&
            requesterSide !== null &&
            viewerSide === requesterSide
        ) {
            return (
                <TeammateWatchingBanner
                    requester={requester}
                    reason={cancellation.reason}
                />
            );
        }
    }

    return (
        <RespondBanner
            matchId={match.id}
            requester={requester}
            reason={cancellation.reason}
        />
    );
}

function resolveRequester(
    match: Match,
    requesterId: number,
): RequesterDisplay | null {
    // 1v1 path — creator + taker carry the canonical names.
    if (match.creator.id === requesterId) {
        return { id: match.creator.id, name: match.creator.name };
    }

    if (match.taker.id === requesterId) {
        return { id: match.taker.id, name: match.taker.name };
    }

    // Team path — search both rosters.
    const fromRoster =
        match.team_a?.find((p) => p.user_id === requesterId) ??
        match.team_b?.find((p) => p.user_id === requesterId) ??
        null;

    return fromRoster
        ? { id: fromRoster.user_id, name: fromRoster.name }
        : null;
}

function sideOfUser(match: Match, userId: number): 'a' | 'b' | null {
    if (match.team_a?.some((p) => p.user_id === userId)) {
        return 'a';
    }

    if (match.team_b?.some((p) => p.user_id === userId)) {
        return 'b';
    }

    return null;
}

function resolveOpponentLabel(
    match: Match,
    requesterId: number,
    t: (key: string) => string,
): string {
    if (match.listing.team_size > 1) {
        const requesterSide = sideOfUser(match, requesterId);

        if (requesterSide === 'a') {
            return t('Team B');
        }

        if (requesterSide === 'b') {
            return t('Team A');
        }

        return t('the opposing team');
    }

    // 1v1
    return match.creator.id === requesterId
        ? match.taker.name
        : match.creator.name;
}

function RequesterWaitingBanner({
    opponentLabel,
    reason,
}: {
    opponentLabel: string;
    reason: string | null;
}) {
    const t = useT();

    return (
        <section className="mb-6 rounded-2xl border border-warning/40 bg-warning/5 p-5">
            <div className="flex items-start gap-3">
                <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning">
                    <Clock className="size-4" strokeWidth={2} />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold text-foreground">
                        {t('Cancellation request sent')}
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t('Waiting for :name to accept or decline.', {
                            name: opponentLabel,
                        })}
                    </p>
                    {reason !== null && (
                        <ReasonBlock label={t('Your reason')} reason={reason} />
                    )}
                </div>
            </div>
        </section>
    );
}

function TeammateWatchingBanner({
    requester,
    reason,
}: {
    requester: RequesterDisplay;
    reason: string | null;
}) {
    const t = useT();

    return (
        <section className="mb-6 rounded-2xl border border-warning/40 bg-warning/5 p-5">
            <div className="flex items-start gap-3">
                <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning">
                    <Clock className="size-4" strokeWidth={2} />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold text-foreground">
                        {t(':name requested to cancel', {
                            name: requester.name,
                        })}
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Waiting for the opposing team to accept or decline. Only they can act on this — same-team accept would defeat mutual cancellation.',
                        )}
                    </p>
                    {reason !== null && (
                        <ReasonBlock
                            label={t(":name's reason", {
                                name: requester.name,
                            })}
                            reason={reason}
                        />
                    )}
                </div>
            </div>
        </section>
    );
}

function RespondBanner({
    matchId,
    requester,
    reason,
}: {
    matchId: number;
    requester: RequesterDisplay;
    reason: string | null;
}) {
    const t = useT();
    const [processing, setProcessing] = useState<'accept' | 'reject' | null>(
        null,
    );

    const handleAccept = () => {
        setProcessing('accept');
        router.post(
            acceptCancellationRoute({ match: matchId }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setProcessing(null),
            },
        );
    };

    const handleReject = () => {
        setProcessing('reject');
        router.post(
            rejectCancellationRoute({ match: matchId }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setProcessing(null),
            },
        );
    };

    return (
        <section className="mb-6 rounded-2xl border border-warning/40 bg-warning/5 p-5">
            <div className="flex items-start gap-3">
                <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning">
                    <Handshake className="size-4" strokeWidth={2} />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold text-foreground">
                        {t(':name wants to cancel this match', {
                            name: requester.name,
                        })}
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            "If you accept, both stakes are refunded and the match ends with no winner. If you decline, the match continues and :name can't request again for 30 minutes.",
                            { name: requester.name },
                        )}
                    </p>

                    {reason !== null && (
                        <ReasonBlock
                            label={t(":name's reason", {
                                name: requester.name,
                            })}
                            reason={reason}
                        />
                    )}

                    <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                            variant="outline"
                            onClick={handleReject}
                            disabled={processing !== null}
                        >
                            {processing === 'reject'
                                ? t('Declining…')
                                : t('Decline')}
                        </Button>
                        <Button
                            variant="default"
                            onClick={handleAccept}
                            disabled={processing !== null}
                        >
                            {processing === 'accept'
                                ? t('Accepting…')
                                : t('Accept and refund')}
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    );
}

function ReasonBlock({ label, reason }: { label: string; reason: string }) {
    return (
        <div className="mt-3 rounded-lg border border-border/60 bg-card/60 p-3">
            <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 text-sm whitespace-pre-wrap text-foreground">
                {reason}
            </p>
        </div>
    );
}
