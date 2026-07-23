import { ExternalLink } from 'lucide-react';
import {
    PLATFORM_COLOR,
    PLATFORM_LABEL,
    PLATFORM_PROFILE_URL,
} from '@/config/platforms';
import { useT } from '@/lib/i18n';
import type { ListingPlatform } from '@/types';

interface Props {
    provider: ListingPlatform;
    username: string;
}

/**
 * A creator's verified linked account. Calm neutral pill (M43 P3) — the
 * platform name carries the brand colour via the `--platform-*` token, the
 * username stays monospace, and hover uses the Stakly primary wash rather than
 * a loud per-brand fill. Click-out opens the external public profile.
 */
export function VerificationChip({ provider, username }: Props) {
    const t = useT();
    const label = PLATFORM_LABEL[provider];
    const buildUrl = PLATFORM_PROFILE_URL[provider];

    // Defensive — if a future platform reaches the chip before its config
    // entry lands, render nothing rather than crash on `buildUrl`.
    if (!label || !buildUrl) {
        return null;
    }

    return (
        <a
            href={buildUrl(username)}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={t(
                'View verified :platform profile :username (opens in new tab)',
                { platform: label, username },
            )}
            className="group inline-flex shrink-0 items-center gap-1.5 rounded-full border border-border/60 bg-card/60 px-3 py-1 text-xs font-medium transition-colors duration-150 ease-out hover:border-primary/40 hover:bg-primary/10 focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        >
            <span style={{ color: PLATFORM_COLOR[provider] }}>{label}</span>
            <span className="text-muted-foreground/50" aria-hidden="true">
                ·
            </span>
            <span className="font-mono tracking-tight text-foreground/90">
                {username}
            </span>
            <ExternalLink
                className="size-3 text-muted-foreground opacity-60 transition-opacity duration-150 group-hover:opacity-100"
                aria-hidden="true"
            />
        </a>
    );
}
