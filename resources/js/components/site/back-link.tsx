import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { MouseEvent } from 'react';

interface Props {
    /** URL when there's no history (direct landing / shared link / fresh tab). */
    fallback: string;
    label?: string;
    className?: string;
}

/**
 * Smart back link — `history.back()` when the tab has history, otherwise
 * falls through to Inertia navigation to `fallback`. Renders as a real
 * `<a>` so modifier-clicks (cmd+click, middle-click) work normally.
 */
export function BackLink({ fallback, label = 'Back', className }: Props) {
    const handleClick = (event: MouseEvent<Element>) => {
        // Modifier-clicks open in new tabs where `history.back()` doesn't apply.
        if (
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.button !== 0
        ) {
            return;
        }

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
                className ??
                'inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground'
            }
        >
            <ChevronLeft className="size-4" />
            {label}
        </Link>
    );
}
