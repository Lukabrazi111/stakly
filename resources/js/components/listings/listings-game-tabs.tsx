import { useEffect, useRef } from 'react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { GameTile } from '@/types';

interface Props {
    games: readonly GameTile[];
    selectedSlug: string;
    onSelect: (slug: string) => void;
}

/**
 * Top-of-page game-context strip on `/listings`. Pill tabs (icon + name)
 * scoped to a single selected game — listings below filter to that game.
 *
 * Different from the homepage `GameSelector` (arena-tile showcase) on
 * purpose: this is utility chrome, not a hero. Compact pills keep the
 * listings grid as the page's visual centerpiece.
 *
 * Selected uses solid `border-primary` + `bg-primary/15` (no glow — small
 * clickable pills don't get `shadow-glow*`, per the Stakly hover-glow
 * scope rule). Non-selected gets the standard outline-button hover.
 */
export function ListingsGameTabs({ games, selectedSlug, onSelect }: Props) {
    const t = useT();
    const scrollerRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const scroller = scrollerRef.current;

        if (!scroller) {
            return;
        }

        const active = scroller.querySelector<HTMLButtonElement>(
            '[data-active="true"]',
        );

        active?.scrollIntoView({
            behavior: 'instant' as ScrollBehavior,
            inline: 'nearest',
            block: 'nearest',
        });
    }, [selectedSlug]);

    if (games.length === 0) {
        return null;
    }

    return (
        <div
            ref={scrollerRef}
            role="tablist"
            aria-label={t('Game')}
            className="-mx-4 flex snap-x snap-mandatory [scrollbar-width:none] gap-2 overflow-x-auto px-4 pb-1 md:mx-0 md:flex-wrap md:overflow-visible md:px-0 [&::-webkit-scrollbar]:hidden"
        >
            {games.map((game) => {
                const isSelected = game.slug === selectedSlug;
                const isComingSoon = game.status === 'coming_soon';

                return (
                    <button
                        key={game.slug}
                        type="button"
                        role="tab"
                        aria-selected={isSelected}
                        data-active={isSelected ? 'true' : 'false'}
                        onClick={() => onSelect(game.slug)}
                        className={cn(
                            'group inline-flex shrink-0 cursor-pointer snap-start items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors duration-150 ease-out',
                            'focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                            isSelected
                                ? 'border-primary bg-primary/15 text-foreground'
                                : 'border-border/60 bg-card/60 text-muted-foreground hover:border-primary/40 hover:bg-primary/10 hover:text-foreground',
                        )}
                    >
                        <GameIcon game={game} />
                        <span className="whitespace-nowrap">
                            {game.display_name}
                        </span>
                        {isComingSoon && (
                            <span
                                className={cn(
                                    'rounded-full px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase transition-colors',
                                    isSelected
                                        ? 'bg-foreground/10 text-muted-foreground'
                                        : 'bg-background/60 text-muted-foreground/80',
                                )}
                            >
                                {t('Soon')}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

function GameIcon({ game }: { game: GameTile }) {
    if (game.poster_path) {
        return (
            <span
                aria-hidden
                className="inline-flex size-6 shrink-0 overflow-hidden rounded-full ring-1 ring-border/60"
            >
                <img
                    src={game.poster_path}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="size-full object-cover object-center"
                />
            </span>
        );
    }

    return (
        <span
            aria-hidden
            className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary/80 to-accent/80 text-[10px] font-bold text-primary-foreground"
        >
            {game.display_name.charAt(0).toUpperCase()}
        </span>
    );
}
