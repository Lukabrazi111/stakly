import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { FeaturedListings } from '@/components/home/featured-listings';
import type { GameTileId } from '@/components/home/game-selector';
import { GAME_TILES, GameSelector } from '@/components/home/game-selector';
import { Hero } from '@/components/home/hero';
import { HowItWorks } from '@/components/home/how-it-works';
import SiteLayout from '@/layouts/site-layout';
import type { Listing } from '@/types';

interface Props {
    featured: { data: Listing[] };
}

export default function Welcome({ featured }: Props) {
    // GameSelector drives FeaturedListings below it — picking a tile filters
    // the "Ending soon" cards. Defaults to chess (the only game with backend
    // data today). When other games' adapters land in M15, this becomes a
    // partial-reload param instead of a client-side filter.
    const [selectedGame, setSelectedGame] = useState<GameTileId>('chess');

    const { listings, name, isLive } = useMemo(() => {
        const tile =
            GAME_TILES.find((g) => g.id === selectedGame) ?? GAME_TILES[0];
        const isLive = !tile.comingSoon;

        return {
            listings: isLive
                ? featured.data.filter((l) => l.game === selectedGame)
                : [],
            name: tile.name,
            isLive,
        };
    }, [featured.data, selectedGame]);

    return (
        <SiteLayout>
            <Head title="Stake your skill. Find your match." />
            <Hero />
            <GameSelector
                selectedId={selectedGame}
                onSelect={setSelectedGame}
            />
            <FeaturedListings
                listings={listings}
                selectedGameName={name}
                isSelectedGameLive={isLive}
            />
            <HowItWorks />
        </SiteLayout>
    );
}
