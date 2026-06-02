<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'username', 'released_at'])]
class UsernameHistory extends Model
{
    protected $table = 'username_history';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rows still inside the 30-day reservation window — drives the rename
     * validator's "this handle is reserved" gate and the old-URL redirect.
     */
    public function scopeReserved(Builder $query): Builder
    {
        return $query->where('released_at', '>', CarbonImmutable::now());
    }
}
