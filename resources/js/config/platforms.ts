import type { GameId } from '@/config/games';
import type { ListingPlatform } from '@/types';

/**
 * Single source of truth for how each verification platform is presented.
 * Consumed by `GameChip`, `VerificationChip`, `VerifiedPlatformChip`, and the
 * listing-detail page so the label / brand colour / profile URL can't drift
 * across surfaces (they did before this was centralised — M43 P3).
 */

export const PLATFORM_LABEL: Record<ListingPlatform, string> = {
    chess_com: 'chess.com',
    lichess: 'Lichess',
    faceit: 'FACEIT',
    steam: 'Steam',
};

/**
 * The platform's calm brand-accent colour, referencing the `--platform-*`
 * tokens in `app.css`. Used to tint the platform NAME only — never as a filled
 * pill background (that was the loud off-palette treatment M43 P2/P3 replaced).
 */
export const PLATFORM_COLOR: Record<ListingPlatform, string> = {
    chess_com: 'var(--platform-chesscom)',
    lichess: 'var(--platform-lichess)',
    faceit: 'var(--platform-faceit)',
    steam: 'var(--platform-steam)',
};

/** External public-profile URL per provider (verified accounts click out here). */
export const PLATFORM_PROFILE_URL: Record<
    ListingPlatform,
    (username: string) => string
> = {
    chess_com: (u) => `https://www.chess.com/member/${u}`,
    lichess: (u) => `https://lichess.org/@/${u}`,
    faceit: (u) => `https://www.faceit.com/en/players/${u}`,
    steam: (u) => `https://steamcommunity.com/id/${u}`,
};

/**
 * Which providers prove skill for each game. Used to filter a creator's linked
 * accounts down to the ones relevant to a listing — a chess listing shouldn't
 * surface the creator's FACEIT/CS2 account, and vice versa.
 */
export const GAME_PLATFORMS: Record<GameId, ListingPlatform[]> = {
    chess: ['chess_com', 'lichess'],
    cs2: ['faceit'],
    dota2: ['steam'],
};
