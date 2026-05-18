import { ShieldCheck } from 'lucide-react';
import type { UserProfile } from '@/types/profile';

interface Props {
    user: UserProfile;
}

/**
 * Renders the user's verified external game accounts on the public profile
 * (M8 Phase 1). Pending verifications are NEVER shown here — only completed
 * links surface publicly.
 */
export function LinkedAccountsSection({ user }: Props) {
    return (
        <section>
            <h2 className="font-display text-foreground mb-3 text-lg font-semibold">
                Linked game accounts
            </h2>
            <div className="border-border/60 bg-card/60 flex flex-col gap-2 rounded-xl border p-2">
                <LinkedAccountRow
                    name="chess.com"
                    username={user.chess_com_username}
                />
                <LinkedAccountRow
                    name="Lichess"
                    username={user.lichess_username}
                />
            </div>
        </section>
    );
}

function LinkedAccountRow({
    name,
    username,
}: {
    name: string;
    username: string | null;
}) {
    if (username === null) {
        return (
            <div className="flex items-center justify-between rounded-lg px-3 py-2">
                <span className="text-foreground text-sm font-medium">
                    {name}
                </span>
                <span className="border-border/60 bg-background/60 text-muted-foreground rounded-full border px-3 py-1 text-xs">
                    Not linked
                </span>
            </div>
        );
    }

    return (
        <div className="flex items-center justify-between rounded-lg px-3 py-2">
            <div className="flex items-center gap-2">
                <span className="text-foreground text-sm font-medium">
                    {name}
                </span>
                <code className="text-muted-foreground font-mono text-xs">
                    {username}
                </code>
            </div>
            <span className="bg-success/15 text-success border-success/30 inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium">
                <ShieldCheck className="size-3" />
                Verified
            </span>
        </div>
    );
}
