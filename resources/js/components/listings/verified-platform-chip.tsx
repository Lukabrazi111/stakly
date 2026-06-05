import { useT } from '@/lib/i18n';
import type { ListingPlatform } from '@/types';

const PLATFORM_META: Record<ListingPlatform, { label: string; tone: string }> =
    {
        chess_com: {
            label: 'chess.com',
            tone: 'border-[#7c5430]/60 bg-[#7c5430]/15 text-[#d4a574]',
        },
        lichess: {
            label: 'Lichess',
            tone: 'border-neutral-500/50 bg-neutral-500/15 text-neutral-200',
        },
        // M15 placeholders — visible on dev-seeded CS2 (FACEIT) and Dota 2
        // (Steam) listings. Tones picked to roughly evoke each brand
        // without committing to a final logo treatment.
        faceit: {
            label: 'FACEIT',
            tone: 'border-[#ff5500]/50 bg-[#ff5500]/15 text-[#ff8a4c]',
        },
        steam: {
            label: 'Steam',
            tone: 'border-[#1b2838]/60 bg-[#1b2838]/40 text-[#66c0f4]',
        },
    };

// Defensive fallback for any platform string the frontend hasn't seen yet.
// Won't normally hit since the union type narrows server values, but admin
// data drift / future M15 catalog additions shouldn't crash the chip.
const FALLBACK_META = {
    label: 'Unknown',
    tone: 'border-border/60 bg-muted text-muted-foreground',
};

interface Props {
    platform: ListingPlatform;
}

/** Read-only platform chip on the listing row + card. */
export function VerifiedPlatformChip({ platform }: Props) {
    const t = useT();
    const meta = PLATFORM_META[platform] ?? FALLBACK_META;

    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-medium ${meta.tone}`}
            aria-label={t('Played on :platform', { platform: meta.label })}
        >
            {meta.label}
        </span>
    );
}
