<?php

namespace App\Models;

use App\Enums\Game as GameEnum;
use App\Enums\GameStatus;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Homepage game-tile catalog. Admin-editable display rows shown in the
 * GameSelector. The sibling `App\Enums\Game` enum is the backend identity for
 * integrated games — only rows whose slug matches an enum case can be Active.
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
        // Step by 10 so later drag-reorders have gaps without renumbering.
        static::creating(function (Game $game): void {
            if ($game->position === null) {
                $game->position = (static::max('position') ?? 0) + 10;
            }
        });

        $forget = fn () => Cache::forget(self::HOMEPAGE_CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);

        // Fires per-row on both single and bulk delete, so the poster on the
        // public disk doesn't outlive its row.
        static::deleting(function (Game $game): void {
            if ($game->poster_path !== null) {
                Storage::disk('public')->delete($game->poster_path);
            }
        });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /**
     * Active + ComingSoon, ordered. Disabled rows excluded so admin can park
     * entries without deleting.
     */
    public function scopeForHomepage(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [GameStatus::Active, GameStatus::ComingSoon])
            ->ordered();
    }

    public function hasBackendIntegration(): bool
    {
        return $this->status === GameStatus::Active
            && GameEnum::tryFrom($this->slug)?->hasArbitrationDriver() === true;
    }
}
