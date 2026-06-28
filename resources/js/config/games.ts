// Frontend game registry. Adding a new game:
//   1) Add a `case` to App\Enums\Game (PHP).
//   2) Add an entry here.
//   3) Add any game-specific filter component + extend `GAME_FILTERS` if it
//      needs filters beyond stake + region + language (which all games share).
//   4) Optionally surface it in `GameSelector` / a games picker UI.
//
// The `available` flag lets us list a game as "Coming soon" before the
// backend is ready. Mirrors the home-page GameSelector pattern.

export type GameId = 'chess' | 'cs2' | 'dota2';

export interface GameConfig {
    id: GameId;
    name: string;
    available: boolean;
    // Game-specific filters this game supports. Generic filters (stake, region,
    // language, sort) are not listed here — they apply everywhere.
    filters: ReadonlyArray<'time_control' | 'skill_range'>;
}

// `skill_range` gates the verified-rating filter (M41 P5): chess filters on the
// creator's Elo for the listing's platform+TC, CS2 on FACEIT level (1–10).
// Dota 2 has no rating integration yet (no Steam/OpenDota fetch), so it carries
// NO rating filter — re-add `skill_range` when that adapter ships. CS2's
// Create-listing flow is still seeded-only (no FACEIT Create form yet), but its
// dev-seeded listings carry real FACEIT ratings, so the filter applies.
export const GAMES: readonly GameConfig[] = [
    {
        id: 'chess',
        name: 'Chess',
        available: true,
        filters: ['time_control', 'skill_range'],
    },
    {
        id: 'cs2',
        name: 'CS2',
        available: true,
        filters: ['skill_range'],
    },
    {
        id: 'dota2',
        name: 'Dota 2',
        available: true,
        filters: [],
    },
] as const;

export const DEFAULT_GAME: GameId = 'chess';

export function findGame(id: GameId): GameConfig {
    const game = GAMES.find((g) => g.id === id);

    if (!game) {
        throw new Error(`Unknown game: ${id}`);
    }

    return game;
}

/**
 * Permissive — returns `false` for unknown game slugs instead of throwing.
 * Lets the listings game-tabs (DB-driven catalog) pass any slug the admin
 * has added in `/admin/games`, including coming-soon ones not yet in
 * `GAMES`. The dropdown then just hides game-specific filters for the
 * unknown game, which is the correct behaviour.
 */
export function gameSupports(
    id: string,
    filter: GameConfig['filters'][number],
): boolean {
    const game = GAMES.find((g) => g.id === id);

    return game?.filters.includes(filter) ?? false;
}
