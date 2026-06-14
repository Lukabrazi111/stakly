import { Check, Crown, Share2, Swords, Target } from 'lucide-react';
import type { ElementType } from 'react';
import { useEffect, useState } from 'react';
import { leaderLabel, pickLeader } from '@/components/lobby/lobby-leader';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { GameId } from '@/config/games';
import { useClipboard } from '@/hooks/use-clipboard';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Lobby, LobbyParticipantPayload } from '@/types';

const GAME_META: Record<GameId, { icon: ElementType; label: string }> = {
    chess: { icon: Crown, label: 'Chess' },
    cs2: { icon: Target, label: 'CS2' },
    dota2: { icon: Swords, label: 'Dota 2' },
};

interface Props {
    lobby: Lobby;
}

/**
 * FACEIT-style lobby header — replaces the previous title block on the
 * team-play lobby view. Transparent (no card surface) so it floats above
 * the slot columns + center column below; those carry the visual weight.
 *
 * Layout: Team A leader (left) | mode chip + state-aware countdown + meta
 * (center) | Team B leader (right) + Share button absolute top-right.
 *
 * Countdown source switches with `lobby_state`:
 *   - recruiting   → `expires_at`               (listing fill timeout)
 *   - ready_checking → `lobby_ready_check_deadline` (5-min ready window)
 *   - locked       → `match_deadline_at`        (4h match-confirmation window)
 *   - cancelled / expired → no countdown; show terminal status badge instead.
 */
export function LobbyHeader({ lobby }: Props) {
    const t = useT();
    const stakePerPlayer = lobby.stake_amount;
    const fillCountA = lobby.roster.a.filter((s) => s !== null).length;
    const fillCountB = lobby.roster.b.filter((s) => s !== null).length;

    return (
        <header className="relative border-b border-border/60 pt-12 pb-5 lg:px-14 lg:pb-6">
            <div className="absolute top-0 right-0">
                <ShareButton />
            </div>

            <div className="grid grid-cols-1 items-center gap-5 lg:grid-cols-[1fr_auto_1fr] lg:gap-6">
                <TeamSide
                    leader={pickLeader(lobby.roster.a)}
                    fallback={t('Team A')}
                    fillCount={fillCountA}
                    teamSize={lobby.team_size}
                    align="start"
                />

                <div className="order-first flex min-w-0 flex-col items-center gap-2 lg:order-none">
                    <ModeRow lobby={lobby} />
                    <StateAwareCountdown lobby={lobby} />
                    <p className="text-center text-xs text-muted-foreground">
                        {t(':amount USDT per player', {
                            amount: `$${stakePerPlayer}`,
                        })}
                    </p>
                </div>

                <TeamSide
                    leader={pickLeader(lobby.roster.b)}
                    fallback={t('Team B')}
                    fillCount={fillCountB}
                    teamSize={lobby.team_size}
                    align="end"
                />
            </div>
        </header>
    );
}

interface TeamSideProps {
    leader: LobbyParticipantPayload | null;
    fallback: string;
    fillCount: number;
    teamSize: number;
    align: 'start' | 'end';
}

function TeamSide({
    leader,
    fallback,
    fillCount,
    teamSize,
    align,
}: TeamSideProps) {
    const getInitials = useInitials();
    const label = leaderLabel(leader, fallback);
    const flexDir = align === 'end' ? 'flex-row-reverse' : 'flex-row';
    const textAlign = align === 'end' ? 'text-right' : 'text-left';

    return (
        <div className={cn('flex min-w-0 items-center gap-3', flexDir)}>
            <Avatar className="size-11 shrink-0 overflow-hidden rounded-full">
                {leader?.user.avatar_thumb_url && (
                    <AvatarImage
                        src={leader.user.avatar_thumb_url}
                        alt={leader.user.username}
                    />
                )}
                <AvatarFallback className="bg-gradient-primary text-sm font-semibold text-primary-foreground">
                    {leader === null ? '?' : getInitials(leader.user.name)}
                </AvatarFallback>
            </Avatar>
            <div className={cn('min-w-0', textAlign)}>
                <h2 className="truncate font-display text-base font-semibold tracking-wide text-foreground">
                    {label}
                </h2>
                <span className="text-xs text-muted-foreground tabular-nums">
                    {fillCount} / {teamSize}
                </span>
            </div>
        </div>
    );
}

