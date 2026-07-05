import {
    Check,
    Copy,
    Crown,
    Link2,
    Share2,
    Swords,
    Target,
} from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import type { ElementType } from 'react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { leaderLabel, pickLeader } from '@/components/lobby/lobby-leader';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { GameId } from '@/config/games';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as listingShow } from '@/routes/listings';
import { invite as lobbyInvite } from '@/routes/lobbies';
import type { Lobby, LobbyParticipantPayload } from '@/types';

const GAME_META: Record<GameId, { icon: ElementType; label: string }> = {
    chess: { icon: Crown, label: 'Chess' },
    cs2: { icon: Target, label: 'CS2' },
    dota2: { icon: Swords, label: 'Dota 2' },
};

// Team accent — Team A pink (primary), Team B purple (accent). Mirrors the slot
// cards so the two sides read as distinct top-to-bottom (M43 P4).
const TEAM_TONE = {
    a: { ring: 'ring-primary/60', dot: 'bg-primary' },
    b: { ring: 'ring-accent/60', dot: 'bg-accent' },
} as const;

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
                <ShareButton lobby={lobby} />
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
    const tone = align === 'start' ? TEAM_TONE.a : TEAM_TONE.b;

    return (
        <div className={cn('flex min-w-0 items-center gap-3', flexDir)}>
            <Avatar
                className={cn(
                    'size-11 shrink-0 overflow-hidden rounded-full ring-2',
                    tone.ring,
                )}
            >
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
                <span
                    className={cn(
                        'inline-flex items-center gap-1.5 text-xs text-muted-foreground tabular-nums',
                        align === 'end' && 'flex-row-reverse',
                    )}
                >
                    <span
                        className={cn('size-1.5 rounded-full', tone.dot)}
                        aria-hidden="true"
                    />
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

/**
 * Lobby invite/share affordance (M34 P6 — replaces the old `LobbyInviteBanner`).
 * A popover (mirrors `ShareProfileButton`: QR + copyable link + Copy) that hands
 * out the link which actually grants access:
 *   - private + owner → the invite-token link (`/lobbies/{token}`). NOT
 *     `window.location.href` — that's the `/listings/{id}` page, which 404s for
 *     invitees after the M34 P5 invite-only hardening.
 *   - public lobby    → the public listing page.
 *   - private + non-owner → nothing to share (no token; the listing 404s for
 *     outsiders) → renders nothing.
 * Lifecycle-gated to recruiting / ready_checking (the invite endpoint 404s once
 * the lobby locks, and there's no one to recruit after that).
 */
function ShareButton({ lobby }: { lobby: Lobby }) {
    const t = useT();
    const [copied, setCopied] = useState(false);
    const [origin, setOrigin] = useState('');

    useEffect(() => {
        setOrigin(window.location.origin);
    }, []);

    const isLive =
        lobby.lobby_state === 'recruiting' ||
        lobby.lobby_state === 'ready_checking';

    const path =
        lobby.invite_token !== null
            ? lobbyInvite({ token: lobby.invite_token }).url
            : lobby.is_public
              ? listingShow({ listing: lobby.id }).url
              : null;

    if (!isLive || path === null) {
        return null;
    }

    const isInvite = lobby.invite_token !== null;
    const url = `${origin}${path}`;
    const Icon = isInvite ? Link2 : Share2;

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            toast.success(t('Link copied'));
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error(t('Could not copy — try selecting it manually.'));
        }
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="rounded-full"
                >
                    <Icon className="size-4" aria-hidden="true" />
                    {isInvite ? t('Invite') : t('Share')}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 space-y-4" align="end">
                <div>
                    <h3 className="font-display text-sm font-semibold text-foreground">
                        {isInvite
                            ? t('Invite to your lobby')
                            : t('Share this lobby')}
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {isInvite
                            ? t(
                                  'Only people with this link can see your lobby. Share it with teammates to recruit.',
                              )
                            : t('Anyone with this link can view your lobby.')}
                    </p>
                </div>

                {/* Forced-light QR — phone cameras read dark squares better on white. */}
                <div className="flex justify-center">
                    <div className="rounded-xl bg-white p-3 shadow-md">
                        <QRCodeSVG
                            value={url}
                            size={160}
                            level="M"
                            marginSize={0}
                        />
                    </div>
                </div>

                <div className="flex items-center gap-2 rounded-lg border border-border/60 bg-card/60 p-2">
                    <code
                        className="flex-1 truncate text-xs text-foreground select-all"
                        title={url}
                    >
                        {url}
                    </code>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={handleCopy}
                        aria-label={copied ? t('Link copied') : t('Copy link')}
                        className="size-8 shrink-0"
                    >
                        {copied ? (
                            <Check
                                className="size-3.5 text-success"
                                aria-hidden="true"
                            />
                        ) : (
                            <Copy className="size-3.5" aria-hidden="true" />
                        )}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
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
