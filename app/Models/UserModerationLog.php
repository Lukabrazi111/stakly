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

    /**
     * Money-level freeze (M9 Phase 0b) — distinct from a ban. A ban is
     * product access; a freeze blocks debits (withdraw + stake) while letting
     * credits land so in-flight matches can still settle.
     */
    public const ACTION_FREEZE = 'freeze';

    public const ACTION_UNFREEZE = 'unfreeze';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
