import { cn } from '@/lib/utils';

interface SwitchProps {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    'aria-label'?: string;
}

export function Switch({
    checked,
    onCheckedChange,
    disabled,
    'aria-label': ariaLabel,
}: SwitchProps) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={ariaLabel}
            onClick={() => onCheckedChange(!checked)}
            disabled={disabled}
            className={cn(
                'inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full p-0.5 transition-colors duration-200',
                'focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                'disabled:cursor-not-allowed disabled:opacity-50',
                checked
                    ? 'bg-primary'
                    : 'border border-border/60 bg-muted',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'inline-block size-4 rounded-full bg-background shadow-sm transition-transform duration-200',
                    checked ? 'translate-x-4' : 'translate-x-0',
                )}
            />
        </button>
    );
}
