import { Check, Copy, Info, ShieldCheck, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useClipboard } from '@/hooks/use-clipboard';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Lobby } from '@/types';

interface Props {
    lobby: Lobby;
}

/**
 * State-dependent coordination panel — final block in the center column.
 * One of three sub-views by `lobby_state`:
 *   - recruiting: muted info banner + chat reminder.
 *   - ready_checking: big countdown + "click Ready up below" CTA hint.
 *   - locked: FACEIT username list with click-to-copy + party-invite tips.
 * Cancelled / expired / null → nothing renders.
 */
export function CoordinationPanel({ lobby }: Props) {
    if (lobby.lobby_state === 'recruiting') {
        return <RecruitingPanel lobby={lobby} />;
    }

    if (lobby.lobby_state === 'ready_checking') {
        return <ReadyCheckingPanel lobby={lobby} />;
    }

    if (lobby.lobby_state === 'locked') {
        return <LockedPanel lobby={lobby} />;
    }

    return null;
}

function RecruitingPanel({ lobby }: Props) {
    const t = useT();
    const max = lobby.team_size * 2;
    const filled =
        lobby.roster.a.filter((s) => s !== null).length +
        lobby.roster.b.filter((s) => s !== null).length;
    const remaining = Math.max(0, max - filled);

    return (
        <section className="rounded-2xl border border-border/60 bg-card/60 p-5 text-center">
            <Users
                className="mx-auto size-5 text-muted-foreground"
                aria-hidden="true"
            />
            <div className="mt-2 font-display text-lg font-semibold text-foreground">
                {remaining > 0
                    ? t('Waiting for :n more', { n: remaining })
                    : t('Lobby is full — Ready up below')}
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
                {t('Use chat to coordinate teams and time.')}
            </p>
        </section>
    );
}

function ReadyCheckingPanel({ lobby }: Props) {
    const t = useT();

    if (lobby.lobby_ready_check_deadline === null) {
        return null;
    }

    return (
        <section className="rounded-2xl border border-warning/40 bg-warning/10 p-5 text-center">
            <div className="text-[10px] font-semibold tracking-[0.18em] text-warning uppercase">
                {t('Ready check')}
            </div>
            <BigCountdown deadlineIso={lobby.lobby_ready_check_deadline} />
            <p className="mt-3 text-xs text-warning/90">
                {t('Tap Ready up below before the timer ends.')}
            </p>
        </section>
    );
}

function BigCountdown({ deadlineIso }: { deadlineIso: string }) {
    const [remainingMs, setRemainingMs] = useState(() =>
        Math.max(0, new Date(deadlineIso).getTime() - Date.now()),
    );

    useEffect(() => {
        const deadline = new Date(deadlineIso).getTime();
        const tick = () => setRemainingMs(Math.max(0, deadline - Date.now()));

        tick();
        const id = window.setInterval(tick, 1000);

        return () => window.clearInterval(id);
    }, [deadlineIso]);

    if (remainingMs <= 0) {
        return null;
    }

    const totalSeconds = Math.ceil(remainingMs / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    const isFinalStretch = totalSeconds <= 30;

    return (
        <div
            className={cn(
                'mt-3 font-display text-6xl leading-none font-bold tabular-nums',
                isFinalStretch
                    ? 'animate-pulse text-destructive'
                    : 'text-warning',
            )}
            aria-live="polite"
        >
            {minutes}:{seconds.toString().padStart(2, '0')}
        </div>
    );
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
