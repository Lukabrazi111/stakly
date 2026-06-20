import { router } from '@inertiajs/react';
import { CheckCircle2, Loader2 } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useEffect, useState } from 'react';
import { useT } from '@/lib/i18n';
import type { ListingPlatform, MatchSnapshots } from '@/types';

interface WaitingForGameCardProps {
    platform: ListingPlatform;
    snapshots: MatchSnapshots;
    /** Truthy when an auto-fetched game card has landed in chat; swaps card
     *  to "found — settling now" until status flips to Settled. */
    hasAutoFetchedCard: boolean;
}

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    lichess: 'Lichess',
    chess_com: 'chess.com',
    // M15 placeholders.
    faceit: 'FACEIT',
    steam: 'Steam',
};

const PLATFORM_PROFILE_URL: Record<ListingPlatform, (u: string) => string> = {
    lichess: (u) => `https://lichess.org/@/${encodeURIComponent(u)}`,
    chess_com: (u) => `https://www.chess.com/member/${encodeURIComponent(u)}`,
    // M15 placeholders — best-effort profile URLs; not used on real CS2/Dota
    // listings today since none exist in production.
    faceit: (u) => `https://www.faceit.com/en/players/${encodeURIComponent(u)}`,
    steam: (u) => `https://steamcommunity.com/id/${encodeURIComponent(u)}`,
};

/** Pending-state action card. Two states: Looking (default), Found (brief
 *  hand-off before status flips to Settled). */
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
    const t = useT();
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
                    {t('Play your match on :platform', {
                        platform: platformLabel,
                    })}
                </h2>

                <p className="mt-1 text-sm text-muted-foreground">
                    {t(
                        'Stakly settles automatically as soon as your game on :platform finishes — no buttons to press.',
                        { platform: platformLabel },
                    )}
                </p>

                {snapshots.creator_username !== null &&
                    snapshots.taker_username !== null && (
                        <div className="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-border/60 bg-background/40 px-3 py-2.5 text-sm">
                            <span className="text-xs tracking-wide text-muted-foreground uppercase">
                                {t('Watching for')}
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
                    <span>{t('Looking for your game…')}</span>
                    <span aria-hidden="true">•</span>
                    <span className="tabular-nums">
                        {t('Last checked :seconds s ago', {
                            seconds: secondsAgo,
                        })}
                    </span>
                </div>
            </div>
        </div>
    );
}

function FoundState({ platform }: { platform: ListingPlatform }) {
    const t = useT();

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
                    {t('Game found — settling now…')}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {t(
                        'We found your game on :platform. Payout and platform fee post to the ledger in a moment.',
                        { platform: PLATFORM_LABEL[platform] },
                    )}
                </p>
            </div>
        </div>
    );
}

/** Seconds since the most recent successful Inertia visit. Resets on every
 *  `router.on('success', ...)` so it owns its own reset signal. */
function useSecondsSinceLastVisit(): number {
    // null until mount — initializing to Date.now() would cause a hydration mismatch.
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
