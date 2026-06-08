import { ExternalLink } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { ListingPlatform } from '@/types';

// Tint colors from each platform's brand identity, adjusted for the dark surface.
const PROVIDER_META: Record<
    ListingPlatform,
    {
        label: string;
        href: (username: string) => string;
        tone: string;
    }
> = {
    chess_com: {
        label: 'chess.com',
        href: (u) => `https://www.chess.com/member/${u}`,
        tone: 'border-[#7c5430]/60 bg-[#7c5430]/15 text-[#d4a574] hover:border-[#7c5430]/90 hover:bg-[#7c5430]/25',
    },
    lichess: {
        label: 'Lichess',
        href: (u) => `https://lichess.org/@/${u}`,
        tone: 'border-neutral-500/50 bg-neutral-500/15 text-neutral-200 hover:border-neutral-400/70 hover:bg-neutral-500/25',
    },
    faceit: {
        label: 'FACEIT',
        href: (u) => `https://www.faceit.com/en/players/${u}`,
        tone: 'border-[#ff5500]/50 bg-[#ff5500]/15 text-[#ff8a4c] hover:border-[#ff5500]/80 hover:bg-[#ff5500]/25',
    },
    steam: {
        label: 'Steam',
        href: (u) => `https://steamcommunity.com/id/${u}`,
        tone: 'border-[#1b2838]/60 bg-[#1b2838]/40 text-[#66c0f4] hover:border-[#66c0f4]/50 hover:bg-[#1b2838]/60',
    },
};

interface Props {
    provider: ListingPlatform;
    username: string;
}

export function VerificationChip({ provider, username }: Props) {
    const t = useT();
    const meta = PROVIDER_META[provider];

    // Defensive — if a future M15 platform reaches the chip before its meta
    // entry lands here, render nothing rather than crash on `meta.href`.
    if (!meta) {
        return null;
    }

    return (
        <a
            href={meta.href(username)}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={t(
                'View verified :platform profile :username (opens in new tab)',
                { platform: meta.label, username },
            )}
            className={`group inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none ${meta.tone}`}
        >
            <span>{meta.label}</span>
            <span className="opacity-50" aria-hidden="true">
                ·
            </span>
            <span className="font-mono tracking-tight">{username}</span>
            <ExternalLink
                className="size-3 opacity-60 transition-opacity duration-150 group-hover:opacity-100"
                aria-hidden="true"
            />
        </a>
    );
}
