<?php

namespace App\Models;

use App\Enums\TimeControl;
use Database\Factories\LinkedAccountRatingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chess time-control rating for a linked account (M41 P3b). See the
 * create migration for the row-exists-⟺-rated invariant and the
 * never-delete-on-refresh policy.
 */
class LinkedAccountRating extends Model
{
    /** @use HasFactory<LinkedAccountRatingFactory> */
    use HasFactory;

    protected $fillable = [
        'linked_account_id',
        'time_control',
        'rating',
        'rd',
        'is_provisional',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'time_control' => TimeControl::class,
            'rating' => 'integer',
            'rd' => 'integer',
            'is_provisional' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function linkedAccount(): BelongsTo
    {
        return $this->belongsTo(LinkedAccount::class);
    }
}
