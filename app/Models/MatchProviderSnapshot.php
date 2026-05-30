<?php

namespace App\Models;

use App\Enums\LinkedAccountProvider;
use Database\Factories\MatchProviderSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (match, side, provider) — see the migration for the full
 * rationale. Append-only; inserted in `TakeListingAction` inside the
 * match-creation transaction.
 */
class MatchProviderSnapshot extends Model
{
    /** @use HasFactory<MatchProviderSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'match_id',
        'side',
        'provider',
        'username',
    ];

    protected function casts(): array
    {
        return [
            'provider' => LinkedAccountProvider::class,
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
