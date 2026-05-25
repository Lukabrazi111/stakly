import { router } from '@inertiajs/react';
import { Clock, Handshake } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    accept as acceptCancellationRoute,
    reject as rejectCancellationRoute,
} from '@/routes/matches/cancellation';
import type { Match, MatchPlayer } from '@/types';

interface CancellationRequestBannerProps {
    match: Match;
    viewerId: number;
}

/**
 * Inline banner shown at the top of the Pending action area while a
 * cancellation request is open. Two variants based on viewer perspective:
 *
 *   - Viewer IS the requester  → informational "waiting" state with the
 *                                reason echoed back. No actions; they
 *                                already chose, can't take it back
 *                                (matches Bybit's pattern — KISS until
 *                                someone asks for a withdraw-request flow).
 *   - Viewer is the OTHER side → "Alice wants to cancel" headline + the
 *                                reason in a quoted block + Accept /
 *                                Decline buttons.
 *
 * Reason is rendered inside this structured banner, NOT inside the chat
 * system message — the system message stays neutral ("Alice requested to
 * cancel the match.") so the M13 chat anti-abuse layer can't be bypassed
 * via the cancellation surface.
 */
export function CancellationRequestBanner({
    match,
    viewerId,
}: CancellationRequestBannerProps) {
    const { cancellation, creator, taker } = match;

    if (cancellation.requested_at === null) {
        return null;
    }

    const requesterId = cancellation.requested_by_id;
    const requester: MatchPlayer | null =
        requesterId === creator.id
            ? creator
            : requesterId === taker.id
              ? taker
              : null;

    if (requester === null) {
        return null;
    }

    const viewerIsRequester = requesterId === viewerId;

    return viewerIsRequester ? (
        <RequesterWaitingBanner
            opponent={requester.id === creator.id ? taker : creator}
            reason={cancellation.reason}
        />
    ) : (
        <RespondBanner
            matchId={match.id}
            requester={requester}
            reason={cancellation.reason}
        />
    );
}

function RequesterWaitingBanner({
    opponent,
    reason,
}: {
    opponent: MatchPlayer;
    reason: string | null;
}) {
    return (
        <section className="mb-6 rounded-2xl border border-warning/40 bg-warning/5 p-5">
            <div className="flex items-start gap-3">
                <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning">
                    <Clock className="size-4" strokeWidth={2} />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold text-foreground">
                        Cancellation request sent
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Waiting for {opponent.name} to accept or decline.
                    </p>
                    {reason !== null && (
                        <ReasonBlock label="Your reason" reason={reason} />
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
    requester: MatchPlayer;
    reason: string | null;
}) {
    const [processing, setProcessing] = useState<'accept' | 'reject' | null>(
        null,
    );

    const handleAccept = () => {
        setProcessing('accept');
        router.post(
            acceptCancellationRoute(matchId).url,
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
            rejectCancellationRoute(matchId).url,
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
                        {requester.name} wants to cancel this match
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        If you accept, both stakes are refunded and the match
                        ends with no winner. If you decline, the match continues
                        and {requester.name} can't request again for 30 minutes.
                    </p>

                    {reason !== null && (
                        <ReasonBlock
                            label={`${requester.name}'s reason`}
                            reason={reason}
                        />
                    )}

                    <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                            variant="outline"
                            onClick={handleReject}
                            disabled={processing !== null}
                        >
                            {processing === 'reject' ? 'Declining…' : 'Decline'}
                        </Button>
                        <Button
                            variant="default"
                            onClick={handleAccept}
                            disabled={processing !== null}
                        >
                            {processing === 'accept'
                                ? 'Accepting…'
                                : 'Accept and refund'}
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
