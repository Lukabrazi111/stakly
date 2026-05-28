// Frontend contract for the homepage. Backend source of truth:
// - App\Http\Resources\GameResource (data shape)
// - App\Models\Game (DB-backed catalog, M24 Phase 1)

export type GameTileStatus = 'active' | 'coming_soon' | 'disabled';

/**
 * One row in the homepage `GameSelector`. Backend-served via
 * `HomeController::index` → `GameResource::collection`.
 *
 * `poster_path` is a public URL (e.g. `/images/games/chess.png` for the
 * pre-Filament seed, or `/storage/games/<hash>` for admin uploads). Tiles
 * without a poster fall back to a default gradient + display_name overlay
 * in the GameSelector — no per-row tint override.
 */
export interface GameTile {
    slug: string;
    display_name: string;
    poster_path: string | null;
    status: GameTileStatus;
}
