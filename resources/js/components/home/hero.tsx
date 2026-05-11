import { Link } from '@inertiajs/react';
import { useAuthModal } from '@/components/auth/auth-modal-provider';
import { Button } from '@/components/ui/button';

export function Hero() {
    const { openRegister } = useAuthModal();

    return (
        <section className="relative isolate overflow-hidden">
            <div aria-hidden className="pointer-events-none absolute inset-0 -z-10">
                <div className="bg-primary/25 absolute top-[-20%] left-[-10%] size-[60rem] rounded-full blur-3xl" />
                <div className="bg-accent/25 absolute right-[-15%] bottom-[-30%] size-[55rem] rounded-full blur-3xl" />
                <div
                    className="absolute inset-0 opacity-[0.04]"
                    style={{
                        backgroundImage:
                            'radial-gradient(circle at 1px 1px, #f5f5f7 1px, transparent 0)',
                        backgroundSize: '32px 32px',
                    }}
                />
                <div className="from-background/0 via-background/30 to-background absolute inset-0 bg-gradient-to-b" />
            </div>

            <div className="mx-auto flex max-w-5xl flex-col items-center px-4 pt-24 pb-20 text-center md:pt-32 md:pb-28">
                <p className="border-border/60 bg-card/60 text-muted-foreground mb-8 inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-xs tracking-widest uppercase backdrop-blur">
                    <span className="bg-success inline-block size-1.5 rounded-full" />
                    Live · chess.com & Lichess verified
                </p>

                <h1 className="font-display text-foreground text-4xl leading-[0.95] font-extrabold tracking-tight text-balance sm:text-5xl md:text-7xl lg:text-8xl">
                    Stake your skill.
                    <br />
                    <span className="text-gradient-primary">Find your match.</span>
                </h1>

                <p className="text-muted-foreground mt-8 max-w-2xl text-base leading-relaxed md:text-lg">
                    Post a listing, escrow your stake, play an opponent, and get paid.
                    A peer-to-peer arena for competitive players who put their money where
                    their rating is.
                </p>

                <div className="mt-10 flex flex-wrap items-center justify-center gap-3 ">
                    <Button
                        variant="gradient"
                        size="pill"
                        onClick={openRegister}
                    >
                        Get started
                    </Button>
                    <Button variant="ghost" size="lg" asChild>
                        <Link href="#how-it-works">How it works</Link>
                    </Button>
                </div>
            </div>
        </section>
    );
}
