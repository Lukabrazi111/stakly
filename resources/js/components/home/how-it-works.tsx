import { FileText, Handshake, Trophy } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';

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
            'Set your stake in USDT, your skill range, and your match preferences. Your stake is escrowed the moment your listing goes live.',
        icon: FileText,
    },
    {
        number: '02',
        title: 'Match an opponent',
        description:
            'An opponent takes your listing. Both stakes are held in escrow and the match starts instantly on chess.com, Lichess, or FACEIT.',
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
    const reduceMotion = useReducedMotion();

    return (
        <section id="how-it-works" className="scroll-mt-28">
            <div className="mx-auto max-w-7xl px-4 py-12 md:py-16">
                <div className="mx-auto max-w-2xl text-center">
                    <p className="mb-3 text-xs tracking-widest text-muted-foreground uppercase">
                        How it works
                    </p>
                    <h2 className="font-display text-3xl font-extrabold tracking-tight text-foreground md:text-5xl">
                        Three steps from{' '}
                        <span className="text-gradient-primary">
                            listing to payout
                        </span>
                        .
                    </h2>
                    <p className="mt-4 text-base text-muted-foreground md:text-lg">
                        Stakly is custodial by design — your funds are held in
                        escrow until the game API confirms the result. No
                        screenshots, no waiting.
                    </p>
                </div>

                <ol className="mt-14 grid gap-4 md:grid-cols-3 md:gap-6">
                    {steps.map((step, index) => {
                        const Icon = step.icon;

                        // Scroll-triggered cascade (01 → 02 → 03). Suppressed
                        // under prefers-reduced-motion — the card just renders.
                        const motionProps = reduceMotion
                            ? {}
                            : {
                                  initial: { opacity: 0, y: 16 },
                                  whileInView: { opacity: 1, y: 0 },
                                  viewport: { once: true, amount: 0.4 },
                                  transition: {
                                      duration: 0.4,
                                      ease: 'easeOut' as const,
                                      delay: index * 0.12,
                                  },
                              };

                        return (
                            <motion.li
                                key={step.number}
                                {...motionProps}
                                className="relative overflow-hidden rounded-2xl border border-border bg-card p-6 transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-glow-sm md:p-8"
                            >
                                <div className="absolute -top-12 -right-12 size-40 rounded-full bg-primary/5 blur-3xl" />

                                <div className="relative flex items-center justify-between">
                                    <span className="font-display text-5xl font-extrabold tracking-tight text-primary/30">
                                        {step.number}
                                    </span>
                                    <span className="inline-flex size-11 items-center justify-center rounded-full border border-border/60 bg-background/60">
                                        <Icon
                                            className="size-5 text-primary"
                                            strokeWidth={1.75}
                                            aria-hidden
                                        />
                                    </span>
                                </div>

                                <h3 className="relative mt-6 font-display text-xl font-bold tracking-tight text-foreground">
                                    {step.title}
                                </h3>
                                <p className="relative mt-2 text-sm leading-relaxed text-muted-foreground">
                                    {step.description}
                                </p>

                                {index < steps.length - 1 && (
                                    <div
                                        aria-hidden
                                        className="absolute top-1/2 -right-3 hidden h-px w-6 -translate-y-1/2 bg-gradient-to-r from-border to-transparent md:block"
                                    />
                                )}
                            </motion.li>
                        );
                    })}
                </ol>
            </div>
        </section>
    );
}
