import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { MouseEvent } from 'react';

interface Props {
    /**
     * Fallback URL when there's no history to go back to (e.g., user
     * landed via direct URL / shared link / fresh tab). Should be the
     * sensible parent of the current page (e.g., `/listings` for listing
     * detail, `/wallet` for wallet sub-pages).
     */
    fallback: string;
    /**
     * Custom label. Default is just "Back" — keeping it generic matches
     * what the button actually does (`history.back()` doesn't know the
     * specific destination).
     */
    label?: string;
    className?: string;
}

/**
 * Smart back link. On left-click, calls `window.history.back()` if this
 * tab has navigation history; otherwise falls through to Inertia `<Link>`
 * navigation to `fallback`.
 *
 * Renders as a real `<a>` (via Inertia's Link) so right-click / modified
 * clicks ("Open in new tab", cmd+click, middle-click) work correctly —
 * only plain in-app left-clicks trigger the smart back behavior.
 *
 * Why generic "Back" instead of "Back to X":
 *   - Matches the browser's own Back button semantics
 *   - The actual destination is whatever's in history, which may not
 *     match a single static label (user could have come from /listings,
 *     /listings/mine, a profile, or a deep link)
 *   - Less misleading than a label that points to one page when the
 *     button actually returns to wherever the user just was
 */
export function BackLink({ fallback, label = 'Back', className }: Props) {
    const handleClick = (event: MouseEvent<Element>) => {
        // Let modifier-clicks take the normal Link path — those open in
        // new tabs/windows where `history.back()` wouldn't make sense.
        if (
            event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.button !== 0
        ) {
            return;
        }

        // `history.length > 1` means the user navigated to this page
        // from somewhere else in the same tab. `length === 1` = direct
        // landing (fresh tab, shared link, deep link) — fall through to
        // Link's default navigation to `fallback`.
        if (window.history.length > 1) {
            event.preventDefault();
            window.history.back();
        }
    };

    return (
        <Link
            href={fallback}
            onClick={handleClick}
            className={
                className
                ?? 'text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm transition-colors'
            }
        >
            <ChevronLeft className="size-4" />
            {label}
        </Link>
    );
}
