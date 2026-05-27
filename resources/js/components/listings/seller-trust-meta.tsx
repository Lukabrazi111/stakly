import { BadgeCheck } from 'lucide-react';
import type { ListingPlatform } from '@/types';

interface Props {
    rate: number | null;
    settled: number;
    /**
     * M22 Phase 1 (badge tier) — providers the creator is verified on.
     * The green `BadgeCheck` icon appears in the meta line ONLY when this
     * list has 2+ entries (cross-platform earned credential). For chess
     * today that means chess.com + Lichess; future M15 providers (FACEIT,
     * Riot, Steam) extend the rule naturally — any 2+ verified providers
     * earns the badge.
     */
    verifiedProviders: ListingPlatform[];
}

const PROVIDER_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
};

/**
 * Seller trust signal as inline meta under the creator name (M22 Phase 1).
 * Mirrors Bybit's "503 Order(s) | 91% | 6m" pattern — small gray text below
 * the seller name showing 30-day completion rate + lifetime settled count.
 *
 * The leading green `BadgeCheck` icon is an *earned* signal: it appears
 * only when the creator has verified accounts on 2+ providers (cross-
 * platform credential). Single-platform sellers see the meta line without
 * the icon — the trust info is still there, just no extra badge.
 *
 * Hides entirely when `settled === 0` (no track record).
 */
export function SellerTrustMeta({ rate, settled, verifiedProviders }: Props) {
    if (settled === 0) {
        return null;
    }

    const isCrossPlatform = verifiedProviders.length >= 2;
    const rateLabel = rate === null ? '—' : `${rate}%`;
    const matchLabel = `${settled} ${settled === 1 ? 'match' : 'matches'}`;

    const rateClause =
        rate === null
            ? `${matchLabel} settled lifetime. No engaged matches in the last 30 days.`
            : `${rateLabel} 30-day completion rate · ${matchLabel} settled lifetime.`;

    const tooltip = isCrossPlatform
        ? `Verified on ${verifiedProviders.map((p) => PROVIDER_LABEL[p]).join(' + ')}. ${rateClause}`
        : rateClause;

    return (
        <span
            className="inline-flex items-center gap-1 text-xs text-muted-foreground"
            title={tooltip}
            aria-label={tooltip}
        >
            {isCrossPlatform && (
                <BadgeCheck
                    className="size-3 text-success"
                    aria-hidden="true"
                />
            )}
            <span className="text-foreground">{rateLabel}</span>
            <span aria-hidden="true" className="opacity-60">
                ·
            </span>
            <span>{matchLabel}</span>
        </span>
    );
}
