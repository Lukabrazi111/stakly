<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a homepage game tile (M24). Whitelist — no internal
 * fields (timestamps, id-as-foreign-key targets) leak to the frontend.
 *
 * @mixin Game
 */
class GameResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'display_name' => $this->display_name,
            'poster_path' => $this->resolvePosterUrl(),
            'status' => $this->status->value,
        ];
    }

    /**
     * Two poster sources coexist:
     *  - Seeded paths like `/images/games/chess.png` (under `public/`),
     *    stored as absolute web paths starting with `/`.
     *  - Filament FileUpload paths like `games/<hash>.webp` (under
     *    `storage/app/public/`), stored as disk-relative paths.
     *
     * Both get normalized to a root-relative URL the frontend hands
     * directly to `<img src>`. Root-relative (not absolute) so the React
     * app stays portable across host / scheme changes.
     */
    private function resolvePosterUrl(): ?string
    {
        if (! $this->poster_path) {
            return null;
        }

        if (str_starts_with($this->poster_path, '/')) {
            return $this->poster_path;
        }

        return '/storage/'.ltrim($this->poster_path, '/');
    }
}
