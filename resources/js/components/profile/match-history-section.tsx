export function MatchHistorySection() {
    return (
        <section>
            <h2 className="font-display text-foreground mb-3 text-lg font-semibold">
                Match history
            </h2>
            <div className="border-border/60 bg-card/40 rounded-xl border border-dashed p-8 text-center">
                <p className="text-muted-foreground text-sm">No matches yet.</p>
                <p className="text-muted-foreground/80 mt-1 text-xs">
                    Match history will appear here once matches are played.
                </p>
            </div>
        </section>
    );
}
