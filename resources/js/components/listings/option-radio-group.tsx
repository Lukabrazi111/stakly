import type { ReactNode } from 'react';
import { RadioIndicator } from '@/components/shared/radio-indicator';
import { cn } from '@/lib/utils';

interface OptionRadioGroupOption {
    value: string;
    label: ReactNode;
}

interface OptionRadioGroupProps {
    name: string;
    value: string;
    onChange: (value: string) => void;
    options: OptionRadioGroupOption[];
    ariaLabel?: string;
    className?: string;
}

/**
 * Compact inline radio group for short binary / few-option choices (Format,
 * Your side) — the Bybit "Fixed / Floating Price" pattern. Reuses the shared
 * `RadioIndicator` dot from the notification-sound list so radios read the same
 * everywhere. Lighter than a filled segmented control where the choice is just
 * a short label and a roomy card would over-weight it.
 */
export function OptionRadioGroup({
    name,
    value,
    onChange,
    options,
    ariaLabel,
    className,
}: OptionRadioGroupProps) {
    return (
        <div
            role="radiogroup"
            aria-label={ariaLabel}
            className={cn('flex flex-wrap gap-x-6 gap-y-2', className)}
        >
            {options.map((option) => {
                const selected = option.value === value;

                return (
                    <label
                        key={option.value}
                        className="flex cursor-pointer items-center gap-2.5 py-1.5"
                    >
                        <input
                            type="radio"
                            name={name}
                            value={option.value}
                            checked={selected}
                            onChange={() => onChange(option.value)}
                            className="sr-only"
                        />
                        <RadioIndicator selected={selected} />
                        <span
                            className={cn(
                                'text-sm transition-colors',
                                selected
                                    ? 'font-medium text-foreground'
                                    : 'text-foreground/80 hover:text-foreground',
                            )}
                        >
                            {option.label}
                        </span>
                    </label>
                );
            })}
        </div>
    );
}
