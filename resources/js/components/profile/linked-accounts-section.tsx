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
            <h2 className="mb-3 font-display text-lg font-semibold text-foreground">
                Linked game accounts
            </h2>
            <div className="flex flex-col gap-2 rounded-xl border border-border/60 bg-card/60 p-2">
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
                <span className="text-sm font-medium text-foreground">
                    {name}
                </span>
                <span className="rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs text-muted-foreground">
                    Not linked
                </span>
            </div>
        );
    }

    return (
        <div className="flex items-center justify-between rounded-lg px-3 py-2">
            <div className="flex items-center gap-2">
                <span className="text-sm font-medium text-foreground">
                    {name}
                </span>
                <code className="font-mono text-xs text-muted-foreground">
                    {username}
                </code>
            </div>
            <span className="inline-flex items-center gap-1 rounded-full border border-success/30 bg-success/15 px-2.5 py-0.5 text-xs font-medium text-success">
                <ShieldCheck className="size-3" />
                Verified
            </span>
        </div>
    );
}
