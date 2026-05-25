import { ExternalLink, ShieldCheck } from 'lucide-react';
import type { UserProfile } from '@/types/profile';

interface Props {
    user: UserProfile;
}

/**
 * Renders the user's verified external game accounts on the public profile
 * (M8 Phase 1, link-out added M18 Phase 3 Slice A). Pending verifications
 * are NEVER shown here — only completed links surface publicly.
 *
 * Each verified username is rendered as an external anchor pointing to
 * the provider's profile page, so a viewer can click through to verify
 * the user's actual chess.com / Lichess presence + see their rating +
 * activity. Stakly's contract is "we verified this person owns this
 * external account"; the rating signal lives on the external site.
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
                    externalUrl={
                        user.chess_com_username
                            ? `https://www.chess.com/member/${user.chess_com_username}`
                            : null
                    }
                />
                <LinkedAccountRow
                    name="Lichess"
                    username={user.lichess_username}
                    externalUrl={
                        user.lichess_username
                            ? `https://lichess.org/@/${user.lichess_username}`
                            : null
                    }
                />
            </div>
        </section>
    );
}

function LinkedAccountRow({
    name,
    username,
    externalUrl,
}: {
    name: string;
    username: string | null;
    externalUrl: string | null;
}) {
    if (username === null || externalUrl === null) {
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
                <a
                    href={externalUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="group inline-flex items-center gap-1 font-mono text-xs text-muted-foreground transition-colors hover:text-primary focus-visible:text-primary focus-visible:outline-none"
                    aria-label={`View ${username} on ${name} (opens in new tab)`}
                >
                    {username}
                    <ExternalLink className="size-3 opacity-60 transition-opacity group-hover:opacity-100" />
                </a>
            </div>
            <span className="inline-flex items-center gap-1 rounded-full border border-success/30 bg-success/15 px-2.5 py-0.5 text-xs font-medium text-success">
                <ShieldCheck className="size-3" />
                Verified
            </span>
        </div>
    );
}
