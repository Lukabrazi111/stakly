import { CircleDashed } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { FaceitRating } from '@/types/listings';

/**
 * Verified FACEIT rating pill (M41 P2). Shown on CS2 listings in place of the
 * old self-typed skill chip. Brand-banded by tier — entry (L1-3) muted, mid
 * (L4-7) pink, elite (L8-10) purple — never FACEIT's amber/red ramp, which
 * collides with Stakly's dispute/loss palette next to a money stake. The level
 * number carries the tier, so it stays colorblind-safe. Non-interactive
 * metadata: no hover, cursor, or glow (matches the sibling GameChip / skill chip).
 *
 * Two variants: `compact` (the marketplace cards + create preview, shipped in
 * P2) and `detail` (the fuller stat block — wired into the chess listing detail
 * page in M41 P4, when chess detail switches from its self-typed range).
 */

type FaceitTier = 'entry' | 'mid' | 'elite';

function tierFor(level: number): FaceitTier {
    if (level >= 8) {
        return 'elite';
    }

    if (level >= 4) {
        return 'mid';
    }

    return 'entry';
}

const PILL_TONE: Record<FaceitTier, string> = {
    entry: 'border-border/60 bg-card/60 text-muted-foreground',
    mid: 'border-primary/30 bg-primary/10 text-foreground',
    elite: 'border-accent/40 bg-accent/10 text-foreground',
};

const COIN_TONE: Record<FaceitTier, string> = {
    entry: 'bg-muted text-foreground/70',
    mid: 'bg-primary/20 text-primary',
    elite: 'bg-accent/25 text-accent',
};

interface Props {
    rating: FaceitRating | null;
    variant?: 'compact' | 'detail';
}

export function FaceitRatingBadge({ rating, variant = 'compact' }: Props) {
    const unrated =
        rating === null ||
        rating.is_unrated ||
        rating.elo === null ||
        rating.level === null;

    if (variant === 'detail') {
        return unrated ? (
            <DetailUnrated />
        ) : (
            <DetailRated elo={rating!.elo!} level={rating!.level!} />
        );
    }

    return unrated ? (
        <CompactUnrated />
    ) : (
        <CompactRated elo={rating!.elo!} level={rating!.level!} />
    );
}

function CompactRated({ elo, level }: { elo: number; level: number }) {
    const t = useT();
    const tier = tierFor(level);

    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium ${PILL_TONE[tier]}`}
            title={t('FACEIT level :level · :elo ELO', { level, elo })}
            aria-label={t('FACEIT level :level, :elo ELO', { level, elo })}
        >
            <span
                className={`inline-flex size-4 items-center justify-center rounded-full text-[10px] font-bold tabular-nums ${COIN_TONE[tier]}`}
                aria-hidden="true"
            >
                {level}
            </span>
            <span className="text-foreground tabular-nums">{elo}</span>
            <span
                className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                aria-hidden="true"
            >
                elo
            </span>
        </span>
    );
}

function CompactUnrated() {
    const t = useT();

    return (
        <span
            className="inline-flex shrink-0 items-center gap-1 rounded-full border border-border/60 bg-muted/40 px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
            title={t('No FACEIT rating yet')}
            aria-label={t('Unrated — no FACEIT rating yet')}
        >
            <CircleDashed className="size-3 opacity-70" aria-hidden="true" />
            {t('Unrated')}
        </span>
    );
}

function DetailRated({ elo, level }: { elo: number; level: number }) {
    const t = useT();
    const tier = tierFor(level);

    return (
        <div className="inline-flex items-center gap-3 rounded-xl border border-border/60 bg-card/60 px-3 py-2">
            <span
                className={`inline-flex size-9 items-center justify-center rounded-full text-base font-bold tabular-nums ${
                    tier === 'elite'
                        ? 'bg-gradient-primary text-primary-foreground'
                        : COIN_TONE[tier]
                }`}
                aria-hidden="true"
            >
                {level}
            </span>
            <div className="flex flex-col leading-tight">
                <span className="text-sm font-semibold text-foreground">
                    <span className="tabular-nums">{elo}</span>
                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                        ELO
                    </span>
                </span>
                <span className="text-xs text-muted-foreground">
                    {t('FACEIT level :level', { level })}
                </span>
            </div>
        </div>
    );
}

function DetailUnrated() {
    const t = useT();

    return (
        <div className="inline-flex items-center gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-2">
            <span
                className="inline-flex size-9 items-center justify-center rounded-full bg-muted text-muted-foreground"
                aria-hidden="true"
            >
                <CircleDashed className="size-4" />
            </span>
            <div className="flex flex-col leading-tight">
                <span className="text-sm font-semibold text-muted-foreground">
                    {t('Unrated')}
                </span>
                <span className="text-xs text-muted-foreground">
                    {t('No FACEIT rating yet')}
                </span>
            </div>
        </div>
    );
}
