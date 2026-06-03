<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'admin_user_id',
    'action',
    'reason',
])]
class UserModerationLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_BAN = 'ban';

    public const ACTION_UNBAN = 'unban';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
