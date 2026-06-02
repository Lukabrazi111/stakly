import { Check } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

interface CheckboxProps
    extends Omit<
        React.InputHTMLAttributes<HTMLInputElement>,
        'type' | 'onChange'
    > {
    onCheckedChange?: (checked: boolean) => void;
}

function Checkbox({
    className,
    checked,
    disabled,
    onCheckedChange,
    ...props
}: CheckboxProps) {
    return (
        <span
            data-slot="checkbox"
            className={cn(
                'relative inline-flex shrink-0',
                disabled && 'opacity-50',
                className,
            )}
        >
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(e) => onCheckedChange?.(e.target.checked)}
                className="peer sr-only"
                {...props}
            />
            <span
                aria-hidden
                className={cn(
                    'size-4 rounded border border-border bg-card/60 transition-colors',
                    'peer-checked:border-primary peer-checked:bg-primary',
                    'peer-focus-visible:ring-2 peer-focus-visible:ring-primary/25 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-background',
                )}
            />
            <Check
                aria-hidden
                strokeWidth={3}
                className="pointer-events-none absolute top-1/2 left-1/2 hidden size-3 -translate-x-1/2 -translate-y-1/2 text-primary-foreground peer-checked:block"
            />
        </span>
    );
}

export { Checkbox };
