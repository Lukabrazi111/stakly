import { cn } from '@/lib/utils';

/**
 * Stakly radio dot — the selected/unselected indicator shared by the
 * notification-sound list (`settings/notifications`) and the create-listing
 * radio choices. Decorative (`aria-hidden`); pair it with an `sr-only`
 * `<input type="radio">` for keyboard + screen-reader semantics.
 */
export function RadioIndicator({ selected }: { selected: boolean }) {
    return (
        <span
            aria-hidden
            className={cn(
                'relative inline-flex size-4 shrink-0 items-center justify-center rounded-full border transition-colors',
                'after:absolute after:rounded-full after:transition-all after:duration-150',
                selected
                    ? 'border-primary bg-primary/10 after:size-2 after:bg-primary'
                    : 'border-border bg-card/60 after:size-0',
            )}
        />
    );
}
