import { BadgeCheck } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { ListingPlatform } from '@/types';

interface Props {
    rate: number | null;
    settled: number;
    /** Providers the creator is verified on. The green BadgeCheck icon
     *  appears ONLY when this list has 2+ entries (cross-platform credential). */
    verifiedProviders: ListingPlatform[];
}

const PROVIDER_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
};

/** Inline seller trust meta under the creator name. Hides when settled === 0. */
export function SellerTrustMeta({ rate, settled, verifiedProviders }: Props) {
    const t = useT();

    if (settled === 0) {
        return null;
    }

    const isCrossPlatform = verifiedProviders.length >= 2;
    const rateLabel = rate === null ? '—' : `${rate}%`;
    const matchCount =
        settled === 1
            ? t(':count match', { count: settled })
            : t(':count matches', { count: settled });

    const rateClause =
        rate === null
            ? `${t(':matches settled lifetime.', { matches: matchCount })} ${t('No engaged matches in the last 30 days.')}`
            : t(':rate% 30-day completion rate · :matches settled lifetime.', {
                  rate: rateLabel,
                  matches: matchCount,
              });

    const tooltip = isCrossPlatform
        ? `${t('Verified on :providers.', {
              providers: verifiedProviders
                  .map((p) => PROVIDER_LABEL[p])
                  .join(' + '),
          })} ${rateClause}`
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
            <span>{matchCount}</span>
        </span>
    );
}
