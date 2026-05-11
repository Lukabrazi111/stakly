// Frontend game registry. Adding a new game:
//   1) Add a `case` to App\Enums\Game (PHP).
//   2) Add an entry here.
//   3) Add any game-specific filter component + extend `GAME_FILTERS` if it
//      needs filters beyond stake + region + language (which all games share).
//   4) Optionally surface it in `GameSelector` / a games picker UI.
//
// The `available` flag lets us list a game as "Coming soon" before the
// backend is ready. Mirrors the home-page GameSelector pattern.

export type GameId = 'chess';

export interface GameConfig {
    id: GameId;
    name: string;
    available: boolean;
    // Game-specific filters this game supports. Generic filters (stake, region,
    // language, sort) are not listed here — they apply everywhere.
    filters: ReadonlyArray<'time_control' | 'skill_range'>;
}

export const GAMES: readonly GameConfig[] = [
    {
        id: 'chess',
        name: 'Chess',
        available: true,
        filters: ['time_control', 'skill_range'],
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

export function gameSupports(
    id: GameId,
    filter: GameConfig['filters'][number],
): boolean {
    return findGame(id).filters.includes(filter);
}
