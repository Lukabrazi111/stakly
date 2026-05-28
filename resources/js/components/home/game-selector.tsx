import { cn } from '@/lib/utils';
import type { GameTile } from '@/types';

interface Props {
    games: readonly GameTile[];
    selectedSlug: string;
    onSelect: (slug: string) => void;
}

/**
 * Homepage game-tile row. Tiles are admin-managed via Filament (M24);
 * data comes from the backend via the `games` Inertia prop. Each tile
 * renders its uploaded poster artwork; tiles without a poster fall back
 * to a gradient + display_name placeholder so the row never breaks if
 * admin adds a game before its art lands.
 */
export function GameSelector({ games, selectedSlug, onSelect }: Props) {
    return (
        <section className="relative">
            <div className="mx-auto max-w-7xl px-4">
                <div className="mb-5 flex items-end justify-between gap-4">
                    <div>
                        <p className="mb-1 text-xs tracking-widest text-muted-foreground uppercase">
                            Select game
                        </p>
                        <h2 className="font-display text-2xl font-bold text-foreground md:text-3xl">
                            Pick your arena
                        </h2>
                    </div>
                    <p className="hidden text-sm text-muted-foreground md:block">
                        Chess is live. More games are rolling out.
                    </p>
                </div>

                <div
                    // Mobile: -mx-4 + px-6 lets the row bleed to the screen
                    // edges so the partially-clipped next tile signals
                    // "scroll right for more". Desktop: mx-0 + px-0 keeps
                    // the row within the parent's px-4 column so the first
                    // tile aligns horizontally with the section heading
                    // and the FeaturedListings cards below.
                    className="-mx-4 flex snap-x snap-mandatory [scrollbar-width:none] gap-4 overflow-x-auto px-6 py-5 pb-6 md:mx-0 md:px-0 [&::-webkit-scrollbar]:hidden"
                    role="listbox"
                    aria-label="Game selector"
                >
                    {games.map((game, index) => {
                        const isSelected = game.slug === selectedSlug;
                        const isComingSoon = game.status === 'coming_soon';
                        // Lazy-load tiles past the first three — the leading
                        // tiles are above the fold and eager-loading them
                        // avoids a visible LCP regression.
                        const loading = index < 3 ? 'eager' : 'lazy';

                        return (
                            <button
                                key={game.slug}
                                type="button"
                                role="option"
                                aria-selected={isSelected}
                                onClick={() => onSelect(game.slug)}
                                className={cn(
                                    'group relative shrink-0 cursor-pointer snap-start overflow-hidden rounded-2xl border-[3px] bg-card transition duration-200 ease-out',
                                    'h-52 w-36 md:h-60 md:w-40',
                                    'focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                                    'hover:-translate-y-1.5 hover:shadow-[var(--shadow-arena-card-glow)] motion-reduce:hover:translate-y-0',
                                    isSelected
                                        ? 'border-primary shadow-[var(--shadow-arena-card-glow)]'
                                        : 'border-border/60 hover:border-primary',
                                )}
                            >
                                {game.poster_path ? (
                                    <img
                                        src={game.poster_path}
                                        alt={game.display_name}
                                        loading={loading}
                                        className="absolute inset-0 h-full w-full object-cover object-center transition-transform duration-300 ease-out group-hover:scale-105 motion-reduce:group-hover:scale-100"
                                    />
                                ) : (
                                    <>
                                        <div className="absolute inset-0 bg-gradient-to-br from-primary/80 to-accent/80 opacity-60 transition-opacity group-hover:opacity-80" />
                                        <div className="absolute inset-0 bg-gradient-to-b from-background/10 via-background/40 to-background" />
                                        <div className="relative flex h-full flex-col items-center justify-end p-4">
                                            <span className="line-clamp-2 text-center font-display text-sm font-bold tracking-tight text-foreground">
                                                {game.display_name}
                                            </span>
                                        </div>
                                    </>
                                )}

                                {isComingSoon && (
                                    <span className="absolute top-2 right-2 rounded-full bg-background/80 px-2 py-0.5 text-[10px] tracking-wide text-muted-foreground uppercase backdrop-blur">
                                        Soon
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
