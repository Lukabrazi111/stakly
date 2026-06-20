import { useMemo, useState } from 'react';
import { FeaturedListings } from '@/components/home/featured-listings';
import { GameSelector } from '@/components/home/game-selector';
import { Hero } from '@/components/home/hero';
import { HowItWorks } from '@/components/home/how-it-works';
import { PageMeta } from '@/components/site/page-meta';
import SiteLayout from '@/layouts/site-layout';
import type { GameTile, Listing } from '@/types';

interface Props {
    featured: { data: Listing[] };
    games: { data: GameTile[] };
}

export default function Welcome({ featured, games }: Props) {
    const tiles = games.data;

    // GameSelector drives FeaturedListings below it — picking a tile filters
    // the "Ending soon" cards. Default to the first tile (chess today via
    // GameSeeder). When other games' adapters land in M15, this becomes a
    // partial-reload param instead of a client-side filter.
    const [selectedSlug, setSelectedSlug] = useState<string>(
        tiles[0]?.slug ?? 'chess',
    );

    const { listings, name, isLive } = useMemo(() => {
        const tile =
            tiles.find((g) => g.slug === selectedSlug) ?? tiles[0] ?? null;
        const isLive = tile?.status === 'active';

        return {
            listings:
                isLive && tile
                    ? featured.data.filter((l) => l.game === tile.slug)
                    : [],
            name: tile?.display_name ?? '',
            isLive,
        };
    }, [featured.data, tiles, selectedSlug]);

    return (
        <SiteLayout>
            <PageMeta
                title="Stake your skill. Find your match."
                description="Peer-to-peer chess staking marketplace. Post a listing, escrow your stake, play your opponent on chess.com or Lichess, and get paid when you win."
            />
            <Hero />
            <GameSelector
                games={tiles}
                selectedSlug={selectedSlug}
                onSelect={setSelectedSlug}
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
