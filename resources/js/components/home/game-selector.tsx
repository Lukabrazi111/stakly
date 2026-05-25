import type { LucideIcon } from 'lucide-react';
import {
    Crown,
    Flame,
    Gamepad2,
    Rocket,
    Snowflake,
    Sparkles,
    Swords,
    Target,
    Trophy,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// Tile ids — superset of the backend `Game` enum because the selector
// shows "Coming soon" tiles for games that aren't wired up yet. Only
// `chess` currently matches an `App\Enums\Game` case; the rest are
// frontend-only placeholders until the corresponding backend support lands.
export type GameTileId =
    | 'chess'
    | 'dota2'
    | 'lol'
    | 'cs2'
    | 'valorant'
    | 'apex'
    | 'rocket-league'
    | 'overwatch'
    | 'fortnite';

interface GameTile {
    id: GameTileId;
    name: string;
    icon: LucideIcon;
    tint: string;
    comingSoon: boolean;
}

export const GAME_TILES: readonly GameTile[] = [
    {
        id: 'chess',
        name: 'Chess',
        icon: Crown,
        tint: 'from-primary/80 to-accent/80',
        comingSoon: false,
    },
    {
        id: 'dota2',
        name: 'Dota 2',
        icon: Swords,
        tint: 'from-red-600/70 to-orange-500/70',
        comingSoon: true,
    },
    {
        id: 'lol',
        name: 'League of Legends',
        icon: Sparkles,
        tint: 'from-blue-500/70 to-indigo-500/70',
        comingSoon: true,
    },
    {
        id: 'cs2',
        name: 'CS2',
        icon: Target,
        tint: 'from-amber-500/70 to-yellow-500/70',
        comingSoon: true,
    },
    {
        id: 'valorant',
        name: 'Valorant',
        icon: Flame,
        tint: 'from-rose-500/70 to-red-500/70',
        comingSoon: true,
    },
    {
        id: 'apex',
        name: 'Apex Legends',
        icon: Trophy,
        tint: 'from-orange-500/70 to-red-600/70',
        comingSoon: true,
    },
    {
        id: 'rocket-league',
        name: 'Rocket League',
        icon: Rocket,
        tint: 'from-sky-500/70 to-blue-600/70',
        comingSoon: true,
    },
    {
        id: 'overwatch',
        name: 'Overwatch',
        icon: Snowflake,
        tint: 'from-cyan-400/70 to-blue-500/70',
        comingSoon: true,
    },
    {
        id: 'fortnite',
        name: 'Fortnite',
        icon: Gamepad2,
        tint: 'from-violet-500/70 to-fuchsia-500/70',
        comingSoon: true,
    },
] as const;

interface Props {
    selectedId: GameTileId;
    onSelect: (id: GameTileId) => void;
}

export function GameSelector({ selectedId, onSelect }: Props) {

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
                    {GAME_TILES.map((game) => {
                        const isSelected = game.id === selectedId;
                        const Icon = game.icon;

                        return (
                            <button
                                key={game.id}
                                type="button"
                                role="option"
                                aria-selected={isSelected}
                                onClick={() => onSelect(game.id)}
                                className={cn(
                                    'group relative shrink-0 cursor-pointer snap-start overflow-hidden rounded-2xl border-2 bg-card transition duration-200 ease-out',
                                    'h-44 w-36 md:h-52 md:w-40',
                                    'focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                                    // Hover lift + glow apply universally — selected tiles
                                    // get the same interactive feedback so the user knows
                                    // the tile is still actionable (e.g. re-selecting to
                                    // refresh the panel).
                                    'hover:-translate-y-1 hover:shadow-glow-sm motion-reduce:hover:translate-y-0',
                                    // Selected = solid `border-primary` (full-saturation
                                    // pink) + `shadow-glow` halo (the soft standard
                                    // Stakly halo). We don't use the shared
                                    // `border-glow` utility here because its
                                    // semi-transparent fuchsia border reads too softly
                                    // for an active-selection cue — the other three
                                    // `border-glow` consumers (auth modal, profile
                                    // dropdown, link-account banner) are static
                                    // decorative borders where that softness is right.
                                    isSelected
                                        ? 'border-primary shadow-glow'
                                        : 'border-border/60 hover:border-primary/60',
                                )}
                            >
                                <div
                                    className={cn(
                                        'absolute inset-0 bg-gradient-to-br opacity-60 transition-opacity group-hover:opacity-80',
                                        game.tint,
                                    )}
                                />
                                <div className="absolute inset-0 bg-gradient-to-b from-background/10 via-background/40 to-background" />

                                {game.comingSoon && (
                                    <span className="absolute top-2 right-2 rounded-full bg-background/80 px-2 py-0.5 text-[10px] tracking-wide text-muted-foreground uppercase backdrop-blur">
                                        Soon
                                    </span>
                                )}

                                <div className="relative flex h-full flex-col items-center justify-end gap-2 p-4">
                                    <Icon
                                        className="size-10 text-foreground/90"
                                        strokeWidth={1.5}
                                    />
                                    <span className="line-clamp-1 font-display text-sm font-bold tracking-tight text-foreground">
                                        {game.name}
                                    </span>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
