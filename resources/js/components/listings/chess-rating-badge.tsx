import { CircleDashed } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { ChessRating } from '@/types/listings';

/**
 * Verified chess rating chip (M41 P4). Shown on chess listings in place of the
 * old self-typed skill range — the rating for the listing's platform + time
 * control (the TC itself shows as its own chip beside this). Chess providers
 * expose an ELO number only (no FACEIT-style level), so this is a minimal
 * tier-tinted number: entry (<1400) muted, mid (1400–1999) pink, elite (≥2000)
 * purple — the number stays the actual signal, so it's colorblind-safe.
 * Provisional / missing ratings render "Unrated". Non-interactive metadata: no
 * hover, cursor, or glow (matches the sibling time-control + GameChip).
 */

type ChessTier = 'entry' | 'mid' | 'elite';

function tierFor(rating: number): ChessTier {
    if (rating >= 2000) {
        return 'elite';
    }

    if (rating >= 1400) {
        return 'mid';
    }

    return 'entry';
}

// Tier shows via the pill border + bg wash; the NUMBER stays text-foreground so
// it always clears WCAG AA contrast (accent/primary text on a faint wash dips
// below 4.5:1) — mirrors how FaceitRatingBadge keeps its number high-contrast.
const PILL_TONE: Record<ChessTier, string> = {
    entry: 'border-border/60 bg-card/60 text-foreground',
    mid: 'border-primary/40 bg-primary/10 text-foreground',
    elite: 'border-accent/50 bg-accent/15 text-foreground',
};

interface Props {
    rating: ChessRating | null;
}

export function ChessRatingBadge({ rating }: Props) {
    const t = useT();

    const unrated =
        rating === null || rating.is_unrated || rating.rating === null;

    if (unrated) {
        return (
            <span
                className="inline-flex shrink-0 items-center gap-1 rounded-full border border-border/60 bg-muted/40 px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                title={t('No verified rating yet')}
                aria-label={t('Unrated — no verified rating yet')}
            >
                <CircleDashed
                    className="size-3 opacity-70"
                    aria-hidden="true"
                />
                {t('Unrated')}
            </span>
        );
    }

    const value = rating!.rating!;
    const tier = tierFor(value);

    return (
        <span
            className={`inline-flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold tabular-nums ${PILL_TONE[tier]}`}
            title={t('Verified rating: :rating', { rating: value })}
            aria-label={t('Verified rating :rating', { rating: value })}
        >
            {value}
        </span>
    );
}
