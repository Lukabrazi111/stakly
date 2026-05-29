import {
    type ChangeEvent,
    type CSSProperties,
    type InputHTMLAttributes,
    useState,
} from 'react';
import { cn } from '@/lib/utils';

interface NeonCheckboxProps
    extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    /**
     * Box size in pixels. Default 20 reads as a form-row checkbox rather
     * than a marketing badge. The particle / ring / spark effects sit on
     * fixed pixel offsets so they look proportionally larger at smaller
     * sizes — that's intentional (the explosion should "outshine" the box).
     */
    sizePx?: number;
}

const PARTICLE_OFFSETS: ReadonlyArray<{ x: string; y: string }> = [
    { x: '25px', y: '-25px' },
    { x: '-25px', y: '-25px' },
    { x: '25px', y: '25px' },
    { x: '-25px', y: '25px' },
    { x: '35px', y: '0px' },
    { x: '-35px', y: '0px' },
    { x: '0px', y: '35px' },
    { x: '0px', y: '-35px' },
    { x: '20px', y: '-30px' },
    { x: '-20px', y: '30px' },
    { x: '30px', y: '20px' },
    { x: '-30px', y: '-20px' },
];

/**
 * Heavily-animated Stakly-tinted checkbox. The unchecked state is a calm
 * outlined box; the checked state triggers a one-shot celebration —
 * particle explosion, expanding rings, sparks — followed by a sustained
 * flowing-border + glow while it stays checked.
 *
 * Reach for this in moments worth marking: an important opt-in, a final
 * confirmation, a "yes I'm in" affordance. For routine checkboxes (filter
 * toggles, settings rows), the shadcn `<Checkbox>` primitive is the
 * calmer pick.
 *
 * Honours `prefers-reduced-motion: reduce` via the CSS rule alongside the
 * keyframes in `app.css` — animations are suppressed entirely for users
 * who opted out; the checked state still reads via the colour change.
 */
export function NeonCheckbox({
    className,
    checked: controlledChecked,
    defaultChecked,
    onChange,
    sizePx = 20,
    ...props
}: NeonCheckboxProps) {
    const [internalChecked, setInternalChecked] = useState(
        defaultChecked ?? false,
    );

    const isControlled = controlledChecked !== undefined;
    const isChecked = isControlled ? controlledChecked : internalChecked;

    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
        if (!isControlled) {
            setInternalChecked(event.target.checked);
        }
        onChange?.(event);
    };

    return (
        <span
            className={cn(
                'relative inline-block shrink-0 align-middle',
                className,
            )}
            style={{ width: sizePx, height: sizePx }}
        >
            <input
                type="checkbox"
                className="peer sr-only"
                checked={isChecked}
                onChange={handleChange}
                {...props}
            />

            <span
                aria-hidden
                className={cn(
                    'absolute inset-0 rounded border-2 transition-all duration-200',
                    'bg-card/80',
                    'peer-focus-visible:ring-2 peer-focus-visible:ring-primary peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-background',
                    isChecked
                        ? 'border-primary bg-primary/10'
                        : 'border-primary/40',
                )}
            >
                <span className="absolute inset-[2px] flex items-center justify-center">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        className={cn(
                            'h-4/5 w-4/5 origin-center stroke-primary stroke-[3]',
                            '[stroke-dasharray:40] transition-all',
                            isChecked
                                ? 'scale-110 duration-200 ease-[cubic-bezier(0.16,1,0.3,1)] [stroke-dashoffset:0]'
                                : 'duration-100 ease-out [stroke-dashoffset:40]',
                        )}
                    >
                        <path d="M3,12.5l7,7L21,5" />
                    </svg>
                </span>

                <span
                    className={cn(
                        'pointer-events-none absolute -inset-0.5 rounded-md bg-primary blur-md transition-opacity duration-200',
                        isChecked ? 'opacity-25' : 'opacity-0',
                    )}
                />
            </span>

            <span
                aria-hidden
                className="pointer-events-none absolute inset-0"
            >
                {PARTICLE_OFFSETS.map((offset, i) => (
                    <span
                        key={i}
                        className={cn(
                            'absolute top-1/2 left-1/2 size-1 rounded-full bg-primary shadow-[0_0_6px_var(--color-primary)]',
                            isChecked
                                ? 'motion-safe:animate-[stakly-neon-particle-explosion_0.6s_ease-out_forwards] motion-reduce:opacity-0'
                                : 'opacity-0',
                        )}
                        style={
                            {
                                '--x': offset.x,
                                '--y': offset.y,
                            } as CSSProperties
                        }
                    />
                ))}
            </span>

            <span
                aria-hidden
                className="pointer-events-none absolute -inset-5"
            >
                {Array.from({ length: 3 }).map((_, i) => (
                    <span
                        key={i}
                        className={cn(
                            'absolute inset-0 scale-0 rounded-full border border-primary',
                            isChecked
                                ? 'motion-safe:animate-[stakly-neon-ring-pulse_0.6s_ease-out_forwards] motion-reduce:opacity-0'
                                : 'opacity-0',
                        )}
                        style={{ animationDelay: `${i * 0.1}s` }}
                    />
                ))}
            </span>

            <span
                aria-hidden
                className="pointer-events-none absolute inset-0"
            >
                {Array.from({ length: 4 }).map((_, i) => (
                    <span
                        key={i}
                        className={cn(
                            'absolute top-1/2 left-1/2 h-px w-5 bg-gradient-to-r from-primary to-transparent',
                            isChecked
                                ? 'motion-safe:animate-[stakly-neon-spark-flash_0.6s_ease-out_forwards] motion-reduce:opacity-0'
                                : 'opacity-0',
                        )}
                        style={{ '--r': `${i * 90}deg` } as CSSProperties}
                    />
                ))}
            </span>
        </span>
    );
}
