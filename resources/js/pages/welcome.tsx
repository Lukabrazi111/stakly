import { Head } from '@inertiajs/react';
import { FeaturedListings } from '@/components/home/featured-listings';
import { GameSelector } from '@/components/home/game-selector';
import { Hero } from '@/components/home/hero';
import { HowItWorks } from '@/components/home/how-it-works';
import SiteLayout from '@/layouts/site-layout';
import type { Listing } from '@/types';

interface Props {
    featured: { data: Listing[] };
}

export default function Welcome({ featured }: Props) {
    return (
        <SiteLayout>
            <Head title="Stake your skill. Find your match." />
            <Hero />
            <FeaturedListings listings={featured.data} />
            <GameSelector />
            <HowItWorks />
        </SiteLayout>
    );
}
