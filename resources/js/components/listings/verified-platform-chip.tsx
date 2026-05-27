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

/**
 * Read-only platform chip on the listing row + card (M22 Phase 1). Shows
 * which platform the match MUST be played on so a viewer can spot at
 * scan-time whether they're verified on the right provider before clicking
 * through to take.
 *
 * Uses the same brand-tinted tokens as the profile-page `VerificationChip`
 * (chess.com brown, Lichess gray) for visual consistency across the app
 * wherever a platform-association is shown. Future M15 game adapters
 * (FACEIT orange, Riot red, Steam blue) extend `PLATFORM_META` + the
 * `ListingPlatform` type.
 */
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
