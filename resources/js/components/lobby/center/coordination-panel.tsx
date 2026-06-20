import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Copy, Info, ShieldCheck } from 'lucide-react';
import { useClipboard } from '@/hooks/use-clipboard';
import { useT } from '@/lib/i18n';
import { show as matchShow } from '@/routes/matches';
import type { Lobby } from '@/types';

interface Props {
    lobby: Lobby;
}

/**
 * State-dependent coordination panel — the FACEIT-username + match-page CTA
 * block in the center column once the lobby locks. Ready-check countdown
 * lives in `LobbyHeader` now (M34 P7), so this panel renders only for the
 * locked state.
 */
export function CoordinationPanel({ lobby }: Props) {
    if (lobby.lobby_state === 'locked') {
        return <LockedPanel lobby={lobby} />;
    }

    return null;
}

function LockedPanel({ lobby }: Props) {
    const t = useT();

    const teamAUsernames = lobby.roster.a
        .filter((s) => s !== null && s.platform_account !== null)
        .map((s) => s!.platform_account!.username);
    const teamBUsernames = lobby.roster.b
        .filter((s) => s !== null && s.platform_account !== null)
        .map((s) => s!.platform_account!.username);

    return (
        <section className="rounded-2xl border border-success/40 bg-success/10 p-5">
            <header className="flex items-center justify-center gap-1.5">
                <ShieldCheck
                    className="size-4 text-success"
                    aria-hidden="true"
                />
                <h3 className="text-[10px] font-semibold tracking-[0.18em] text-success uppercase">
                    {t('Locked in — coordinate on FACEIT')}
                </h3>
            </header>
            <p className="mt-2 text-center text-xs text-foreground/80">
                {t(
                    'Add each other on FACEIT and queue together as a stack. The result settles automatically once your match ends.',
                )}
            </p>

            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <UsernameList label={t('Team A')} usernames={teamAUsernames} />
                <UsernameList label={t('Team B')} usernames={teamBUsernames} />
            </div>

            <a
                href="https://faceit.com/en/parties"
                target="_blank"
                rel="noopener noreferrer"
                className="mt-4 block text-center text-xs text-success underline-offset-2 hover:underline"
            >
                {t('How to queue together on FACEIT →')}
            </a>

            {/* M34 P6 — once the lobby locks, the match is live and lives at
                /matches/{id}. Surface the destination so players know where
                to go for dispute / cancellation actions + the settlement
                summary (this lobby view becomes stale post-lock). */}
            {lobby.match_id !== null && (
                <Link
                    href={matchShow({ match: lobby.match_id }).url}
                    className="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-success px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-success/90"
                >
                    {t('View match page')}
                    <ArrowRight className="size-4" aria-hidden="true" />
                </Link>
            )}
        </section>
    );
}

interface UsernameListProps {
    label: string;
    usernames: string[];
}

function UsernameList({ label, usernames }: UsernameListProps) {
    const t = useT();

    return (
        <div className="rounded-xl border border-border/60 bg-card/60 p-3">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                    {label}
                </span>
                <Info
                    className="size-3 text-muted-foreground/60"
                    aria-hidden="true"
                />
            </div>
            {usernames.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    {t('No FACEIT usernames available.')}
                </p>
            ) : (
                <ul className="space-y-1.5">
                    {usernames.map((u) => (
                        <UsernameRow key={u} username={u} />
                    ))}
                </ul>
            )}
        </div>
    );
}

function UsernameRow({ username }: { username: string }) {
    const t = useT();
    const [copiedText, copy] = useClipboard();
    const isCopied = copiedText === username;

    return (
        <li className="flex items-center justify-between gap-2 rounded-lg border border-border/40 bg-background/50 px-2.5 py-1.5 text-xs">
            <span className="truncate font-mono text-foreground">
                {username}
            </span>
            <button
                type="button"
                onClick={() => copy(username)}
                aria-label={t('Copy :username', { username })}
                className="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-primary/10 hover:text-primary"
            >
                {isCopied ? (
                    <Check className="size-3 text-success" />
                ) : (
                    <Copy className="size-3" />
                )}
            </button>
        </li>
    );
}
