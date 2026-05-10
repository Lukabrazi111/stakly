import { Sparkles } from 'lucide-react';

export interface MarqueeItem {
    label: string;
    value: string;
}

interface MarqueeStripProps {
    items: MarqueeItem[];
}

export function MarqueeStrip({ items }: MarqueeStripProps) {
    const row = (prefix: string, ariaHidden = false) => (
        <div
            className="flex shrink-0 items-center gap-12 px-8"
            aria-hidden={ariaHidden}
        >
            {items.map((item, i) => (
                <div
                    key={`${prefix}-${i}`}
                    className="flex items-center gap-3 text-sm whitespace-nowrap"
                >
                    <Sparkles className="size-4 text-primary" />
                    <span className="text-gradient-primary font-display font-bold tracking-wide uppercase">
                        {item.label}:
                    </span>
                    <span className="text-foreground/80">{item.value}</span>
                </div>
            ))}
        </div>
    );

    return (
        <div className="bg-card/70 border-border/50 sticky top-16 z-40 overflow-hidden border-y py-3 backdrop-blur-lg">
            <div className="animate-marquee motion-reduce:animate-none flex w-max motion-reduce:flex-wrap motion-reduce:justify-center">
                {row('a')}
                {row('b', true)}
            </div>
        </div>
    );
}
