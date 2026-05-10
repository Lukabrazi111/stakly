import { FileText, Handshake, Trophy } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

interface Step {
    number: string;
    title: string;
    description: string;
    icon: LucideIcon;
}

const steps: Step[] = [
    {
        number: '01',
        title: 'Post a listing',
        description:
            'Set your stake in USDT, your rating range, and your preferred time control. Your stake is escrowed the moment your listing goes live.',
        icon: FileText,
    },
    {
        number: '02',
        title: 'Match an opponent',
        description:
            'An opponent takes your listing. Both stakes are held in escrow and the match starts instantly on chess.com or Lichess.',
        icon: Handshake,
    },
    {
        number: '03',
        title: 'Play and get paid',
        description:
            'The result is pulled directly from the game API. The winner takes the pot minus a small platform fee — payout in seconds.',
        icon: Trophy,
    },
];

export function HowItWorks() {
    return (
        <section id="how-it-works" className="scroll-mt-20">
            <div className="mx-auto max-w-7xl px-4 py-20 md:py-28">
                <div className="mx-auto max-w-2xl text-center">
                    <p className="text-muted-foreground mb-3 text-xs tracking-widest uppercase">
                        How it works
                    </p>
                    <h2 className="font-display text-foreground text-3xl font-extrabold tracking-tight md:text-5xl">
                        Three steps from{' '}
                        <span className="text-gradient-primary">listing to payout</span>.
                    </h2>
                    <p className="text-muted-foreground mt-4 text-base md:text-lg">
                        Stakly is custodial by design — your funds are held in escrow until
                        the game API confirms the result. No screenshots, no waiting.
                    </p>
                </div>

                <ol className="mt-14 grid gap-4 md:grid-cols-3 md:gap-6">
                    {steps.map((step, index) => {
                        const Icon = step.icon;
                        return (
                            <li
                                key={step.number}
                                className="border-border bg-card relative overflow-hidden rounded-2xl border p-6 transition-colors hover:border-primary/40 md:p-8"
                            >
                                <div className="bg-primary/5 absolute -top-12 -right-12 size-40 rounded-full blur-3xl" />

                                <div className="relative flex items-center justify-between">
                                    <span className="font-display text-muted-foreground/40 text-5xl font-extrabold tracking-tight">
                                        {step.number}
                                    </span>
                                    <span className="border-border/60 bg-background/60 inline-flex size-11 items-center justify-center rounded-full border">
                                        <Icon
                                            className="text-primary size-5"
                                            strokeWidth={1.75}
                                            aria-hidden
                                        />
                                    </span>
                                </div>

                                <h3 className="font-display text-foreground relative mt-6 text-xl font-bold tracking-tight">
                                    {step.title}
                                </h3>
                                <p className="text-muted-foreground relative mt-2 text-sm leading-relaxed">
                                    {step.description}
                                </p>

                                {index < steps.length - 1 && (
                                    <div
                                        aria-hidden
                                        className="from-border absolute top-1/2 -right-3 hidden h-px w-6 -translate-y-1/2 bg-gradient-to-r to-transparent md:block"
                                    />
                                )}
                            </li>
                        );
                    })}
                </ol>
            </div>
        </section>
    );
}
