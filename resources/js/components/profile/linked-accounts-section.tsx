export function LinkedAccountsSection() {
    return (
        <section>
            <h2 className="font-display text-foreground mb-3 text-lg font-semibold">
                Linked game accounts
            </h2>
            <div className="border-border/60 bg-card/60 flex flex-col gap-2 rounded-xl border p-2">
                <LinkedAccountRow name="chess.com" />
                <LinkedAccountRow name="Lichess" />
            </div>
            <p className="text-muted-foreground mt-2 text-xs">
                Link your accounts in settings.
            </p>
        </section>
    );
}

function LinkedAccountRow({ name }: { name: string }) {
    return (
        <div className="flex items-center justify-between rounded-lg px-3 py-2">
            <span className="text-foreground text-sm font-medium">{name}</span>
            <span className="border-border/60 bg-background/60 text-muted-foreground rounded-full border px-3 py-1 text-xs">
                Not linked
            </span>
        </div>
    );
}
