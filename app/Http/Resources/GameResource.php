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
     * Two poster sources coexist — seeded `/images/games/chess.png`
     * (absolute web path under `public/`) and Filament FileUpload
     * `games/<hash>.webp` (disk-relative under `storage/app/public/`).
     * Both normalized to root-relative URLs so the React app stays
     * portable across host / scheme changes.
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
