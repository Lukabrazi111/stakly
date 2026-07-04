import type { GameId } from '@/config/games';
import { useT } from '@/lib/i18n';
import type { ListingPlatform } from '@/types';

const GAME_LABEL: Record<GameId, string> = {
    chess: 'Chess',
    cs2: 'CS2',
    dota2: 'Dota 2',
};

const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
    faceit: 'FACEIT',
    steam: 'Steam',
};

// Brand-accent tokens (app.css) — the platform's calm identity colour in the
// merged chip. Keyed by platform so a listing reads with a coherent colour
// instead of the old loud orange pill.
const PLATFORM_COLOR: Record<ListingPlatform, string> = {
    chess_com: 'var(--platform-chesscom)',
    lichess: 'var(--platform-lichess)',
    faceit: 'var(--platform-faceit)',
    steam: 'var(--platform-steam)',
};

interface Props {
    game: GameId;
    /**
     * Optional team format. When > 1 (e.g. CS2 5v5, Wingman 2v2) the chip
     * becomes a compound "Game · NvN" pill — the team-size segment renders
     * in the accent color so the format reads as a distinct marker, not
     * just metadata. Passing 1 / omitted leaves the chip as just the game.
     */
    teamSize?: number;
    /**
     * Optional verification platform (chess.com / Lichess / FACEIT / Steam).
     * When set, appends a "· Platform" segment tinted with the platform's
     * brand accent so game / format / platform read as one metadata pill —
     * instead of the game chip plus a separate loud brand-coloured chip.
     */
    platform?: ListingPlatform;
}

/**
 * Compact game indicator used on dense list surfaces (My listings, Match
 * history, Marketplace row). Text-only — the game name carries it; no icon.
 */
export function GameChip({ game, teamSize, platform }: Props) {
    const t = useT();
    const label = GAME_LABEL[game];

    if (!label) {
        return null;
    }

    const isTeamPlay = teamSize !== undefined && teamSize > 1;
    const platformLabel = platform ? PLATFORM_LABEL[platform] : null;
    const platformColor = platform ? PLATFORM_COLOR[platform] : undefined;

    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-card/60 px-2.5 py-0.5 text-xs font-medium text-foreground">
            {t(label)}
            {isTeamPlay && (
                <>
                    <span
                        className="text-muted-foreground/60"
                        aria-hidden="true"
                    >
                        ·
                    </span>
                    <span className="font-semibold text-accent">
                        {teamSize}v{teamSize}
                    </span>
                </>
            )}
            {platformLabel && (
                <>
                    <span
                        className="text-muted-foreground/60"
                        aria-hidden="true"
                    >
                        ·
                    </span>
                    <span style={{ color: platformColor }}>
                        {platformLabel}
                    </span>
                </>
            )}
        </span>
    );
}
