<?php

namespace App\Models;

use App\Enums\Game as GameEnum;
use App\Enums\GameStatus;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Homepage game-tile catalog (M24). Admin-editable display rows shown in
 * the GameSelector. The sibling `App\Enums\Game` enum is the backend identity
 * for games with active integration — only games whose slug matches an enum
 * case can be set to `GameStatus::Active`.
 */
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    public const HOMEPAGE_CACHE_KEY = 'homepage:games';

    protected $fillable = [
        'slug',
        'display_name',
        'poster_path',
        'position',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Auto-append new games to the end of the row when position isn't
        // explicitly set. The admin form doesn't expose a position field —
        // admin reorders via Filament's drag-to-reorder in the table view.
        // Step by 10 so manual reorders later have gaps without renumbering.
        static::creating(function (Game $game): void {
            if ($game->position === null) {
                $game->position = (static::max('position') ?? 0) + 10;
            }
        });

        $forget = fn () => Cache::forget(self::HOMEPAGE_CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /**
     * Tiles shown on the public homepage — Active + ComingSoon, ordered.
     * Disabled rows are excluded so admin can park entries without deleting.
     */
    public function scopeForHomepage(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [GameStatus::Active, GameStatus::ComingSoon])
            ->ordered();
    }

    /**
     * True when this row points at a backend-integrated game (slug matches
     * an `App\Enums\Game` case) and is marked Active. False for display-only
     * tiles (ComingSoon for games not yet wired up).
     */
    public function hasBackendIntegration(): bool
    {
        return $this->status === GameStatus::Active
            && GameEnum::tryFrom($this->slug) !== null;
    }
}