function ModeRow({ lobby }: { lobby: Lobby }) {
    const t = useT();
    const meta = GAME_META[lobby.game];
    const Icon = meta?.icon ?? Target;

    return (
        <div className="flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-foreground">
            <Icon className="size-3 text-primary" aria-hidden="true" />
            <span>{t(meta?.label ?? lobby.game)}</span>
            <span className="text-muted-foreground/60" aria-hidden="true">
                ·
            </span>
            <span className="font-semibold text-accent">
                {lobby.team_size}v{lobby.team_size}
            </span>
            {lobby.region && (
                <>
                    <span
                        className="text-muted-foreground/60"
                        aria-hidden="true"
                    >
                        ·
                    </span>
                    <span className="text-muted-foreground">
                        {lobby.region}
                    </span>
                </>
            )}
        </div>
    );
}

function StateAwareCountdown({ lobby }: { lobby: Lobby }) {
    const t = useT();
    const state = lobby.lobby_state;

    if (state === 'cancelled') {
        return <TerminalBadge tone="muted" label={t('Lobby cancelled')} />;
    }

    if (state === 'expired') {
        return <TerminalBadge tone="muted" label={t('Lobby expired')} />;
    }

    if (state === 'ready_checking' && lobby.lobby_ready_check_deadline) {
        return (
            <Countdown
                deadlineIso={lobby.lobby_ready_check_deadline}
                tone="warning"
                subLabel={t('Ready check')}
            />
        );
    }

    if (state === 'locked' && lobby.match_deadline_at) {
        return (
            <Countdown
                deadlineIso={lobby.match_deadline_at}
                tone="success"
                subLabel={t('Time to play your match')}
            />
        );
    }

    if (state === 'recruiting') {
        return (
            <Countdown
                deadlineIso={lobby.expires_at}
                tone="default"
                subLabel={t('Lobby fills within')}
            />
        );
    }

    return null;
}

interface CountdownProps {
    deadlineIso: string;
    tone: 'default' | 'warning' | 'success';
    subLabel: string;
}

function Countdown({ deadlineIso, tone, subLabel }: CountdownProps) {
    const remainingMs = useCountdown(deadlineIso);

    if (remainingMs <= 0) {
        return null;
    }

    const totalSeconds = Math.ceil(remainingMs / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const isFinalStretch = tone === 'warning' && totalSeconds <= 30;

    const display =
        hours > 0
            ? `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
            : `${minutes}:${seconds.toString().padStart(2, '0')}`;

    const toneClass = isFinalStretch
        ? 'animate-pulse text-destructive'
        : tone === 'warning'
          ? 'text-warning'
          : tone === 'success'
            ? 'text-gradient-primary'
            : 'text-foreground';

    return (
        <div className="text-center">
            <div
                className={cn(
                    'font-display text-2xl leading-none font-bold tabular-nums sm:text-3xl',
                    toneClass,
                )}
                aria-live="polite"
            >
                {display}
            </div>
            <p className="mt-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {subLabel}
            </p>
        </div>
    );
}

function TerminalBadge({ tone, label }: { tone: 'muted'; label: string }) {
    return (
        <div
            className={cn(
                'rounded-full border px-4 py-1.5 text-xs font-semibold tracking-wide uppercase',
                tone === 'muted' &&
                    'border-border/60 bg-muted text-muted-foreground',
            )}
        >
            {label}
        </div>
    );
}

function ShareButton() {
    const t = useT();
    const [copiedText, copy] = useClipboard();
    const [url, setUrl] = useState('');

    useEffect(() => {
        setUrl(window.location.href);
    }, []);

    const isCopied = copiedText === url;

    const handleShare = async () => {
        if (typeof navigator !== 'undefined' && 'share' in navigator) {
            try {
                await navigator.share({
                    title: t('Stakly lobby'),
                    url,
                });

                return;
            } catch {
                // user dismissed the share sheet — fall through to copy
            }
        }

        copy(url);
    };

    return (
        <button
            type="button"
            onClick={handleShare}
            aria-label={t('Share lobby link')}
            className="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-full border border-border/60 bg-card/60 text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/10 hover:text-primary"
        >
            {isCopied ? (
                <Check className="size-4 text-success" aria-hidden="true" />
            ) : (
                <Share2 className="size-4" aria-hidden="true" />
            )}
        </button>
    );
}

function useCountdown(deadlineIso: string): number {
    const [remainingMs, setRemainingMs] = useState(() =>
        computeRemaining(deadlineIso),
    );

    useEffect(() => {
        const tick = () => setRemainingMs(computeRemaining(deadlineIso));

        tick();
        const id = window.setInterval(tick, 1000);

        return () => window.clearInterval(id);
    }, [deadlineIso]);

    return remainingMs;
}

function computeRemaining(deadlineIso: string): number {
    return Math.max(0, new Date(deadlineIso).getTime() - Date.now());
}
