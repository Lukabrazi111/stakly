import { Head } from '@inertiajs/react';
import { GameSelector } from '@/components/home/game-selector';
import { Hero } from '@/components/home/hero';
import { HowItWorks } from '@/components/home/how-it-works';
import SiteLayout from '@/layouts/site-layout';

export default function Welcome() {
    return (
        <SiteLayout>
            <Head title="Stake your skill. Find your match." />
            <Hero />
            <GameSelector />
            <HowItWorks />
        </SiteLayout>
    );
}
