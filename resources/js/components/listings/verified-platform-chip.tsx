import { PLATFORM_COLOR, PLATFORM_LABEL } from '@/config/platforms';
import { useT } from '@/lib/i18n';
import type { ListingPlatform } from '@/types';

interface Props {
    platform: ListingPlatform;
}

/**
 * Read-only "played on" platform marker (profile listing rows). Calm neutral
 * chip (M43 P3) — the platform name is tinted with its `--platform-*` token
 * instead of the old loud filled pill. Falls back to plain text for any
 * platform the frontend hasn't seen yet (admin data drift / future catalog).
 */
export function VerifiedPlatformChip({ platform }: Props) {
    const t = useT();
    const label = PLATFORM_LABEL[platform] ?? 'Unknown';

    return (
        <span
            className="inline-flex shrink-0 items-center rounded-full border border-border/60 bg-card/60 px-2.5 py-0.5 text-[11px] font-medium"
            aria-label={t('Played on :platform', { platform: label })}
        >
            <span style={{ color: PLATFORM_COLOR[platform] }}>{label}</span>
        </span>
    );
}
