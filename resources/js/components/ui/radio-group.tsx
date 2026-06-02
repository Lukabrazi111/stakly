import { createContext, useContext, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface RadioGroupContextValue {
    value: string | null;
    onChange: (value: string) => void;
    disabled?: boolean;
    name?: string;
}

const RadioGroupContext = createContext<RadioGroupContextValue | null>(null);

interface RadioGroupProps {
    label?: string;
    value: string | null;
    onChange: (value: string) => void;
    disabled?: boolean;
    name?: string;
    className?: string;
    children?: ReactNode;
}

export function RadioGroup({
    label,
    value,
    onChange,
    disabled,
    name,
    className,
    children,
}: RadioGroupProps) {
    return (
        <RadioGroupContext.Provider value={{ value, onChange, disabled, name }}>
            {label && <span className="sr-only">{label}</span>}
            <div role="radiogroup" className={className}>
                {children}
            </div>
        </RadioGroupContext.Provider>
    );
}

interface RadioGroupItemProps {
    value: string;
    className?: string;
    children?: ReactNode;
}

function RadioGroupItem({ value, className, children }: RadioGroupItemProps) {
    const ctx = useContext(RadioGroupContext);

    if (!ctx) {
        throw new Error('RadioGroup.Item must be used inside a RadioGroup');
    }

    const isSelected = ctx.value === value;

    return (
        <label
            className={cn(
                'group flex cursor-pointer items-center gap-3',
                ctx.disabled && 'cursor-not-allowed opacity-60',
                className,
            )}
        >
            <input
                type="radio"
                name={ctx.name}
                value={value}
                checked={isSelected}
                onChange={(event) => ctx.onChange(event.target.value)}
                disabled={ctx.disabled}
                className="sr-only"
            />
            <span
                aria-hidden
                className={cn(
                    'relative inline-flex size-4 shrink-0 items-center justify-center rounded-full border transition-all duration-200 ease-out',
                    'after:absolute after:rounded-full after:transition-all after:duration-200 after:ease-out',
                    isSelected
                        ? 'border-primary bg-primary/10 after:size-2 after:bg-primary'
                        : 'border-border bg-card/60 after:size-0',
                    !isSelected &&
                        !ctx.disabled &&
                        'group-hover:border-primary/50 group-hover:bg-primary/5',
                )}
            />
            {children}
        </label>
    );
}

RadioGroup.Item = RadioGroupItem;