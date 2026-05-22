<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'username',
    'bio',
    'email',
    'password',
    'tron_address',
    'is_active_mode',
    'chess_com_username',
    'chess_com_verified_at',
    'lichess_username',
    'lichess_verified_at',
    'pending_verification_provider',
    'pending_verification_username',
    'pending_verification_code',
    'pending_verification_expires_at',
])]
#[Hidden([
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
    'pending_verification_code',
])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Route model binding uses `username` instead of `id`, so `/users/{user}`
     * resolves via the public handle. Username is derived at registration and
     * immutable in v1.
     */
    public function getRouteKeyName(): string
    {
        return 'username';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'usdt_balance' => 'decimal:6',
            'is_platform' => 'boolean',
            'is_active_mode' => 'boolean',
            'chess_com_verified_at' => 'datetime',
            'lichess_verified_at' => 'datetime',
            'pending_verification_expires_at' => 'datetime',
        ];
    }

    /**
     * Append-only ledger entries belonging to this user. Invariant:
     * `SUM(wallet_transactions.amount) == users.usdt_balance` always.
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * Matches where this user is the taker. The creator side is reached via
     * `$user->listings->map->gameMatch` — combined "all my matches" queries
     * use a scope on `GameMatch` (Phase 6) rather than a model relation.
     */
    public function gameMatchesAsTaker(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'taker_user_id');
    }

    /**
     * Has the user verified at least one chess provider account? Gates both
     * sides of marketplace participation (M8 Phase 5 take-gate +
     * create-gate). Permissive — one link unlocks both create and take —
     * because today every listing is chess and any verified chess link is
     * sufficient to support evidence resolution. Phase 5's
     * `listings.platform` column tightens this to "verified on the
     * listing's specific platform."
     */
    public function hasVerifiedChessLink(): bool
    {
        return $this->lichess_verified_at !== null
            || $this->chess_com_verified_at !== null;
    }
}
