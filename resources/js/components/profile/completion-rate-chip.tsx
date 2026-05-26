import type { ProfileTrust } from '@/types';

interface Props {
    trust: ProfileTrust;
    /**
     * Click handler — opens the "more info" modal (Slice B.2). Optional
     * during Slice B.1 so the chip can land before the modal exists; the
     * chip is still rendered as a button so the click target + a11y
     * affordances are in place.
     */
    onClick?: () => void;
}

/**
 * Compact trust-signal pill rendered on the public profile (M18 Phase 3
 * Slice B) and on listing rows (Slice B.3). Shows the completion rate
 * (settled ÷ engaged matches) + the lifetime settled-match count.
 *
 * Rate prefers the 30-day rolling window; falls back to lifetime when
 * 30-day activity is absent (e.g. an inactive but experienced user).
 * Modal disambiguates which window when the user wants the detail.
 *
 * Hidden entirely when `settled_lifetime === 0` — a profile with no
 * settled matches carries no completion signal worth surfacing.
 */
export function CompletionRateChip({ trust, onClick }: Props) {
    if (trust.settled_lifetime === 0) {
        return null;
    }

    // `??` (not `||`) so that a real 0% rate is preserved instead of
    // falling through to lifetime. 0/2 settled is genuinely 0%, not absence.
    const rate = trust.rate_30d ?? trust.rate_lifetime;

    if (rate === null) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={`Completion rate: ${rate}% across ${trust.settled_lifetime} settled matches. Click for details.`}
            className="inline-flex items-center gap-2 rounded-full border border-border/60 bg-card/60 px-3 py-1.5 text-sm transition-all duration-150 ease-out hover:border-primary/30 hover:bg-primary/10 hover:shadow-glow-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        >
            <span className="font-medium text-foreground tabular-nums">
                {rate}%
            </span>
            <span className="text-muted-foreground" aria-hidden="true">
                ·
            </span>
            <span className="text-muted-foreground tabular-nums">
                {trust.settled_lifetime}{' '}
                {trust.settled_lifetime === 1 ? 'match' : 'matches'}
            </span>
        </button>
    );
}
