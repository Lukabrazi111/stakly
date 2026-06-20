import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type BGVariant =
    | 'dots'
    | 'diagonal-stripes'
    | 'grid'
    | 'horizontal-lines'
    | 'vertical-lines'
    | 'checkerboard';

type BGMask =
    | 'fade-center'
    | 'fade-edges'
    | 'fade-top'
    | 'fade-bottom'
    | 'fade-left'
    | 'fade-right'
    | 'fade-x'
    | 'fade-y'
    | 'none';

interface BGPatternProps extends ComponentProps<'div'> {
    variant?: BGVariant;
    mask?: BGMask;
    /** Tile size in px. Larger = sparser pattern. */
    size?: number;
    /**
     * Pattern stroke / fill colour. Use a translucent rgba so the texture
     * sits behind content without competing — e.g. the Stakly purple at
     * 18% alpha looks like brand atmosphere instead of plain noise.
     */
    fill?: string;
}

// Mask gradients use `black` (any opaque colour works — only the alpha is
// read by `mask-image`). Was `var(--background)` in the upstream component,
// changed to keep the intent obvious.
const maskClasses: Record<BGMask, string> = {
    'fade-edges':
        '[mask-image:radial-gradient(ellipse_at_center,black,transparent)]',
    'fade-center':
        '[mask-image:radial-gradient(ellipse_at_center,transparent,black)]',
    'fade-top': '[mask-image:linear-gradient(to_bottom,transparent,black)]',
    'fade-bottom': '[mask-image:linear-gradient(to_bottom,black,transparent)]',
    'fade-left': '[mask-image:linear-gradient(to_right,transparent,black)]',
    'fade-right': '[mask-image:linear-gradient(to_right,black,transparent)]',
    'fade-x':
        '[mask-image:linear-gradient(to_right,transparent,black,transparent)]',
    'fade-y':
        '[mask-image:linear-gradient(to_bottom,transparent,black,transparent)]',
    none: '',
};

function getBackgroundImage(
    variant: BGVariant,
    fill: string,
    size: number,
): string | undefined {
    switch (variant) {
        case 'dots':
            return `radial-gradient(${fill} 1px, transparent 1px)`;
        case 'grid':
            return `linear-gradient(to right, ${fill} 1px, transparent 1px), linear-gradient(to bottom, ${fill} 1px, transparent 1px)`;
        case 'diagonal-stripes':
            return `repeating-linear-gradient(45deg, ${fill}, ${fill} 1px, transparent 1px, transparent ${size}px)`;
        case 'horizontal-lines':
            return `linear-gradient(to bottom, ${fill} 1px, transparent 1px)`;
        case 'vertical-lines':
            return `linear-gradient(to right, ${fill} 1px, transparent 1px)`;
        case 'checkerboard':
            return `linear-gradient(45deg, ${fill} 25%, transparent 25%), linear-gradient(-45deg, ${fill} 25%, transparent 25%), linear-gradient(45deg, transparent 75%, ${fill} 75%), linear-gradient(-45deg, transparent 75%, ${fill} 75%)`;
        default:
            return undefined;
    }
}

/**
 * Static decorative pattern backdrop. Drop inside a `relative isolate`
 * wrapper as `absolute inset-0 -z-10` — or rely on its default positioning
 * (already absolute inset-0). Choose a translucent Stakly-tinted `fill` so
 * the pattern reads as ambient texture rather than visual noise.
 */
export function BGPattern({
    variant = 'grid',
    mask = 'none',
    size = 24,
    fill = 'rgba(168, 85, 247, 0.18)',
    className,
    style,
    ...props
}: BGPatternProps) {
    return (
        <div
            aria-hidden
            className={cn(
                'pointer-events-none absolute inset-0 -z-10 size-full',
                maskClasses[mask],
                className,
            )}
            style={{
                backgroundImage: getBackgroundImage(variant, fill, size),
                backgroundSize: `${size}px ${size}px`,
                ...style,
            }}
            {...props}
        />
    );
}
