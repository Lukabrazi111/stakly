<?php

namespace App\Models;

use App\Enums\Game;
use App\Enums\ListingStatus;
use App\Enums\TimeControl;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'game',
        'stake_amount',
        'skill_min',
        'skill_max',
        'time_control',
        'region',
        'language',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'game' => Game::class,
            'stake_amount' => 'decimal:2',
            'skill_min' => 'integer',
            'skill_max' => 'integer',
            'time_control' => TimeControl::class,
            'expires_at' => 'datetime',
            'status' => ListingStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Listings that are open AND not yet past their expiry.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->where('status', ListingStatus::Open)
            ->where('expires_at', '>', now());
    }
}
