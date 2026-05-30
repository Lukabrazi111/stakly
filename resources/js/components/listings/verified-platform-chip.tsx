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
    };

interface Props {
    platform: ListingPlatform;
}

/** Read-only platform chip on the listing row + card. */
export function VerifiedPlatformChip({ platform }: Props) {
    const meta = PLATFORM_META[platform];

    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-medium ${meta.tone}`}
            aria-label={`Played on ${meta.label}`}
        >
            {meta.label}
        </span>
    );
}
