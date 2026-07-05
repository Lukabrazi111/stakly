import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, Users } from 'lucide-react';
import { GameChip } from '@/components/listings/game-chip';
import { useT } from '@/lib/i18n';
import { formatMatchDate } from '@/lib/matches-format';
import { show as showListing } from '@/routes/listings';
import type { Match } from '@/types';

interface Props {
    match: Match;
}

/**
 * Recruiting-lobby row on `/matches` (M44). A team-play lobby you're a live
 * participant in shows here while it's still filling (`status ===
 * 'lobby_filling'`) so you can always get back to it — the gap this closes is
 * a joiner who navigated away with no anchor. No opponent yet: the left zone
 * identifies the lobby + host, the middle shows fill progress, and the whole
 * row returns you to the lobby.
 */
export function RecruitingLobbyRow({ match }: Props) {
    const t = useT();
    const { auth } = usePage().props;

    const teamSize = match.listing.team_size;
    const target = teamSize * 2;
    const fill = match.listing.live_participant_count ?? 0;
    const isReadyCheck = match.listing.lobby_state === 'ready_checking';
    const host = match.creator;
    const isHost = host.id === auth.user?.id;
    const lobbyHref = showListing({ listing: match.listing.id }).url;

    return (
        <article className="group relative flex flex-col gap-4 border-t border-border/40 px-4 py-4 transition-colors duration-200 ease-out first:border-t-0 hover:bg-primary/5 md:flex-row md:items-center md:gap-6 md:px-5">
            <Link
                href={lobbyHref}
                aria-label={t('Return to the :size v :size lobby', {
                    size: teamSize,
                })}
                className="absolute inset-0 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            />

            <div className="relative flex min-w-0 items-center gap-3 md:w-52 md:shrink-0">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary">
                    <Users className="size-5" aria-hidden="true" />
                </div>
                <div className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-semibold text-foreground">
                        {t(':size v :size lobby', { size: teamSize })}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {isHost
                            ? t('Your lobby')
                            : t('Hosted by :name', { name: host.name })}
                    </span>
                </div>
            </div>

            <div className="pointer-events-none relative flex flex-1 flex-wrap items-center gap-3 md:flex-nowrap md:gap-6">
                <div className="flex flex-wrap items-center gap-2 md:flex-1">
                    <GameChip
                        game={match.listing.game}
                        teamSize={teamSize}
                        platform={match.listing.platform}
                    />

                    <span
                        className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium ${
                            isReadyCheck
                                ? 'border-warning/40 bg-warning/10 text-warning'
                                : 'border-primary/40 bg-primary/10 text-primary'
                        }`}
                    >
                        {isReadyCheck ? t('Ready check') : t('Recruiting')}
                    </span>

                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground tabular-nums">
                        <Users className="size-3" aria-hidden="true" />
                        {fill}/{target}
                    </span>

                    <span className="inline-flex items-center gap-1 text-xs font-medium text-primary">
                        {t('Return')}
                        <ArrowRight className="size-3" aria-hidden="true" />
                    </span>
                </div>

                <div className="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-muted-foreground md:w-20 md:justify-end">
                    {match.created_at && formatMatchDate(match.created_at, t)}
                </div>

                <div className="flex items-baseline gap-1 md:w-28 md:shrink-0 md:justify-end">
                    <span className="text-gradient-primary font-display text-xl leading-none font-bold md:text-2xl">
                        ${match.listing.stake_amount}
                    </span>
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>
            </div>
        </article>
    );
}
