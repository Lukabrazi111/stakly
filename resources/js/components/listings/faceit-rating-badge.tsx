import { CircleDashed } from 'lucide-react';
import { faceitLevelColor } from '@/lib/faceit-level';
import { useT } from '@/lib/i18n';
import type { FaceitRating } from '@/types/listings';

/**
 * Verified FACEIT rating badge (M41 P2 → P7). The level renders as a circular
 * DIAL filled proportionally to the level (level 9 ≈ 90%), in AUTHENTIC FACEIT
 * colors (grey / light-blue / blue / green / gold per the official ladder — see
 * `faceit-level.ts`), the level number centered in `text-foreground` (WCAG AA)
 * with the ELO beside it. The number carries the meaning, so the dial stays
 * readable as a LEVEL — the green/gold rings don't read as win/dispute status
 * next to a stake. Non-interactive metadata: no hover, cursor, or glow.
 *
 * Two variants: `compact` (marketplace cards, rows, lobby slots, create preview)
 * and `detail` (the fuller stat block on the listing detail page).
 */

interface Props {
    rating: FaceitRating | null;
    variant?: 'compact' | 'detail';
    // Compact dial diameter in px. Marketplace cards use the smaller default;
    // the lobby slot card passes a larger value for its prominent roster look.
    dialSize?: number;
}

export function FaceitRatingBadge({
    rating,
    variant = 'compact',
    dialSize = 25,
}: Props) {
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
        <CompactRated
            elo={rating!.elo!}
            level={rating!.level!}
            dialSize={dialSize}
        />
    );
}

// ── Dial size knobs ─────────────────────────────────────────────────────────
// Overall DIAMETER is the `dialSize` prop on FaceitRatingBadge: the default 22
// (marketplace cards) lives in the Props above; the lobby slot card passes 32.
// The two ratios below (fractions of the diameter) reshape every dial at once:
const RING_THICKNESS = 0.11; // ring stroke — raise (e.g. 0.16) for a chunkier ring
const LEVEL_NUMBER_SIZE = 0.4; // centered level digit — raise for a bigger number
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Circular FACEIT level dial — a faint full track + a progress arc filled to
 * level/10 in the level's authentic color, the number centered. Decorative
 * (`aria-hidden`); the calling badge carries the accessible label.
 */
function LevelDial({ level, size }: { level: number; size: number }) {
    const stroke = Math.max(2, Math.round(size * RING_THICKNESS));
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const filled = (Math.min(level, 10) / 10) * circumference;

    return (
        <span
            className="relative inline-flex shrink-0 items-center justify-center"
            style={{ width: size, height: size }}
            aria-hidden="true"
        >
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                className="-rotate-90"
            >
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="var(--border)"
                    strokeWidth={stroke}
                />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke={faceitLevelColor(level)}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={`${filled} ${circumference}`}
                />
            </svg>
            <span
                className="absolute inset-0 flex items-center justify-center font-bold text-foreground tabular-nums"
                style={{ fontSize: Math.round(size * LEVEL_NUMBER_SIZE) }}
            >
                {level}
            </span>
        </span>
    );
}

function CompactRated({
    elo,
    level,
    dialSize,
}: {
    elo: number;
    level: number;
    dialSize: number;
}) {
    const t = useT();

    // Reference look (M41 P8): bare `ELO  (dial)` — number first, dial on the
    // right, no surrounding pill, no "ELO" suffix label.
    return (
        <span
            className="inline-flex shrink-0 items-center gap-2"
            title={t('FACEIT level :level · :elo ELO', { level, elo })}
            aria-label={t('FACEIT level :level, :elo ELO', { level, elo })}
        >
            <span className="text-sm font-semibold text-foreground tabular-nums">
                {elo}
            </span>
            <LevelDial level={level} size={dialSize} />
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

    return (
        <div className="inline-flex items-center gap-3 rounded-xl border border-border/60 bg-card/60 px-3 py-2">
            <LevelDial level={level} size={38} />
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
