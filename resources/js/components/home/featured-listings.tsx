import { Link } from '@inertiajs/react';
import { ArrowRight, Sparkles } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { ListingCard } from '@/components/listings/listing-card';
import { index as listingsIndex } from '@/routes/listings';
import type { Listing } from '@/types';

interface Props {
    listings: Listing[];
    selectedGameName: string;
    isSelectedGameLive: boolean;
}

/**
 * Top N ending-soon open listings for the currently-selected game in
 * `GameSelector`. Lives BELOW the selector so the visual cause/effect
 * is obvious — click a tile, the listings below swap.
 *
 * Filtering is client-side. Only `chess` has backend data today, so
 * non-chess selections always land in the empty state (per-game backend
 * filtering becomes relevant when other games' adapters land in M15).
 *
 * Animation: cards spring into place with a slight scale + 20px slide,
 * 70ms wave stagger left-to-right. Spring stiffness 220 / damping 22
 * gives a controlled bounce that settles in ~500ms without overshooting.
 * Empty state uses a gentler scale-up for visual parity. The container
 * crossfade keys on grid-vs-empty so swapping between filled games stays
 * smooth without unmounting the cards in-place.
 *
 * `useReducedMotion` collapses to a plain opacity fade with zero delay
 * when the user opts out of motion via OS settings.
 */
export function FeaturedListings({
    listings,
    selectedGameName,
    isSelectedGameLive,
}: Props) {
    const reduceMotion = useReducedMotion();

    const hasListings = listings.length > 0;

    return (
        <section className="mx-auto max-w-7xl px-4 py-12 md:py-16">
            <header className="mb-8 flex items-end justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
                        Ending soon
                    </h2>
                    <p className="mt-1.5 text-sm text-muted-foreground">
                        Open {selectedGameName} listings closing in the next few
                        hours.
                    </p>
                </div>

                {hasListings && (
                    <Link
                        href={listingsIndex()}
                        className="group inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-primary"
                    >
                        View all
                        <ArrowRight className="size-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5" />
                    </Link>
                )}
            </header>

            <AnimatePresence mode="wait" initial={false}>
                <motion.div
                    key={
                        hasListings
                            ? `grid-${selectedGameName}`
                            : `empty-${selectedGameName}`
                    }
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0 }}
                    transition={{ duration: 0.18, ease: [0.4, 0, 0.2, 1] }}
                >
                    {hasListings ? (
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {listings.map((listing, i) => (
                                <motion.div
                                    key={listing.id}
                                    initial={
                                        reduceMotion
                                            ? { opacity: 0 }
                                            : { opacity: 0, y: 20, scale: 0.94 }
                                    }
                                    animate={
                                        reduceMotion
                                            ? { opacity: 1 }
                                            : { opacity: 1, y: 0, scale: 1 }
                                    }
                                    transition={
                                        reduceMotion
                                            ? { duration: 0.2 }
                                            : {
                                                  // Spring tuned for "premium settle":
                                                  // arrives with a small overshoot then
                                                  // damps in ~500ms. Higher stiffness =
                                                  // snappier; lower damping = more bounce.
                                                  type: 'spring',
                                                  stiffness: 220,
                                                  damping: 22,
                                                  mass: 0.9,
                                                  // 70ms wave — at 4 cards that's a
                                                  // 280ms total reveal arc, slow enough
                                                  // to register each card distinctly,
                                                  // fast enough to feel responsive.
                                                  delay: i * 0.07,
                                              }
                                    }
                                >
                                    <ListingCard listing={listing} />
                                </motion.div>
                            ))}
                        </div>
                    ) : (
                        <motion.div
                            initial={
                                reduceMotion
                                    ? { opacity: 0 }
                                    : { opacity: 0, scale: 0.96 }
                            }
                            animate={
                                reduceMotion
                                    ? { opacity: 1 }
                                    : { opacity: 1, scale: 1 }
                            }
                            transition={
                                reduceMotion
                                    ? { duration: 0.2 }
                                    : {
                                          type: 'spring',
                                          stiffness: 240,
                                          damping: 26,
                                          mass: 0.8,
                                      }
                            }
                        >
                            <EmptyState
                                gameName={selectedGameName}
                                isLive={isSelectedGameLive}
                            />
                        </motion.div>
                    )}
                </motion.div>
            </AnimatePresence>
        </section>
    );
}

interface EmptyStateProps {
    gameName: string;
    isLive: boolean;
}

function EmptyState({ gameName, isLive }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border/60 bg-card/60 px-6 py-12 text-center">
            <div className="inline-flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                <Sparkles className="size-5" />
            </div>
            <p className="text-sm font-medium text-foreground">
                {isLive
                    ? `No ${gameName} listings closing soon.`
                    : `${gameName} is rolling out.`}
            </p>
            <p className="max-w-sm text-sm text-muted-foreground">
                {isLive
                    ? 'Be the first — post a listing and find an opponent.'
                    : 'Chess is live now. Pick it above to see what’s open.'}
            </p>
        </div>
    );
}
