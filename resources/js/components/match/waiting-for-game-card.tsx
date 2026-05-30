import { router } from '@inertiajs/react';
import { CheckCircle2, Loader2 } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useEffect, useState } from 'react';
import type { ListingPlatform, MatchSnapshots } from '@/types';

interface WaitingForGameCardProps {
    platform: ListingPlatform;
    snapshots: MatchSnapshots;
    /**
     * Truthy when an auto-fetched game card has landed in chat. The action
     * card swaps to a "found — settling now" state; the parent's poll
     * tick catches the status flip to Settled within a few seconds and
     * unmounts this card in favor of `SettlementSummary`.
     */
    hasAutoFetchedCard: boolean;
}

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    lichess: 'Lichess',
    chess_com: 'chess.com',
};

const PLATFORM_PROFILE_URL: Record<ListingPlatform, (u: string) => string> = {
    lichess: (u) => `https://lichess.org/@/${encodeURIComponent(u)}`,
    chess_com: (u) => `https://www.chess.com/member/${encodeURIComponent(u)}`,
};

/**
 * Pending-state action card (M16). Replaced the M6 confirm buttons —
 * matches now settle from the game-API card, no player vote required.
 *
 * Two visual states:
 *   - **Looking** — pulsing spinner, the player-pair we're polling for,
 *     a soft "last checked Ns ago" stamp. Default while a card hasn't
 *     landed.
 *   - **Found** — success-toned, "Game found — settling now…". Brief
 *     hand-off state between card-landed and status-flips-to-Settled
 *     (typically <2s before the next poll catches the flip and unmounts
 *     the whole card in favor of `SettlementSummary`).
 *
 * If a snapshot username is missing on either side, the player-pair row
 * is omitted (no broken "Looking for X vs (missing)" copy). Take + create
 * gates enforce both-sides-linked upstream, so this is a defensive UX
 * rather than a normal path.
 *
 * Animation respects `prefers-reduced-motion` — the pulse spinner
 * collapses to a static icon, the success state cross-fade collapses to
 * an instant swap.
 */
export function WaitingForGameCard({
    platform,
    snapshots,
    hasAutoFetchedCard,
}: WaitingForGameCardProps) {
    const reduceMotion = useReducedMotion();

    return (
        <section className="overflow-hidden rounded-2xl border border-border/60 bg-card/60">
            <AnimatePresence mode="wait" initial={false}>
                {hasAutoFetchedCard ? (
                    <motion.div
                        key="found"
                        initial={reduceMotion ? false : { opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={reduceMotion ? undefined : { opacity: 0, y: -8 }}
                        transition={{ duration: 0.2, ease: 'easeOut' }}
                        className="p-6"
                    >
                        <FoundState platform={platform} />
                    </motion.div>
                ) : (
                    <motion.div
                        key="looking"
                        initial={reduceMotion ? false : { opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={reduceMotion ? undefined : { opacity: 0 }}
                        transition={{ duration: 0.15, ease: 'easeOut' }}
                        className="p-6"
                    >
                        <LookingState
                            platform={platform}
                            snapshots={snapshots}
                            reduceMotion={reduceMotion ?? false}
                        />
                    </motion.div>
                )}
            </AnimatePresence>
        </section>
    );
}

function LookingState({
    platform,
    snapshots,
    reduceMotion,
}: {
    platform: ListingPlatform;
    snapshots: MatchSnapshots;
    reduceMotion: boolean;
}) {
    const secondsAgo = useSecondsSinceLastVisit();

    const platformLabel = PLATFORM_LABEL[platform];
    const buildProfileUrl = PLATFORM_PROFILE_URL[platform];

    return (
        <div className="flex items-start gap-4">
            <div className="shrink-0 rounded-full bg-warning/10 p-2.5 ring-1 ring-warning/20">
                {reduceMotion ? (
                    <Loader2
                        className="size-5 text-warning"
                        aria-hidden="true"
                    />
                ) : (
                    <Loader2
                        className="size-5 animate-spin text-warning"
                        aria-hidden="true"
                    />
                )}
            </div>

            <div className="min-w-0 flex-1">
                <h2 className="font-display text-lg font-semibold text-foreground">
                    Play your match on {platformLabel}
                </h2>

                <p className="mt-1 text-sm text-muted-foreground">
                    Stakly settles automatically as soon as your game on{' '}
                    {platformLabel} finishes — no buttons to press.
                </p>

                {snapshots.creator_username !== null &&
                    snapshots.taker_username !== null && (
                        <div className="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-border/60 bg-background/40 px-3 py-2.5 text-sm">
                            <span className="text-xs tracking-wide text-muted-foreground uppercase">
                                Watching for
                            </span>
                            <a
                                href={buildProfileUrl(
                                    snapshots.creator_username,
                                )}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-medium text-foreground transition-colors hover:text-primary"
                            >
                                {snapshots.creator_username}
                            </a>
                            <span className="text-muted-foreground">vs</span>
                            <a
                                href={buildProfileUrl(snapshots.taker_username)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-medium text-foreground transition-colors hover:text-primary"
                            >
                                {snapshots.taker_username}
                            </a>
                        </div>
                    )}

                <div
                    className="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
                    aria-live="polite"
                >
                    <span className="size-1.5 rounded-full bg-warning" />
                    <span>Looking for your game…</span>
                    <span aria-hidden="true">•</span>
                    <span className="tabular-nums">
                        Last checked {secondsAgo}s ago
                    </span>
                </div>
            </div>
        </div>
    );
}

function FoundState({ platform }: { platform: ListingPlatform }) {
    return (
        <div className="flex items-start gap-4">
            <div className="shrink-0 rounded-full bg-success/10 p-2.5 ring-1 ring-success/20">
                <CheckCircle2
                    className="size-5 text-success"
                    aria-hidden="true"
                />
            </div>

            <div className="min-w-0 flex-1">
                <h2 className="font-display text-lg font-semibold text-foreground">
                    Game found — settling now…
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    We found your game on {PLATFORM_LABEL[platform]}. Payout and
                    platform fee post to the ledger in a moment.
                </p>
            </div>
        </div>
    );
}

/**
 * Returns the seconds elapsed since the most recent successful Inertia
 * visit (or since mount if no visit has completed yet). Re-renders once
 * per second while mounted; resets to 0 every time Inertia's router
 * fires `success` — that fires on every successful partial reload from
 * the match page's 8s polling loop.
 *
 * Subscribing to `router.on('success', ...)` directly removes the need
 * for the parent to maintain a poll-tick counter and pass it down. The
 * timer owns its own reset signal, decoupled from prop-change detection.
 */
function useSecondsSinceLastVisit(): number {
    // null until the client mounts. Initializing to `Date.now()` would
    // render different values on the server vs first client paint, causing
    // a hydration mismatch on the "seconds ago" copy this hook feeds.
    const [resetAt, setResetAt] = useState<number | null>(null);
    const [now, setNow] = useState<number | null>(null);

    useEffect(() => {
        const init = Date.now();
        setResetAt(init);
        setNow(init);

        return router.on('success', () => {
            setResetAt(Date.now());
            setNow(Date.now());
        });
    }, []);

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(id);
    }, []);

    if (now === null || resetAt === null) {
        return 0;
    }

    return Math.max(0, Math.floor((now - resetAt) / 1000));
}
