import { Check } from 'lucide-react';
import type { ReactNode } from 'react';
import { ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

interface SegmentedOptionProps {
    value: string;
    label: ReactNode;
    /** When set, renders the two-line "option card" layout (icon + title + this). */
    description?: ReactNode;
    /** Size the icon at the call site (e.g. `size-5`) so the toggle base doesn't force it to size-4. */
    icon?: ReactNode;
    className?: string;
}

/**
 * One option in a segmented `ToggleGroup` (Platform / Format / Your side /
 * Visibility on the create-listing form). Replaces the per-call class soup with
 * one consistent, branded treatment:
 *
 * - Strong selected affordance — solid `border-primary` + pink wash + a corner
 *   check, so the active state never relies on color alone (a11y).
 * - Brighter idle text than the shadcn default (`text-foreground/70`, not
 *   `text-muted-foreground`) so the unselected option doesn't read as disabled.
 * - `description` switches to the taller two-line card; omit it for a compact
 *   centered pill.
 */
export function SegmentedOption({
    value,
    label,
    description,
    icon,
    className,
}: SegmentedOptionProps) {
    const hasDescription = description != null;

    return (
        <ToggleGroupItem
            value={value}
            className={cn(
                'group/seg relative w-full cursor-pointer rounded-xl border border-border/60 bg-card/40 px-4 text-foreground/70',
                'hover:border-primary/40 data-[state=on]:border-primary data-[state=on]:bg-primary/15 data-[state=on]:text-foreground',
                hasDescription
                    ? 'flex h-auto min-h-16 items-start gap-3 py-3 text-left'
                    : 'flex h-12 items-center justify-center gap-2',
                className,
            )}
        >
            {icon != null && (
                <span
                    className={cn(
                        'flex shrink-0 items-center justify-center',
                        hasDescription && 'mt-0.5',
                    )}
                >
                    {icon}
                </span>
            )}

            <span
                className={cn(
                    'flex min-w-0 flex-col',
                    hasDescription ? 'items-start' : 'items-center',
                )}
            >
                <span className="leading-tight font-medium">{label}</span>
                {hasDescription && (
                    <span className="mt-0.5 text-xs leading-snug text-muted-foreground group-data-[state=on]/seg:text-foreground/75">
                        {description}
                    </span>
                )}
            </span>

            <Check
                aria-hidden
                className="absolute top-3 right-3 size-4 text-primary opacity-0 transition-opacity duration-150 group-data-[state=on]/seg:opacity-100"
            />
        </ToggleGroupItem>
    );
}
